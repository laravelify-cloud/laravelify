<?php

namespace App\Services;

use App\Models\SiteIssue;
use App\Models\Website;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Turns new ERROR (or worse) entries of a Laravel site's log into issues,
 * grouped by exception class and the file and line that threw it.
 */
class IssueCollector
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * On the first run, look back this far into an existing log.
     */
    private const FIRST_READ = 512 * 1024;

    private const LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    public function __construct(
        private LogTailer $tailer,
        private SitePaths $paths,
    ) {}

    /**
     * @return list<array{issue: SiteIssue, kind: string}> New and returning issues, for alerts.
     */
    public function collect(Website $website): array
    {
        if (! $website->isLaravel()) {
            return [];
        }

        try {
            $root = str_replace('\\', '/', $this->paths->root($website));
        } catch (RuntimeException) {
            return [];
        }

        $files = glob($root.'/storage/logs/laravel*.log') ?: [];
        usort($files, fn (string $a, string $b): int => (int) @filemtime($b) <=> (int) @filemtime($a));

        if ($files === []) {
            return [];
        }

        $read = $this->tailer->read($website, 'laravel', str_replace('\\', '/', $files[0]), self::MAX_BYTES, self::FIRST_READ);

        if ($read === null) {
            return [];
        }

        $changes = [];

        foreach ($this->entries($read['text'], $root) as $entry) {
            $change = $this->record($website, $entry);

            if ($change !== null) {
                $changes[] = $change;
            }
        }

        $this->tailer->commit($read);

        return $changes;
    }

    /**
     * Parsed log entries at ERROR level or worse.
     *
     * @return list<array{at: Carbon, level: string, exception: ?string, message: string, file: ?string, line: ?int, trace: ?string, fingerprint: string}>
     */
    public function entries(string $text, string $root = ''): array
    {
        $entries = [];
        $chunks = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/m', $text) ?: [];

        foreach ($chunks as $chunk) {
            if (preg_match('/^\[(?<time>[^\]]+)\]\s+[\w.-]+\.(?<level>[A-Z]+):\s?(?<rest>.*)$/s', $chunk, $match) !== 1 || ! in_array($match['level'], self::LEVELS, true)) {
                continue;
            }

            $lines = preg_split('/\R/', rtrim($match['rest'])) ?: [''];
            $first = $lines[0];
            $exception = $file = $line = null;

            if (preg_match('/"exception":"\[object\] \((?<class>[A-Za-z0-9_\\\\]+)\(code: [^)]*\): (?<message>.*) at (?<file>\S+):(?<line>\d+)\)\s*$/', $first, $found) === 1) {
                $exception = str_replace('\\\\', '\\', $found['class']);
                $message = $this->unescape($found['message']);
                $file = $this->relative($this->unescape($found['file']), $root);
                $line = (int) $found['line'];
            } else {
                $message = trim((string) preg_replace(['/\s+\{".*$/', '/\s+\[\]\s*$/'], '', $first));
            }

            $traceStart = array_search('[stacktrace]', $lines, true);
            $trace = $traceStart === false ? null : implode("\n", array_map(
                fn (string $traceLine): string => $this->relative($traceLine, $root),
                array_slice($lines, $traceStart + 1, 25),
            ));

            try {
                $at = Carbon::parse($match['time']);
            } catch (Throwable) {
                $at = now();
            }

            $entries[] = [
                'at' => $at,
                'level' => $match['level'],
                'exception' => $exception,
                'message' => mb_substr($message !== '' ? $message : $match['level'], 0, 2000),
                'file' => $file !== null ? mb_substr($file, 0, 500) : null,
                'line' => $line,
                'trace' => $trace,
                'fingerprint' => $exception !== null
                    ? sha1($exception.'|'.$file.'|'.$line)
                    : sha1($match['level'].'|'.$this->normalize($message)),
            ];
        }

        return $entries;
    }

    /**
     * @param  array{at: Carbon, level: string, exception: ?string, message: string, file: ?string, line: ?int, trace: ?string, fingerprint: string}  $entry
     * @return array{issue: SiteIssue, kind: string}|null
     */
    private function record(Website $website, array $entry): ?array
    {
        $issue = SiteIssue::query()->firstWhere(['website_id' => $website->id, 'fingerprint' => $entry['fingerprint']]);

        if ($issue === null) {
            $issue = SiteIssue::query()->create([
                'website_id' => $website->id,
                'fingerprint' => $entry['fingerprint'],
                'level' => $entry['level'],
                'exception' => $entry['exception'],
                'message' => $entry['message'],
                'file' => $entry['file'],
                'line' => $entry['line'],
                'trace' => $entry['trace'],
                'occurrences' => 1,
                'first_seen_at' => $entry['at'],
                'last_seen_at' => $entry['at'],
                'status' => 'open',
            ]);

            return ['issue' => $issue, 'kind' => 'new'];
        }

        $returned = $issue->status === 'resolved';
        $issue->fill([
            'message' => $entry['message'],
            'trace' => $entry['trace'] ?? $issue->trace,
            'occurrences' => $issue->occurrences + 1,
            'last_seen_at' => $entry['at']->greaterThan($issue->last_seen_at) ? $entry['at'] : $issue->last_seen_at,
        ]);

        if ($returned) {
            $issue->status = 'open';
            $issue->resolved_at = null;
        }

        $issue->save();

        return $returned ? ['issue' => $issue, 'kind' => 'returned'] : null;
    }

    private function unescape(string $value): string
    {
        $decoded = json_decode('"'.$value.'"');

        return is_string($decoded) ? $decoded : stripslashes($value);
    }

    private function relative(string $value, string $root): string
    {
        return $root !== '' ? str_replace(rtrim($root, '/').'/', '', $value) : $value;
    }

    /**
     * Messages that differ only by IDs or quoted values are the same issue.
     */
    private function normalize(string $message): string
    {
        return (string) preg_replace(
            ['/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/"[^"]*"|\'[^\']*\'/', '/\d+/'],
            ['{uuid}', '?', 'N'],
            mb_substr($message, 0, 300),
        );
    }
}
