<?php

namespace App\Services;

use App\Models\ServerSample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * CPU, memory, load, disk, and network of this server, read from /proc, plus
 * service status and the busiest processes. One sample per minute is stored for
 * the Server monitoring charts. Everything degrades to null where /proc is missing.
 */
class ServerStats
{
    private const STATE_TTL = 600;

    /**
     * Interfaces that are not the server's real network traffic.
     */
    private const VIRTUAL_INTERFACES = '/^(lo|docker\d*|veth.*|br-.*|virbr.*|cni.*|flannel.*|cali.*)$/';

    public function __construct(private CommandRunner $runner) {}

    /**
     * Cumulative CPU time from the first line of /proc/stat.
     *
     * @return array{total: int, idle: int}|null
     */
    public static function parseCpu(string $stat): ?array
    {
        if (preg_match('/^cpu\s+(.+)$/m', $stat, $match) !== 1) {
            return null;
        }

        $fields = array_map('intval', preg_split('/\s+/', trim($match[1])) ?: []);

        if (count($fields) < 4) {
            return null;
        }

        // user nice system idle iowait irq softirq steal (guest time is already inside user).
        return ['total' => array_sum(array_slice($fields, 0, 8)), 'idle' => $fields[3] + ($fields[4] ?? 0)];
    }

    /**
     * @return array{total: int, used: int, available: int, percent: float, swap_total: int, swap_used: int, swap_percent: float}|null
     */
    public static function parseMemory(string $meminfo): ?array
    {
        $values = [];

        foreach (preg_split('/\R/', $meminfo) ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $match) === 1) {
                $values[$match[1]] = (int) $match[2] * 1024;
            }
        }

        $total = $values['MemTotal'] ?? 0;

        if ($total === 0) {
            return null;
        }

        $available = $values['MemAvailable'] ?? (($values['MemFree'] ?? 0) + ($values['Buffers'] ?? 0) + ($values['Cached'] ?? 0));
        $swapTotal = $values['SwapTotal'] ?? 0;
        $swapUsed = max(0, $swapTotal - ($values['SwapFree'] ?? 0));

        return [
            'total' => $total,
            'used' => $total - $available,
            'available' => $available,
            'percent' => round(($total - $available) / $total * 100, 2),
            'swap_total' => $swapTotal,
            'swap_used' => $swapUsed,
            'swap_percent' => $swapTotal > 0 ? round($swapUsed / $swapTotal * 100, 2) : 0.0,
        ];
    }

    /**
     * @return array{float, float, float}|null
     */
    public static function parseLoad(string $loadavg): ?array
    {
        $parts = preg_split('/\s+/', trim($loadavg)) ?: [];

        return count($parts) >= 3 && is_numeric($parts[0]) ? [(float) $parts[0], (float) $parts[1], (float) $parts[2]] : null;
    }

    public static function parseUptime(string $uptime): ?int
    {
        $first = explode(' ', trim($uptime))[0] ?? '';

        return is_numeric($first) ? (int) floor((float) $first) : null;
    }

    public static function parseCpuCount(string $cpuinfo): int
    {
        return max(1, preg_match_all('/^processor\s*:/m', $cpuinfo));
    }

    /**
     * Bytes received and sent since boot, over the real interfaces.
     *
     * @return array{in: int, out: int}|null
     */
    public static function parseNetwork(string $netDev): ?array
    {
        $in = $out = 0;
        $found = false;

        foreach (preg_split('/\R/', $netDev) ?: [] as $line) {
            if (preg_match('/^\s*([^:\s]+):\s*(.+)$/', $line, $match) !== 1 || preg_match(self::VIRTUAL_INTERFACES, $match[1]) === 1) {
                continue;
            }

            $fields = preg_split('/\s+/', trim($match[2])) ?: [];

            if (count($fields) >= 9) {
                $in += (int) $fields[0];
                $out += (int) $fields[8];
                $found = true;
            }
        }

        return $found ? ['in' => $in, 'out' => $out] : null;
    }

    /**
     * Systemd properties of one unit: "LoadState=loaded\nActiveState=active".
     *
     * @return array{loaded: bool, state: string}
     */
    public static function parseUnit(string $output): array
    {
        $properties = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $properties[trim($key)] = trim($value);
            }
        }

        return [
            'loaded' => ($properties['LoadState'] ?? 'not-found') !== 'not-found',
            'state' => ($properties['ActiveState'] ?? 'unknown') === 'active' ? 'running' : ($properties['ActiveState'] ?? 'unknown'),
        ];
    }

    /**
     * Several units from one `systemctl show`, in the order they were asked.
     * A blank line separates each unit.
     *
     * @return list<array{loaded: bool, state: string}>
     */
    public static function parseUnits(string $output, int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $blocks = trim($output) === '' ? [] : (preg_split("/\R\s*\R/", trim($output)) ?: []);

        return array_map(
            fn (int $index): array => self::parseUnit($blocks[$index] ?? ''),
            range(0, $count - 1),
        );
    }

    /**
     * `ps -eo pid,user,pcpu,pmem,comm` rows.
     *
     * @return list<array{pid: int, user: string, cpu: float, memory: float, name: string}>
     */
    public static function parseProcesses(string $output): array
    {
        $rows = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+(\S+)\s+([\d.]+)\s+([\d.]+)\s+(.+)$/', $line, $match) === 1) {
                $rows[] = ['pid' => (int) $match[1], 'user' => $match[2], 'cpu' => (float) $match[3], 'memory' => (float) $match[4], 'name' => trim($match[5])];
            }
        }

        return $rows;
    }

    /**
     * What the server looks like right now.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $memory = self::parseMemory($this->read('/proc/meminfo'));
        $load = self::parseLoad($this->read('/proc/loadavg'));
        $cores = self::parseCpuCount($this->read('/proc/cpuinfo'));
        $disk = $this->disk();
        $latest = ServerSample::query()->latest('at')->first();

        return [
            'available' => $memory !== null || $load !== null,
            'hostname' => gethostname() ?: null,
            'os' => $this->osName(),
            'kernel' => php_uname('r'),
            'uptime' => self::parseUptime($this->read('/proc/uptime')),
            'cores' => $cores,
            'load' => $load,
            'memory' => $memory,
            'disk' => $disk,
            'cpu' => $latest !== null && $latest->at->gt(now()->subMinutes(3)) ? $latest->cpu : null,
            'net_in' => $latest?->net_in,
            'net_out' => $latest?->net_out,
            'sampled_at' => $latest?->at,
        ];
    }

    /**
     * A live reading for the Server monitoring page. CPU and network are measured
     * over a quarter of a second, so every call is a fresh, real value.
     *
     * @return array<string, mixed>
     */
    public function live(bool $withProcesses = false): array
    {
        $cpuBefore = self::parseCpu($this->read('/proc/stat'));
        $netBefore = self::parseNetwork($this->read('/proc/net/dev'));
        $memory = self::parseMemory($this->read('/proc/meminfo'));

        if ($cpuBefore === null && $memory === null) {
            return ['available' => false];
        }

        $started = microtime(true);
        usleep(250000);
        $cpuAfter = self::parseCpu($this->read('/proc/stat'));
        $netAfter = self::parseNetwork($this->read('/proc/net/dev'));
        $seconds = max(0.001, microtime(true) - $started);

        $cpu = null;

        if ($cpuBefore !== null && $cpuAfter !== null && $cpuAfter['total'] > $cpuBefore['total']) {
            $cpu = round(max(0, min(100, 100 * (1 - ($cpuAfter['idle'] - $cpuBefore['idle']) / ($cpuAfter['total'] - $cpuBefore['total'])))), 1);
        }

        $network = $netBefore !== null && $netAfter !== null
            ? ['in' => (int) round(max(0, $netAfter['in'] - $netBefore['in']) / $seconds), 'out' => (int) round(max(0, $netAfter['out'] - $netBefore['out']) / $seconds)]
            : null;

        return [
            'available' => true,
            't' => time(),
            'cpu' => $cpu,
            'memory' => $memory['percent'] ?? null,
            'memory_used' => $memory['used'] ?? null,
            'memory_total' => $memory['total'] ?? null,
            'swap' => $memory['swap_percent'] ?? null,
            'load' => self::parseLoad($this->read('/proc/loadavg')),
            'disk' => $this->disk()['percent'] ?? null,
            'net_in' => $network['in'] ?? null,
            'net_out' => $network['out'] ?? null,
            'uptime' => self::parseUptime($this->read('/proc/uptime')),
            'processes' => $withProcesses ? $this->topProcesses() : null,
        ];
    }

    /**
     * Store one sample. CPU and network are rates, so they need the previous reading:
     * the first run after a restart stores them empty.
     */
    public function sample(): ?ServerSample
    {
        $cpu = self::parseCpu($this->read('/proc/stat'));
        $memory = self::parseMemory($this->read('/proc/meminfo'));
        $load = self::parseLoad($this->read('/proc/loadavg'));
        $network = self::parseNetwork($this->read('/proc/net/dev'));

        if ($cpu === null && $memory === null && $load === null) {
            return null;
        }

        $now = microtime(true);
        $previous = Cache::get('server:stats');
        Cache::put('server:stats', ['at' => $now, 'cpu' => $cpu, 'network' => $network], self::STATE_TTL);

        $cpuPercent = null;
        $in = $out = null;

        if (is_array($previous) && $now - $previous['at'] >= 1 && $now - $previous['at'] <= self::STATE_TTL) {
            if ($cpu !== null && ($previous['cpu'] ?? null) !== null && $cpu['total'] > $previous['cpu']['total']) {
                $cpuPercent = round(100 * (1 - ($cpu['idle'] - $previous['cpu']['idle']) / ($cpu['total'] - $previous['cpu']['total'])), 2);
                $cpuPercent = max(0, min(100, $cpuPercent));
            }

            if ($network !== null && ($previous['network'] ?? null) !== null && $network['in'] >= $previous['network']['in'] && $network['out'] >= $previous['network']['out']) {
                $seconds = $now - $previous['at'];
                $in = (int) round(($network['in'] - $previous['network']['in']) / $seconds);
                $out = (int) round(($network['out'] - $previous['network']['out']) / $seconds);
            }
        }

        return ServerSample::query()->create([
            'at' => now(),
            'cpu' => $cpuPercent,
            'memory' => $memory['percent'] ?? null,
            'swap' => $memory['swap_percent'] ?? null,
            'load1' => $load[0] ?? null,
            'disk' => $this->disk()['percent'] ?? null,
            'net_in' => $in,
            'net_out' => $out,
        ]);
    }

    /**
     * Averages per chart bar for a range (same ranges as the website pages).
     *
     * @return array{buckets: list<array<string, mixed>>, summary: array<string, float|int|null>}
     */
    public function history(string $range): array
    {
        $range = SiteMetrics::range($range);
        [, $minutes, $perBucket] = SiteMetrics::RANGES[$range];
        $seconds = $perBucket * 60;
        $count = intdiv($minutes, $perBucket);
        $last = intdiv(now()->getTimestamp(), $seconds) * $seconds;
        $first = $last - ($count - 1) * $seconds;

        $samples = ServerSample::query()
            ->where('at', '>=', Carbon::createFromTimestamp($first, config('app.timezone')))
            ->orderBy('at')
            ->get();

        $fields = ['cpu', 'memory', 'swap', 'load1', 'disk', 'net_in', 'net_out'];
        $sums = array_fill(0, $count, array_fill_keys($fields, ['sum' => 0.0, 'n' => 0]));

        foreach ($samples as $sample) {
            $index = intdiv($sample->at->getTimestamp() - $first, $seconds);

            if (! isset($sums[$index])) {
                continue;
            }

            foreach ($fields as $field) {
                if ($sample->{$field} !== null) {
                    $sums[$index][$field]['sum'] += $sample->{$field};
                    $sums[$index][$field]['n']++;
                }
            }
        }

        $buckets = [];

        foreach ($sums as $index => $bucket) {
            $row = ['label' => app(DisplayTime::class)->label($first + $index * $seconds, $range !== '1h')];

            foreach ($fields as $field) {
                $row[$field] = $bucket[$field]['n'] > 0 ? round($bucket[$field]['sum'] / $bucket[$field]['n'], 2) : null;
            }

            $buckets[] = $row;
        }

        $average = fn (string $field): ?float => $samples->whereNotNull($field)->isNotEmpty() ? round((float) $samples->whereNotNull($field)->avg($field), 1) : null;

        return [
            'buckets' => $buckets,
            'summary' => [
                'cpu_avg' => $average('cpu'),
                'cpu_max' => $samples->whereNotNull('cpu')->isNotEmpty() ? (float) $samples->max('cpu') : null,
                'memory_avg' => $average('memory'),
                'memory_max' => $samples->whereNotNull('memory')->isNotEmpty() ? (float) $samples->max('memory') : null,
                'load_max' => $samples->whereNotNull('load1')->isNotEmpty() ? (float) $samples->max('load1') : null,
                'samples' => $samples->count(),
            ],
        ];
    }

    /**
     * Status of the services a Laravelify server runs. Units that are not installed are left out.
     *
     * @return list<array{name: string, unit: string, state: string}>
     */
    public function services(): array
    {
        return Cache::remember('server:services', 120, function (): array {
            $units = ['nginx' => 'nginx', 'MySQL' => 'mysql', 'Redis' => 'redis-server', 'Supervisor' => 'supervisor', 'Cloudflare tunnel' => 'cloudflared'];

            foreach (config('laravelify.php_versions', []) as $version) {
                $units['PHP '.$version.' FPM'] = 'php'.$version.'-fpm';
            }

            // One systemctl for every unit. A separate call per unit waits out the
            // command runner's poll and made this page take several seconds.
            $result = $this->runner->run(['systemctl', 'show', ...array_values($units), '--property=LoadState', '--property=ActiveState', '--no-pager']);

            if ($result->skipped || ! $result->ok) {
                return [];
            }

            $services = [];

            foreach (self::parseUnits($result->output, count($units)) as $index => $parsed) {
                if (! $parsed['loaded']) {
                    continue;
                }

                $name = array_keys($units)[$index];
                $services[] = ['name' => $name, 'unit' => array_values($units)[$index], 'state' => $parsed['state']];
            }

            return $services;
        });
    }

    /**
     * @return list<array{pid: int, user: string, cpu: float, memory: float, name: string}>
     */
    public function topProcesses(int $limit = 8): array
    {
        return Cache::remember('server:processes', 4, function () use ($limit): array {
            $result = $this->runner->run(['ps', '-eo', 'pid,user:20,pcpu,pmem,comm', '--sort=-pcpu']);

            return $result->skipped || ! $result->ok ? [] : array_slice(self::parseProcesses($result->output), 0, $limit);
        });
    }

    /**
     * @return array{total: float, used: float, free: float, percent: float}|null
     */
    private function disk(string $path = '/'): ?array
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if (! $total || $free === false) {
            return null;
        }

        return ['total' => (float) $total, 'used' => (float) ($total - $free), 'free' => (float) $free, 'percent' => round(($total - $free) / $total * 100, 2)];
    }

    private function osName(): ?string
    {
        $release = $this->read('/etc/os-release');

        return preg_match('/^PRETTY_NAME="?([^"\r\n]+)"?/m', $release, $match) === 1 ? $match[1] : null;
    }

    private function read(string $path): string
    {
        return is_readable($path) ? (string) @file_get_contents($path) : '';
    }
}
