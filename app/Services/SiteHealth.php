<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Quick checks that tell whether a website is healthy right now.
 */
class SiteHealth
{
    public function __construct(
        private ServerProcesses $processes,
        private SiteLogs $logs,
        private HostWriter $hostWriter,
        private ConfigRenderer $renderer,
        private CommandRunner $runner,
    ) {}

    /**
     * @return list<array{key: string, title: string, status: string, detail: string, log?: string, program?: string, manager?: string, action?: string}>
     */
    public function check(Website $website): array
    {
        return [
            $this->http($website),
            ...$this->programs($website),
            ...($website->isLaravel() ? [$this->laravelErrors($website)] : []),
            $this->nginxConfig(),
            $this->nginxLogging($website),
            $this->lastDeploy($website),
        ];
    }

    /**
     * @return array{key: string, title: string, status: string, detail: string}
     */
    private function http(Website $website): array
    {
        $url = $website->checkUrl();

        if (! $url) {
            return ['key' => 'http', 'title' => 'Website responds', 'status' => 'unknown', 'detail' => 'No domain or HTTP port yet.'];
        }

        try {
            $started = microtime(true);
            $response = Http::timeout(10)->withoutRedirecting()->withHeaders(['User-Agent' => 'Laravelify health check'])->get($url);
            $ms = (int) round((microtime(true) - $started) * 1000);
            $status = $response->status();

            return [
                'key' => 'http',
                'title' => 'Website responds',
                'status' => $status >= 500 ? 'fail' : ($status >= 400 ? 'warn' : 'ok'),
                'detail' => 'HTTP '.$status.' in '.$ms.' ms from '.$url.($status >= 500 ? '. Check the Laravel and nginx error logs.' : ''),
                'log' => $status >= 500 ? ($website->isLaravel() ? 'laravel' : 'nginx-error') : null,
            ];
        } catch (Throwable $exception) {
            return ['key' => 'http', 'title' => 'Website responds', 'status' => 'fail', 'detail' => 'No answer from '.$url.': '.mb_strimwidth($exception->getMessage(), 0, 180, '…'), 'log' => 'nginx-error'];
        }
    }

    /**
     * @return list<array{key: string, title: string, status: string, detail: string, log?: string, program?: string, manager?: string, action?: string}>
     */
    private function programs(Website $website): array
    {
        if (! $this->runner->supportsHostCommands()) {
            return [['key' => 'processes', 'title' => 'Background processes', 'status' => 'unknown', 'detail' => 'Host commands are disabled on this machine.']];
        }

        $running = collect($this->processes->list());

        if (! $website->hasProcessFile()) {
            return [];
        }

        if ($website->isNode()) {
            $process = $running->first(fn (array $row): bool => $row['manager'] === 'pm2' && $row['name'] === $website->slug);

            return [[
                'key' => 'pm2',
                'title' => 'PM2 · '.$website->slug,
                'status' => $process === null ? 'fail' : ($process['status'] === 'online' ? 'ok' : 'fail'),
                'detail' => $process === null ? 'Not running in PM2. Deploy the site or check the process file.' : $process['status'].' · '.$process['detail'],
                'log' => 'pm2',
                'program' => $website->slug,
                'manager' => 'pm2',
                'action' => 'restart',
            ]];
        }

        $checks = [];

        foreach (array_keys($this->logs->programs($website)) as $program) {
            $process = $running->first(fn (array $row): bool => $row['manager'] === 'supervisor' && $row['name'] === $program);
            $state = strtoupper((string) ($process['status'] ?? 'missing'));

            $checks[] = [
                'key' => 'program:'.$program,
                'title' => 'Supervisor · '.$program,
                'status' => $state === 'RUNNING' ? 'ok' : ($state === 'STARTING' ? 'warn' : 'fail'),
                'detail' => match (true) {
                    $process === null => 'Not loaded in Supervisor. Save the Processes tab to apply it.',
                    in_array($state, ['FATAL', 'BACKOFF', 'EXITED'], true) => trim($state.' '.$process['detail']).'. It kept crashing, so Supervisor stopped retrying. Read its log, fix the cause, then Restart.',
                    default => trim($state.' '.$process['detail']),
                },
                'log' => 'program:'.$program,
                'program' => $program,
                'manager' => 'supervisor',
                'action' => 'restart',
            ];
        }

        return $checks === []
            ? [['key' => 'processes', 'title' => 'Background processes', 'status' => 'unknown', 'detail' => 'No Supervisor programs in the process file.']]
            : $checks;
    }

    /**
     * @return array{key: string, title: string, status: string, detail: string, log: string}
     */
    private function laravelErrors(Website $website): array
    {
        try {
            $errors = $this->logs->recentErrors($website);
        } catch (Throwable $exception) {
            return ['key' => 'errors', 'title' => 'Laravel errors (24h)', 'status' => 'unknown', 'detail' => $exception->getMessage(), 'log' => 'laravel'];
        }

        return [
            'key' => 'errors',
            'title' => 'Laravel errors (24h)',
            'status' => $errors['count'] === 0 ? 'ok' : 'warn',
            'detail' => $errors['count'] === 0 ? 'No errors logged in the last 24 hours.' : $errors['count'].' errors. Latest: '.$errors['last'],
            'log' => 'laravel',
        ];
    }

    /**
     * @return array{key: string, title: string, status: string, detail: string}
     */
    private function nginxConfig(): array
    {
        if (! $this->runner->supportsHostCommands()) {
            return ['key' => 'nginx', 'title' => 'nginx configuration', 'status' => 'unknown', 'detail' => 'Host commands are disabled on this machine.'];
        }

        try {
            $output = $this->hostWriter->nginxTest();

            return ['key' => 'nginx', 'title' => 'nginx configuration', 'status' => 'ok', 'detail' => str_contains($output, 'successful') ? 'nginx -t: syntax is ok, test is successful.' : trim($output)];
        } catch (Throwable $exception) {
            return ['key' => 'nginx', 'title' => 'nginx configuration', 'status' => 'fail', 'detail' => mb_strimwidth(trim($exception->getMessage()), 0, 400, '…')];
        }
    }

    /**
     * @return array{key: string, title: string, status: string, detail: string, action?: string, log?: string}
     */
    private function nginxLogging(Website $website): array
    {
        return $this->renderer->hasNginxLogs($website)
            ? ['key' => 'nginx-logs', 'title' => 'nginx site logs', 'status' => 'ok', 'detail' => 'Access and error logs are written per site.', 'log' => 'nginx-error']
            : ['key' => 'nginx-logs', 'title' => 'nginx site logs', 'status' => 'warn', 'detail' => 'This site writes to the shared nginx logs, so the panel cannot show them.', 'action' => 'enable-nginx-logs'];
    }

    /**
     * @return array{key: string, title: string, status: string, detail: string}
     */
    private function lastDeploy(Website $website): array
    {
        $deployment = $website->deployments()->first();

        if (! $deployment) {
            return ['key' => 'deploy', 'title' => 'Last deploy', 'status' => 'unknown', 'detail' => 'Never deployed.'];
        }

        return [
            'key' => 'deploy',
            'title' => 'Last deploy',
            'status' => match ($deployment->status) {
                'success' => 'ok',
                'failed' => 'fail',
                default => 'warn',
            },
            'detail' => ucfirst($deployment->status).' '.app(DisplayTime::class)->ago($deployment->created_at)
                .($deployment->commit_sha ? ' · '.substr($deployment->commit_sha, 0, 7) : '')
                .($deployment->commit_message ? ' · '.mb_strimwidth(strtok($deployment->commit_message, "\n") ?: '', 0, 80, '…') : ''),
        ];
    }
}
