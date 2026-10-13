<?php

namespace App\Services;

use App\Models\Website;
use RuntimeException;

/**
 * The log files of one website: Laravel, every Supervisor program, other
 * storage/logs files, PM2 for Next.js sites, and the site's nginx logs.
 */
class SiteLogs
{
    public function __construct(
        private SitePaths $paths,
        private HostWriter $hostWriter,
        private ServerProcesses $processes,
        private ConfigRenderer $renderer,
    ) {}

    /**
     * @return list<array{key: string, label: string, group: string, path: string, size: string, modified: ?int}>
     */
    public function sources(Website $website): array
    {
        $sources = [];
        $root = $this->siteRoot($website);
        $seen = [];

        if ($root !== null && $website->isLaravel()) {
            $laravel = $this->newest($root.'/storage/logs', 'laravel*.log');
            $sources[] = $this->fileSource('laravel', 'Laravel', 'Application', $laravel ?? $root.'/storage/logs/laravel.log');
            $seen[] = $laravel;
        }

        foreach ($this->programs($website) as $program => $logfile) {
            $path = $root !== null ? $this->insideSite($root, $logfile) : null;

            if ($path === null) {
                continue;
            }

            $label = str_starts_with($program, $website->slug.'-') ? substr($program, strlen($website->slug) + 1) : $program;
            $sources[] = $this->fileSource('program:'.$program, ucfirst($label), 'Supervisor', $path);
            $seen[] = $path;
        }

        if (! $website->isLaravel()) {
            $sources[] = ['key' => 'pm2', 'label' => 'PM2 output', 'group' => 'Application', 'path' => 'pm2 logs '.$website->slug, 'size' => '', 'modified' => null];
        }

        if ($root !== null) {
            foreach (glob($root.'/storage/logs/*.log') ?: [] as $file) {
                if (in_array($file, $seen, true) || is_link($file)) {
                    continue;
                }

                $sources[] = $this->fileSource('file:'.basename($file), basename($file), 'Other logs', $file);
            }
        }

        $sources[] = ['key' => 'nginx-access', 'label' => 'Access log', 'group' => 'nginx', 'path' => '/var/log/nginx/laravelify-'.$website->slug.'.access.log', 'size' => '', 'modified' => null];
        $sources[] = ['key' => 'nginx-error', 'label' => 'Error log', 'group' => 'nginx', 'path' => '/var/log/nginx/laravelify-'.$website->slug.'.error.log', 'size' => '', 'modified' => null];

        return $sources;
    }

    /**
     * The last lines of one source.
     */
    public function tail(Website $website, string $key, int $lines): string
    {
        $lines = max(10, min($lines, 5000));

        if ($key === 'nginx-access' || $key === 'nginx-error') {
            if (! $this->renderer->hasNginxLogs($website)) {
                return '';
            }

            return $this->hostWriter->nginxLog($website, $key === 'nginx-access' ? 'access' : 'error', $lines);
        }

        if ($key === 'pm2') {
            return $this->processes->logs('pm2', $website->slug);
        }

        $path = $this->pathFor($website, $key);

        if (! is_file($path)) {
            return '';
        }

        return $this->readTail($path, $lines);
    }

    /**
     * Empty one of the site's own log files.
     */
    public function truncate(Website $website, string $key): void
    {
        $path = $this->pathFor($website, $key);

        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('That log file does not exist.');
        }

        if (file_put_contents($path, '') === false) {
            throw new RuntimeException('Could not clear that log. Check its permissions.');
        }
    }

    /**
     * Laravel log entries at ERROR level or worse during the last day.
     *
     * @return array{count: int, last: ?string}
     */
    public function recentErrors(Website $website): array
    {
        $root = $this->siteRoot($website);
        $file = $root !== null && $website->isLaravel() ? $this->newest($root.'/storage/logs', 'laravel*.log') : null;

        if ($file === null) {
            return ['count' => 0, 'last' => null];
        }

        $since = now()->subDay();
        $count = 0;
        $last = null;

        foreach (preg_split('/\R/', $this->readTail($file, 4000)) ?: [] as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\]\s+\w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }

            if (strtotime($match[1]) >= $since->getTimestamp()) {
                $count++;
                $last = mb_strimwidth($match[3], 0, 200, '…');
            }
        }

        return ['count' => $count, 'last' => $last];
    }

    /**
     * Supervisor program names and their stdout log files from the process config.
     *
     * @return array<string, string>
     */
    public function programs(Website $website): array
    {
        $programs = [];
        $current = null;

        foreach (preg_split('/\R/', (string) $website->process_config) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^\[program:([A-Za-z0-9_.-]+)\]$/', $line, $match) === 1) {
                $current = $match[1];

                continue;
            }

            if ($current !== null && preg_match('/^stdout_logfile\s*=\s*(\S+)/', $line, $match) === 1) {
                $programs[$current] = $match[1];
            }
        }

        return $programs;
    }

    private function pathFor(Website $website, string $key): string
    {
        $root = $this->siteRoot($website) ?? throw new RuntimeException('Deploy the site first.');

        if ($key === 'laravel') {
            return $this->newest($root.'/storage/logs', 'laravel*.log') ?? $root.'/storage/logs/laravel.log';
        }

        if (str_starts_with($key, 'program:')) {
            $logfile = $this->programs($website)[substr($key, 8)] ?? null;
            $path = $logfile !== null ? $this->insideSite($root, $logfile) : null;

            return $path ?? throw new RuntimeException('That program has no log file inside the site folder.');
        }

        if (str_starts_with($key, 'file:')) {
            $name = basename(substr($key, 5));

            if (! str_ends_with($name, '.log')) {
                throw new RuntimeException('Unknown log.');
            }

            return $root.'/storage/logs/'.$name;
        }

        throw new RuntimeException('Unknown log.');
    }

    private function siteRoot(Website $website): ?string
    {
        try {
            return str_replace('\\', '/', $this->paths->root($website));
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * A log path from the config, only when it is inside the site folder.
     */
    private function insideSite(string $root, string $path): ?string
    {
        $path = str_replace('\\', '/', $path);

        if (str_contains($path, '..') || ! $this->paths->inside($root, $path)) {
            return null;
        }

        return $path;
    }

    private function newest(string $directory, string $pattern): ?string
    {
        $files = glob($directory.'/'.$pattern) ?: [];
        usort($files, fn (string $a, string $b): int => (int) @filemtime($b) <=> (int) @filemtime($a));

        return $files[0] ?? null;
    }

    /**
     * @return array{key: string, label: string, group: string, path: string, size: string, modified: ?int}
     */
    private function fileSource(string $key, string $label, string $group, string $path): array
    {
        $exists = is_file($path);
        $bytes = $exists ? (int) @filesize($path) : 0;

        return [
            'key' => $key,
            'label' => $label,
            'group' => $group,
            'path' => $path,
            'size' => $exists ? ($bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024, 1).' KB') : 'missing',
            'modified' => $exists ? (int) @filemtime($path) : null,
        ];
    }

    /**
     * Read the last lines without loading the whole file.
     */
    private function readTail(string $path, int $lines): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Cannot read '.basename($path).'. Check its permissions.');
        }

        $size = (int) (fstat($handle)['size'] ?? 0);
        $chunk = 65536;
        $buffer = '';
        $position = $size;

        while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
            $read = min($chunk, $position);
            $position -= $read;
            fseek($handle, $position);
            $buffer = fread($handle, $read).$buffer;

            if (strlen($buffer) > 8 * 1024 * 1024) {
                break;
            }
        }

        fclose($handle);

        $rows = preg_split('/\R/', rtrim($buffer, "\r\n")) ?: [];

        return implode("\n", array_slice($rows, -$lines));
    }
}
