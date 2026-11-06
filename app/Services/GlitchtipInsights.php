<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Website;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * What GlitchTip knows about the websites, read from its API on this server so the panel's
 * Monitoring page shows it: issues (with their latest stack trace), slow transactions, and logs.
 * Every list is for all websites or one, over a period.
 */
class GlitchtipInsights
{
    /** Periods the pages offer, in hours. */
    public const PERIODS = ['1h' => 1, '24h' => 24, '7d' => 168, '14d' => 336, '30d' => 720];

    public const LOG_LEVELS = ['trace', 'debug', 'info', 'warn', 'error', 'fatal'];

    /** Rows per page. */
    public const PAGE_SIZE = 25;

    public const TRANSACTION_SORTS = ['-avg_duration' => 'Slowest first', '-count' => 'Most requests', 'avg_duration' => 'Fastest first'];

    public function __construct(private Glitchtip $glitchtip) {}

    public function available(): bool
    {
        return $this->glitchtip->installed() && filled(Setting::getValue('glitchtip.token'));
    }

    /**
     * Websites with a GlitchTip project, by project id.
     *
     * @return array<int, string>
     */
    public function projects(): array
    {
        return Website::query()->whereNotNull('glitchtip_project_id')->orderBy('name')->pluck('name', 'glitchtip_project_id')
            ->mapWithKeys(fn (string $name, $id) => [(int) $id => $name])->all();
    }

    /**
     * @param  array{project?: ?int, period?: string, query?: string, status?: string}  $filters
     * @return array{rows: list<array<string, mixed>>, next: ?string, previous: ?string, total: ?int}
     */
    public function issues(array $filters, ?string $cursor = null, int $limit = self::PAGE_SIZE): array
    {
        $status = in_array($filters['status'] ?? '', ['unresolved', 'resolved', 'ignored'], true) ? $filters['status'] : 'unresolved';
        $search = trim('is:'.$status.' '.($filters['query'] ?? ''));
        $projects = $this->projects();

        $page = $this->fetch('/organizations/'.Glitchtip::ORGANIZATION.'/issues/', [
            'query' => $search,
            'project' => $filters['project'] ?? null,
            'start' => $this->start($filters['period'] ?? '24h'),
        ], $cursor, $limit);

        return $page + ['rows' => array_map(fn (array $issue) => [
            'id' => (string) $issue['id'],
            'short_id' => (string) ($issue['shortId'] ?? ''),
            'title' => (string) ($issue['title'] ?? $issue['metadata']['value'] ?? 'Error'),
            'type' => (string) ($issue['metadata']['type'] ?? ''),
            'culprit' => (string) ($issue['culprit'] ?? ''),
            'level' => strtolower((string) ($issue['level'] ?? 'error')),
            'status' => strtolower((string) ($issue['status'] ?? 'unresolved')),
            'count' => (int) ($issue['count'] ?? 0),
            'users' => (int) ($issue['userCount'] ?? 0),
            'first_seen' => $issue['firstSeen'] ?? null,
            'last_seen' => $issue['lastSeen'] ?? null,
            'website' => $projects[(int) ($issue['project']['id'] ?? 0)] ?? ($issue['project']['name'] ?? ''),
            'trend' => array_map(fn (array $point) => (int) ($point[1] ?? 0), $issue['stats']['24h'] ?? []),
        ], $page['json'])];
    }

    /**
     * The newest event of an issue: what happened, where (stack frames, newest call first), and
     * the request and tags around it.
     *
     * @return array<string, mixed>
     */
    public function latestEvent(string $issueId): array
    {
        $event = $this->api()->get('/organizations/'.Glitchtip::ORGANIZATION.'/issues/'.(int) $issueId.'/events/latest/')->throw()->json();
        $exception = collect($event['entries'] ?? [])->firstWhere('type', 'exception')['data']['values'] ?? [];
        $request = collect($event['entries'] ?? [])->firstWhere('type', 'request')['data'] ?? null;
        $last = end($exception) ?: [];

        $frames = collect($last['stacktrace']['frames'] ?? [])->reverse()->take(25)->map(fn (array $frame) => [
            'file' => (string) ($frame['filename'] ?? $frame['absPath'] ?? $frame['module'] ?? '?'),
            'function' => (string) ($frame['function'] ?? ''),
            'line' => $frame['lineNo'] ?? $frame['lineno'] ?? null,
            'in_app' => (bool) ($frame['inApp'] ?? $frame['in_app'] ?? false),
            'context' => array_map(fn ($line) => ['number' => $line[0] ?? null, 'code' => (string) ($line[1] ?? '')], $frame['context'] ?? []),
        ])->values()->all();

        return [
            'id' => (string) ($event['eventID'] ?? $event['id'] ?? ''),
            'type' => (string) ($last['type'] ?? ''),
            'message' => (string) ($last['value'] ?? $event['message'] ?? $event['title'] ?? ''),
            'date' => $event['dateCreated'] ?? null,
            'frames' => $frames,
            'request' => $request ? ['method' => $request['method'] ?? '', 'url' => $request['url'] ?? ''] : null,
            'tags' => collect($event['tags'] ?? [])->mapWithKeys(fn (array $tag) => [(string) ($tag['key'] ?? '') => (string) ($tag['value'] ?? '')])
                ->only(['environment', 'release', 'browser', 'os', 'runtime', 'server_name', 'url', 'level'])->all(),
        ];
    }

    /** Resolve, ignore or reopen an issue. */
    public function setStatus(string $issueId, string $status): void
    {
        abort_unless(in_array($status, ['resolved', 'ignored', 'unresolved'], true), 422);

        $this->api()->put('/organizations/'.Glitchtip::ORGANIZATION.'/issues/'.(int) $issueId.'/', ['status' => $status])->throw();
    }

    /**
     * Endpoints and jobs by speed: how often, how slow (average, p50, p95), how often they fail.
     *
     * @param  array{project?: ?int, period?: string, query?: string, sort?: string}  $filters
     * @return array{rows: list<array<string, mixed>>, next: ?string, previous: ?string, total: ?int}
     */
    public function transactions(array $filters, ?string $cursor = null, int $limit = self::PAGE_SIZE): array
    {
        $projects = $this->projects();
        $sort = array_key_exists($filters['sort'] ?? '', self::TRANSACTION_SORTS) ? $filters['sort'] : '-avg_duration';

        $page = $this->fetch('/organizations/'.Glitchtip::ORGANIZATION.'/transaction-groups/', [
            'project' => $filters['project'] ?? null,
            'start' => $this->start($filters['period'] ?? '24h'),
            'query' => $filters['query'] ?? null,
            'sort' => $sort,
        ], $cursor, $limit);

        return $page + ['rows' => array_map(fn (array $group) => [
            'id' => (string) $group['id'],
            'transaction' => (string) ($group['transaction'] ?? ''),
            'op' => (string) ($group['op'] ?? ''),
            'method' => (string) ($group['method'] ?? ''),
            'count' => (int) ($group['count'] ?? 0),
            'avg' => isset($group['avgDuration']) ? (float) $group['avgDuration'] : null,
            'p50' => isset($group['p50']) ? (float) $group['p50'] : null,
            'p95' => isset($group['p95']) ? (float) $group['p95'] : null,
            'error_rate' => (float) ($group['errorRate'] ?? 0),
            'throughput' => isset($group['throughput']) ? (float) $group['throughput'] : null,
            'last_seen' => $group['lastSeen'] ?? null,
            'website' => $projects[(int) ($group['project'] ?? 0)] ?? '',
        ], $page['json'])];
    }

    /**
     * @param  array{project?: ?int, period?: string, query?: string, level?: string}  $filters
     * @return array{rows: list<array<string, mixed>>, next: ?string, previous: ?string, total: ?int}
     */
    public function logs(array $filters, ?string $cursor = null, int $limit = self::PAGE_SIZE): array
    {
        $projects = $this->projects();
        $level = in_array($filters['level'] ?? '', self::LOG_LEVELS, true) ? $filters['level'] : null;

        $page = $this->fetch('/organizations/'.Glitchtip::ORGANIZATION.'/logs/', [
            'project' => $filters['project'] ?? null,
            'level' => $level,
            'query' => $filters['query'] ?? null,
            'start' => $this->start($filters['period'] ?? '24h'),
        ], $cursor, $limit);

        return $page + ['rows' => array_map(fn (array $log) => [
            'id' => (string) $log['id'],
            'time' => $log['timestamp'] ?? null,
            'level' => strtolower((string) ($log['level'] ?? 'info')),
            'body' => (string) ($log['body'] ?? ''),
            'service' => (string) ($log['service'] ?? ''),
            'environment' => (string) ($log['environment'] ?? ''),
            'trace_id' => $log['traceID'] ?? null,
            'data' => (array) ($log['data'] ?? []),
            'website' => $projects[(int) ($log['projectId'] ?? 0)] ?? '',
        ], $page['json'])];
    }

    /**
     * How many log lines of each level over the period (all pages), from GlitchTip's hourly stats.
     *
     * @param  array{project?: ?int, period?: string}  $filters
     * @return array<string, int>
     */
    public function logCounts(array $filters): array
    {
        $stats = $this->api()->get('/organizations/'.Glitchtip::ORGANIZATION.'/logs/stats/', array_filter([
            'project' => $filters['project'] ?? null,
            'start' => $this->start($filters['period'] ?? '24h'),
        ]))->throw()->json();

        return collect($stats['series'] ?? [])->mapWithKeys(fn (array $series) => [
            strtolower((string) ($series['name'] ?? '')) => (int) array_sum((array) ($series['data'] ?? [])),
        ])->all();
    }

    /**
     * One page of a list: its rows, the cursors of the pages around it, and the total when
     * GlitchTip says it (x-hits).
     *
     * @param  array<string, mixed>  $query
     * @return array{json: list<array<string, mixed>>, next: ?string, previous: ?string, total: ?int}
     */
    private function fetch(string $path, array $query, ?string $cursor, int $limit): array
    {
        $response = $this->api()->get($path, array_filter([...$query, 'cursor' => $cursor, 'limit' => $limit]))->throw();
        $link = $response->header('Link');
        $cursorOf = fn (string $rel) => preg_match('/rel="'.$rel.'";\s*results="true";\s*cursor="([^"]+)"/', $link, $match) === 1 ? $match[1] : null;
        $json = $response->json();

        return [
            'json' => is_array($json) && array_is_list($json) ? $json : [],
            'next' => $cursorOf('next'),
            'previous' => $cursorOf('previous'),
            'total' => $response->header('X-Hits') !== '' ? (int) $response->header('X-Hits') : null,
        ];
    }

    private function start(string $period): string
    {
        return Carbon::now()->subHours(self::PERIODS[$period] ?? 24)->toIso8601String();
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl(Glitchtip::API)->withToken((string) Setting::getValue('glitchtip.token'))->acceptJson()->asJson()->timeout(15);
    }
}
