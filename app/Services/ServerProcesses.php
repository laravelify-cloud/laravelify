<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Support\Str;

class ServerProcesses
{
    public function __construct(private CommandRunner $runner) {}

    /**
     * Services every Laravelify server runs, by port.
     *
     * @var array<int, array{string, string}>
     */
    private const SYSTEM_PORTS = [
        22 => ['SSH', 'Remote login'],
        80 => ['nginx', 'Every site with a domain (Cloudflare tunnels connect here)'],
        443 => ['nginx', 'HTTPS'],
        3306 => ['MySQL', 'Panel and site databases'],
        33060 => ['MySQL', 'MySQL X protocol'],
        6379 => ['Redis', 'Cache, sessions, and queues'],
    ];

    /**
     * Every port that is reserved by a site or a service, or listening right now,
     * with who uses it, plus the next free PM2 port.
     *
     * @return array{rows: list<array{port: int, owner: string, kind: string, detail: string, listening: bool, public: bool, processes: list<string>, website: ?string, url: ?string}>, next_free: int, checked: bool}
     */
    public function portMap(): array
    {
        $rows = [];

        foreach (self::SYSTEM_PORTS as $port => [$owner, $detail]) {
            $rows[$port] = ['port' => $port, 'owner' => $owner, 'kind' => 'service', 'detail' => $detail, 'website' => null, 'url' => null];
        }

        foreach (Website::query()->orderBy('name')->get() as $website) {
            $url = route('websites.show', ['website' => $website, 'tab' => 'runtime']);
            $reserved = array_filter([
                $website->port ? [$website->port, 'PM2 port'.($website->domain ? ' · '.$website->domain : '')] : null,
                $website->http_port ? [$website->http_port, 'HTTP port'.($website->domain ? ' · '.$website->domain : '')] : null,
                $website->reverb_port ? [$website->reverb_port, 'Laravel Reverb'] : null,
            ]);

            foreach ($reserved as [$port, $detail]) {
                $rows[(int) $port] = ['port' => (int) $port, 'owner' => $website->name, 'kind' => 'site', 'detail' => $detail, 'website' => $website->slug, 'url' => $url];
            }
        }

        $listening = $this->listening();

        foreach ($listening as $port => $socket) {
            $rows[$port] ??= ['port' => $port, 'owner' => $socket['processes'][0] ?? 'Unknown process', 'kind' => 'other', 'detail' => 'Listening, not managed by Laravelify', 'website' => null, 'url' => null];
        }

        ksort($rows);

        $rows = array_map(fn (array $row): array => $row + [
            'listening' => isset($listening[$row['port']]),
            'public' => (bool) ($listening[$row['port']]['public'] ?? false),
            'processes' => $listening[$row['port']]['processes'] ?? [],
        ], array_values($rows));

        return [
            'rows' => $rows,
            'next_free' => $this->nextFreePort(array_column($rows, 'port'), (int) config('laravelify.node_port_start', 3000)),
            'checked' => $listening !== [] || $this->runner->supportsHostCommands(),
        ];
    }

    /**
     * @return list<array{port: int, owner: string, note: string}>
     */
    public function reservedPorts(): array
    {
        return array_map(fn (array $row): array => [
            'port' => $row['port'],
            'owner' => $row['owner'],
            'note' => $row['detail'],
        ], $this->portMap()['rows']);
    }

    /**
     * Parse `ss -H -lntp`: port => whether it is reachable from outside, and which processes listen.
     *
     * @return array<int, array{public: bool, processes: list<string>}>
     */
    public static function parseListening(string $output): array
    {
        $ports = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            // State Recv-Q Send-Q Local:Port Peer:Port [Process]; the state column is optional.
            $local = null;

            foreach ($fields as $field) {
                if (preg_match('/^(.*):(\d+)$/', $field, $match) === 1) {
                    $local = $match;

                    break;
                }
            }

            if ($local === null) {
                continue;
            }

            $port = (int) $local[2];
            $address = trim($local[1], '[]');
            preg_match_all('/\("([^"]+)",pid=/', $line, $names);

            $ports[$port] ??= ['public' => false, 'processes' => []];
            $ports[$port]['public'] = $ports[$port]['public'] || ! in_array($address, ['127.0.0.1', '::1', 'localhost'], true) && ! str_starts_with($address, '127.');
            $ports[$port]['processes'] = array_values(array_unique([...$ports[$port]['processes'], ...$names[1]]));
        }

        ksort($ports);

        return $ports;
    }

    /**
     * Listening TCP sockets. Process names need root, so this goes through host-apply.sh.
     *
     * @return array<int, array{public: bool, processes: list<string>}>
     */
    private function listening(): array
    {
        if (! $this->runner->supportsHostCommands()) {
            return [];
        }

        $result = function_exists('posix_geteuid') && posix_geteuid() === 0
            ? $this->runner->run(['ss', '-H', '-lntp'])
            : $this->runner->run(['sudo', '-n', base_path('scripts/host-apply.sh'), 'ports', 'all']);

        if (! $result->ok) {
            // Without sudo there are no process names, but the ports are still useful.
            $result = $this->runner->run(['ss', '-H', '-lnt']);
        }

        return $result->ok ? self::parseListening($result->output) : [];
    }

    /**
     * @param  list<int>  $used
     */
    private function nextFreePort(array $used, int $start): int
    {
        $port = max(1024, $start);

        while (in_array($port, $used, true)) {
            $port++;
        }

        return $port;
    }

    /**
     * @return list<array{manager: string, name: string, pid: string, status: string, detail: string}>
     */
    public function list(): array
    {
        return array_merge($this->pm2(), $this->supervisor());
    }

    public function restart(string $manager, string $name): string
    {
        $this->assertName($name);

        $result = $manager === 'pm2'
            ? $this->manage('pm2', 'restart', $name)
            : $this->manage('supervisor', 'restart', $name);

        return trim($result->output) !== '' ? $result->output : $name.' restarted.';
    }

    public function delete(string $manager, string $name): string
    {
        $this->assertName($name);

        $result = $manager === 'pm2'
            ? $this->manage('pm2', 'delete', $name)
            : $this->manage('supervisor', 'stop', $name);

        return trim($result->output) !== '' ? $result->output : $name.' removed.';
    }

    public function logs(string $manager, string $name): string
    {
        $this->assertName($name);

        if ($manager === 'pm2') {
            $result = $this->manage('pm2', 'logs', $name);

            return trim($result->output) !== '' ? $result->output : 'No PM2 logs yet.';
        }

        $result = $this->manage('supervisor', 'tail', $name);

        return trim($result->output) !== '' ? $result->output : 'No Supervisor logs yet.';
    }

    /**
     * Run a Supervisor or PM2 command. Both need root: supervisorctl to reach the
     * Supervisor socket, and pm2 because site apps live under root's PM2 daemon.
     * A non-root panel goes through the sudo-allowed host-apply.sh.
     */
    private function manage(string $manager, string $command, ?string $name = null): CommandResult
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $direct = match ([$manager, $command]) {
                ['supervisor', 'status'] => ['supervisorctl', 'status'],
                ['supervisor', 'tail'] => ['supervisorctl', 'tail', '-8000', (string) $name],
                ['pm2', 'jlist'] => ['pm2', 'jlist'],
                ['pm2', 'restart'] => ['pm2', 'restart', (string) $name, '--update-env'],
                ['pm2', 'logs'] => ['pm2', 'logs', (string) $name, '--nostream', '--lines', '400', '--raw'],
                default => [$manager === 'supervisor' ? 'supervisorctl' : 'pm2', $command, (string) $name],
            };

            return $this->runner->run($direct);
        }

        return $this->runner->run(['sudo', '-n', base_path('scripts/host-apply.sh'), $manager, $command, $name ?? 'all']);
    }

    public function env(string $name): string
    {
        $this->assertName($name);
        $slug = Str::beforeLast($name, '-queue');
        $slug = Str::beforeLast($slug, '-scheduler');
        $slug = Str::beforeLast($slug, '-reverb');

        $website = Website::query()->where('slug', $slug)->orWhere('slug', $name)->first();

        if ($website && is_file($website->deploy_path.'/.env')) {
            return (string) file_get_contents($website->deploy_path.'/.env');
        }

        if ($website && $website->env_content) {
            return $website->env_content;
        }

        $path = '/var/www/'.$name.'/.env';

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }

        return 'No .env file for this process yet. It is written when the site is created or deployed.';
    }

    /**
     * @return list<array{manager: string, name: string, pid: string, status: string, detail: string}>
     */
    private function pm2(): array
    {
        if (! $this->runner->supportsHostCommands()) {
            return [];
        }

        $result = $this->manage('pm2', 'jlist');
        $start = strpos($result->output, '[');
        $decoded = $start === false ? null : json_decode(substr($result->output, $start), true);

        if (! is_array($decoded)) {
            return [];
        }

        $rows = [];

        foreach ($decoded as $process) {
            if (! is_array($process)) {
                continue;
            }

            $memory = (int) ($process['monit']['memory'] ?? 0);

            $rows[] = [
                'manager' => 'pm2',
                'name' => (string) ($process['name'] ?? ''),
                'pid' => (string) ($process['pid'] ?? ''),
                'status' => (string) ($process['pm2_env']['status'] ?? 'unknown'),
                'detail' => trim(($process['monit']['cpu'] ?? '0').'% cpu, '.number_format($memory / 1048576, 1).' MB'),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{manager: string, name: string, pid: string, status: string, detail: string}>
     */
    private function supervisor(): array
    {
        if (! $this->runner->supportsHostCommands()) {
            return [];
        }

        $result = $this->manage('supervisor', 'status');
        $rows = [];

        foreach (preg_split('/\R/', $result->output) ?: [] as $line) {
            if (preg_match('/^(\S+)\s+(\S+)\s+(.*)$/', trim($line), $match) !== 1) {
                continue;
            }

            $pid = '';

            if (preg_match('/pid\s+(\d+)/', $match[3], $pidMatch) === 1) {
                $pid = $pidMatch[1];
            }

            $rows[] = [
                'manager' => 'supervisor',
                'name' => $match[1],
                'pid' => $pid,
                'status' => strtolower($match[2]),
                'detail' => trim($match[3]),
            ];
        }

        return $rows;
    }

    private function assertName(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9_.:-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid process name.');
        }
    }
}
