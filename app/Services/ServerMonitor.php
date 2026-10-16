<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\UptimeCheck;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Background checks that feed the Telegram alerts (Coolify-style): websites
 * going down and coming back, workers crashing and recovering, disk usage,
 * and available server patches. A problem must show up on two checks in a
 * row before it alerts, so a deploy restart or a short blip stays quiet.
 */
class ServerMonitor
{
    private const STATE_TTL = 60 * 60 * 24 * 30;

    public function __construct(
        private Telegram $telegram,
        private ServerProcesses $processes,
        private SiteLogs $logs,
        private CommandRunner $runner,
        private HostWriter $hostWriter,
    ) {}

    /**
     * @return list<string> What happened, for the command output.
     */
    public function run(bool $patchesOnly = false): array
    {
        // The daily patch run is separate, so it never doubles the website and process counters.
        return $patchesOnly
            ? $this->checkPatches()
            : [...$this->checkWebsites(), ...$this->checkProcesses(), ...$this->checkDisk()];
    }

    /**
     * @return list<string>
     */
    public function checkWebsites(): array
    {
        $report = [];

        foreach ($this->monitoredWebsites() as $website) {
            $url = (string) $website->checkUrl();

            $started = microtime(true);
            $status = null;

            try {
                $response = Http::timeout(10)->withoutRedirecting()->withHeaders(['User-Agent' => 'Laravelify monitor'])->get($url);
                $status = $response->status();
                $ms = (int) round((microtime(true) - $started) * 1000);
                $error = $status >= 500 ? 'HTTP '.$status : null;
                $ok = $error === null ? 'HTTP '.$status.' in '.$ms.' ms' : null;
            } catch (Throwable $exception) {
                $ms = (int) round((microtime(true) - $started) * 1000);
                $error = mb_strimwidth($exception->getMessage(), 0, 200, '…');
                $ok = null;
            }

            // Uptime history for the Monitoring tab.
            UptimeCheck::query()->create([
                'website_id' => $website->id,
                'ok' => $error === null,
                'status' => $status,
                'duration_ms' => $ms,
                'error' => $error !== null ? mb_substr($error, 0, 255) : null,
                'checked_at' => now(),
            ]);

            $report[] = $this->transition(
                'site:'.$website->id,
                $error === null,
                fn (array $state) => $this->telegram->notify('site_down', $this->siteDown($website, (string) $error), $this->websiteButtons($website, ['health', 'logs'])),
                fn (array $state) => $this->telegram->notify('site_up', $this->siteUp($website, (int) ($state['since'] ?? time()), (string) $ok), $this->websiteButtons($website, ['open', 'health'])),
            ) ?? $website->name.': '.($error ?? 'up');
        }

        return $report;
    }

    /**
     * @return list<string>
     */
    public function checkProcesses(): array
    {
        if (! $this->runner->supportsHostCommands()) {
            return [];
        }

        try {
            $running = collect($this->processes->list());
        } catch (Throwable) {
            return [];
        }

        $report = [];

        foreach ($this->monitoredWebsites() as $website) {
            $names = match (true) {
                $website->isLaravel() => array_map(fn (string $name): array => ['supervisor', $name], array_keys($this->logs->programs($website))),
                $website->isNode() => [['pm2', $website->slug]],
                default => [],
            };

            foreach ($names as [$manager, $name]) {
                $row = $running->first(fn (array $process): bool => $process['manager'] === $manager && $process['name'] === $name);
                $state = strtolower((string) ($row['status'] ?? 'missing'));
                $healthy = $manager === 'pm2' ? $state === 'online' : in_array($state, ['running', 'starting'], true);
                $detail = trim($state.' '.($row['detail'] ?? ''));

                $report[] = $this->transition(
                    'process:'.$manager.':'.$name,
                    $healthy,
                    fn () => $this->telegram->notify('process_crashed', $this->processCrashed($website, $manager, $name, $detail), $this->websiteButtons($website, ['health', $manager === 'pm2' ? 'log:pm2' : 'log:program:'.$name])),
                    fn () => $this->telegram->notify('process_recovered', $this->processRecovered($website, $name), $this->websiteButtons($website, ['health'])),
                ) ?? $name.': '.$state;
            }
        }

        return $report;
    }

    /**
     * @return list<string>
     */
    public function checkDisk(string $path = '/'): array
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if (! $total || $free === false) {
            return [];
        }

        $used = (int) round(($total - $free) / $total * 100);
        $limit = max(50, min(99, (int) (Setting::getValue('telegram_disk_threshold') ?: 80)));
        $last = Cache::get('monitor:disk');

        if ($used < $limit) {
            Cache::forget('monitor:disk');

            return ['disk: '.$used.'%'];
        }

        $repeat = ! is_array($last) || $last['at'] < time() - 12 * 3600 || $used >= $last['used'] + 5;

        if ($repeat) {
            $this->telegram->notify('disk_high', $this->diskHigh($used, $limit, (float) $total, (float) $free), [
                ['text' => 'Open terminal', 'url' => route('terminal.index')],
                ['text' => 'File manager', 'url' => route('files.index')],
            ]);
            Cache::put('monitor:disk', ['at' => time(), 'used' => $used], self::STATE_TTL);
        }

        return ['disk: '.$used.'% (limit '.$limit.'%)'.($repeat ? ', alerted' : '')];
    }

    /**
     * @return list<string>
     */
    public function checkPatches(): array
    {
        if (! $this->runner->supportsHostCommands()) {
            return [];
        }

        try {
            $output = $this->hostWriter->serverPatches();
        } catch (Throwable $exception) {
            return ['patches: '.$exception->getMessage()];
        }

        $updates = self::parseUpgradable($output);

        if ($updates === []) {
            Cache::forget('monitor:patches');

            return ['patches: none'];
        }

        $signature = md5(implode('|', array_column($updates, 'package')).count($updates));
        $last = Cache::get('monitor:patches');
        $send = ! is_array($last) || $last['signature'] !== $signature || $last['at'] < time() - 7 * 86400;

        if ($send) {
            $this->telegram->notify('server_patches', $this->patches($updates), [
                ['text' => 'Manage server', 'url' => route('server.index')],
            ]);
            Cache::put('monitor:patches', ['signature' => $signature, 'at' => time()], self::STATE_TTL);
        }

        return ['patches: '.count($updates).($send ? ', alerted' : '')];
    }

    /**
     * Parse `apt list --upgradable` lines like
     * "base-files/jammy-updates 12ubuntu4.7 amd64 [upgradable from: 12ubuntu4.6]".
     *
     * @return list<array{package: string, current_version: string, new_version: string}>
     */
    public static function parseUpgradable(string $output): array
    {
        $updates = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('#^([a-z0-9][a-z0-9+.-]*)/\S+\s+(\S+)\s+\S+\s+\[upgradable from: ([^\]]+)\]#i', trim($line), $match) === 1) {
                $updates[] = ['package' => $match[1], 'current_version' => $match[3], 'new_version' => $match[2]];
            }
        }

        return $updates;
    }

    /**
     * Websites worth watching: they have an address and deployed successfully at least once.
     *
     * @return Collection<int, Website>
     */
    private function monitoredWebsites()
    {
        return Website::query()
            ->where('enabled', true)
            ->whereHas('deployments', fn ($query) => $query->where('status', 'success'))
            ->orderBy('name')
            ->get()
            ->filter(fn (Website $website): bool => (bool) $website->publicUrl());
    }

    /**
     * Track up/down for one thing. Alerts after the second failed check in a row,
     * and once when it recovers. Returns a report line when something was sent.
     */
    private function transition(string $key, bool $healthy, callable $onDown, callable $onUp): ?string
    {
        $state = Cache::get('monitor:'.$key, ['down' => false, 'fails' => 0, 'since' => null]);

        if ($healthy) {
            if ($state['down']) {
                $onUp($state);
                Cache::put('monitor:'.$key, ['down' => false, 'fails' => 0, 'since' => null], self::STATE_TTL);

                return $key.': recovered, alerted';
            }

            Cache::put('monitor:'.$key, ['down' => false, 'fails' => 0, 'since' => null], self::STATE_TTL);

            return null;
        }

        $state['fails']++;
        $state['since'] ??= time();

        if (! $state['down'] && $state['fails'] >= 2) {
            $state['down'] = true;
            $onDown($state);
            Cache::put('monitor:'.$key, $state, self::STATE_TTL);

            return $key.': down, alerted';
        }

        Cache::put('monitor:'.$key, $state, self::STATE_TTL);

        return null;
    }

    private function siteDown(Website $website, string $error): string
    {
        return '🔴 Laravelify: <b>'.Telegram::escape($website->name).'</b> is down! ('.Telegram::escape((string) $website->publicUrl()).")\n\n📊 Details:\n"
            .'• Error: '.Telegram::escape($error)."\n"
            .'• Server: '.Telegram::escape($this->hostname())."\n"
            .'• Checked: '.app(DisplayTime::class)->datetime(now()).', twice in a row';
    }

    private function siteUp(Website $website, int $since, string $ok): string
    {
        return '🟢 Laravelify: <b>'.Telegram::escape($website->name).'</b> is back up ('.Telegram::escape((string) $website->publicUrl()).")\n\n"
            .'• Down for about '.now()->diffForHumans(Carbon::createFromTimestamp($since), true)."\n"
            .'• '.Telegram::escape($ok);
    }

    private function processCrashed(Website $website, string $manager, string $name, string $detail): string
    {
        return '💥 Laravelify: <b>'.Telegram::escape($name).'</b> is not running on '.Telegram::escape($this->hostname())."!\n\n📊 Details:\n"
            .'• Website: '.Telegram::escape($website->name)."\n"
            .'• Manager: '.($manager === 'pm2' ? 'PM2' : 'Supervisor')."\n"
            .'• State: '.Telegram::escape($detail !== '' ? $detail : 'missing');
    }

    private function processRecovered(Website $website, string $name): string
    {
        return '✅ Laravelify: <b>'.Telegram::escape($name).'</b> is running again ('.Telegram::escape($website->name).')';
    }

    private function diskHigh(int $used, int $limit, float $total, float $free): string
    {
        $gb = fn (float $bytes): string => number_format($bytes / 1073741824, 1).' GB';

        return '💾 Laravelify: [ACTION REQUIRED] Disk usage is at '.$used.'% on '.Telegram::escape($this->hostname())."!\n\n📊 Summary:\n"
            .'• Used: '.$gb($total - $free).' of '.$gb($total)."\n"
            .'• Free: '.$gb($free)."\n"
            .'• Alert limit: '.$limit."%\n\n"
            .'💡 Look for big logs and caches: <code>du -sh /var/www/* | sort -h</code>';
    }

    /**
     * @param  list<array{package: string, current_version: string, new_version: string}>  $updates
     */
    private function patches(array $updates): string
    {
        $os = $this->osName();
        $message = '🔧 Laravelify: [ACTION REQUIRED] '.count($updates).' server patches available on '.Telegram::escape($this->hostname())."!\n\n📊 Summary:\n"
            .'• OS: '.Telegram::escape($os)."\n"
            ."• Package Manager: apt\n"
            .'• Total Updates: '.count($updates)."\n\n📦 Sample Updates:\n";

        foreach (array_slice($updates, 0, 5) as $update) {
            $message .= '• '.Telegram::escape($update['package']).': '.Telegram::escape($update['current_version']).' → '.Telegram::escape($update['new_version'])."\n";
        }

        if (count($updates) > 5) {
            $message .= '• ... and '.(count($updates) - 5)." more packages\n";
        }

        $critical = array_values(array_filter($updates, fn (array $update): bool => preg_match('/kernel|linux-(image|headers)|openssh|openssl|libssl|nginx|php|mysql|redis|docker|systemd/i', $update['package']) === 1));

        if ($critical !== []) {
            $message .= "\n⚠️ Critical packages detected: ".count($critical)." packages may require restarts\n";

            foreach (array_slice($critical, 0, 3) as $update) {
                $message .= '• '.Telegram::escape($update['package']).': '.Telegram::escape($update['current_version']).' → '.Telegram::escape($update['new_version'])."\n";
            }

            if (count($critical) > 3) {
                $message .= '• ... and '.(count($critical) - 3)." more critical packages\n";
            }
        }

        return $message."\n💡 Apply them over SSH: <code>sudo apt update &amp;&amp; sudo apt upgrade</code>";
    }

    /**
     * @param  list<string>  $kinds  open, health, logs, log:{source}
     * @return list<array{text: string, url: string}>
     */
    private function websiteButtons(Website $website, array $kinds): array
    {
        $page = route('websites.show', $website);

        return array_values(array_filter(array_map(fn (string $kind): ?array => match (true) {
            $kind === 'open' && (bool) $website->publicUrl() => ['text' => 'Open website', 'url' => (string) $website->publicUrl()],
            $kind === 'health' => ['text' => 'Health', 'url' => $page.'?tab=health'],
            $kind === 'logs' => ['text' => 'Logs', 'url' => $page.'?tab=logs'],
            str_starts_with($kind, 'log:') => ['text' => 'Logs', 'url' => $page.'?tab=logs&log='.rawurlencode(substr($kind, 4))],
            default => null,
        }, $kinds)));
    }

    private function hostname(): string
    {
        return (string) (gethostname() ?: 'this server');
    }

    private function osName(): string
    {
        $release = @file_get_contents('/etc/os-release');

        if (is_string($release) && preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', $release, $match) === 1) {
            return $match[1];
        }

        return PHP_OS_FAMILY;
    }
}
