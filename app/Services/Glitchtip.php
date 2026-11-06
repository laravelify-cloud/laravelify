<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Support\EnvFile;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Error tracking with GlitchTip (MIT), an optional add-on on the panel's own server at /glitchtip.
 * The Sentry SDKs in a website (Laravel, PHP, Node, Next.js, browser apps) send its errors there:
 * each website gets a project (every website when GlitchTip is installed, and each new one), and
 * its key (DSN) goes into the website's .env under the name that site type's SDK reads, so the next
 * deploy picks it up.
 */
class Glitchtip
{
    public const VERSION = 'v6.2.6';

    public const ORGANIZATION = 'laravelify';

    public const TEAM = 'websites';

    /** Where the panel reaches GlitchTip's API (its own process; nginx is for browsers). */
    public const API = 'http://127.0.0.1:8095/glitchtip/api/0';

    public const ISSUE_STATUSES = ['unresolved', 'resolved', 'ignored'];

    public const ISSUE_SORTS = ['-last_seen' => 'Last seen', '-first_seen' => 'Newest', '-count' => 'Most events', '-priority' => 'Priority'];

    public const TRANSACTION_SORTS = ['-avg_duration' => 'Slowest', '-count' => 'Most runs', 'avg_duration' => 'Fastest', '-created' => 'Newest'];

    public const LOG_LEVELS = ['trace', 'debug', 'info', 'warn', 'error', 'fatal'];

    /** Time ranges for performance and logs. */
    public const PERIODS = ['1h' => '1h', '24h' => '24h', '7d' => '7d', '14d' => '14d', '30d' => '30d'];

    public function __construct(private HostWriter $host) {}

    public function installed(): bool
    {
        return filled(Setting::getValue('glitchtip.token'));
    }

    /** GlitchTip in the browser, behind its own sign-in. */
    public function url(string $path = ''): string
    {
        return rtrim((string) config('app.url'), '/').'/glitchtip/'.ltrim($path, '/');
    }

    /** A GlitchTip page, opened through the panel, which signs the browser in first. */
    public function open(string $path = ''): string
    {
        return route('tools.glitchtip', $path === '' ? [] : ['to' => $path]);
    }

    /** @return array{email: ?string, password: ?string} */
    public function login(): array
    {
        return ['email' => Setting::getValue('glitchtip.email'), 'password' => Setting::getValue('glitchtip.password')];
    }

    /**
     * Sign the panel's GlitchTip admin in, as a browser would (allauth's headless login), and return
     * GlitchTip's cookies: GlitchTip is on the panel's own address, so they can be handed to the
     * signed-in panel user's browser. Null when GlitchTip turned the sign-in down (its password
     * was changed there).
     *
     * @return array<string, string>|null name => value (sessionid, csrftoken)
     */
    public function signIn(): ?array
    {
        $login = $this->login();

        if (blank($login['email']) || blank($login['password'])) {
            return null;
        }

        $base = Http::baseUrl(str_replace('/api/0', '/_allauth/browser/v1', self::API))->acceptJson()->timeout(15);
        $csrf = (string) ($this->cookies($base->get('/auth/session'))['csrftoken'] ?? '');

        $response = $base->withHeaders(['Cookie' => 'csrftoken='.$csrf, 'X-CSRFToken' => $csrf, 'Referer' => $this->url()])
            ->post('/auth/login', ['email' => $login['email'], 'password' => $login['password']]);
        $cookies = $this->cookies($response);

        return $response->successful() && isset($cookies['sessionid']) ? $cookies + ['csrftoken' => $csrf] : null;
    }

    /**
     * The cookies a GlitchTip answer sets, from its Set-Cookie headers.
     *
     * @return array<string, string>
     */
    private function cookies(Response $response): array
    {
        $cookies = [];

        foreach ($response->headers()['Set-Cookie'] ?? $response->headers()['set-cookie'] ?? [] as $header) {
            [$pair] = explode(';', (string) $header, 2);
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $cookies[$name] = $value;
        }

        return $cookies;
    }

    /**
     * Install (or update) GlitchTip, keep its API token and admin, and make the organization and
     * team the websites' projects go in.
     */
    public function install(User $user): string
    {
        $result = $this->host->glitchtipInstall((string) config('app.url'), self::VERSION, (string) $user->email);
        Setting::putValue('glitchtip.token', $result['token']);
        Setting::putValue('glitchtip.email', $result['email'] ?? $user->email);

        if ($result['password'] !== null) {
            Setting::putValue('glitchtip.password', $result['password']);
        }

        $organizations = collect($this->api()->get('/organizations/')->throw()->json());

        if (! $organizations->contains('slug', self::ORGANIZATION)) {
            $this->api()->post('/organizations/', ['name' => 'Laravelify'])->throw();
        }

        $teams = collect($this->api()->get('/organizations/'.self::ORGANIZATION.'/teams/')->throw()->json());

        if (! $teams->contains('slug', self::TEAM)) {
            $this->api()->post('/organizations/'.self::ORGANIZATION.'/teams/', ['slug' => self::TEAM])->throw();
        }

        $result = $this->connectAll();

        return 'GlitchTip is ready at '.$this->url().'. '.$result['connected'].' '.str('website')->plural($result['connected']).' connected.'
            .($result['failed'] === [] ? '' : ' Could not connect: '.implode(', ', array_keys($result['failed'])).'.');
    }

    /**
     * Turn off error tracking: remove GlitchTip from the server (with every error it kept), take
     * each website's key out of its .env, and forget the projects and GlitchTip's login. The
     * Sentry SDK stays in the websites' code; without a key it sends nothing.
     */
    public function uninstall(): string
    {
        $this->host->glitchtipRemove();

        $websites = Website::query()->whereNotNull('glitchtip_project')->get();

        foreach ($websites as $website) {
            $keys = $this->envKeys($website);
            $website->forceFill([
                'glitchtip_project' => null,
                'glitchtip_project_id' => null,
                'env_content' => $keys === [] ? $website->env_content : EnvFile::set((string) $website->env_content, array_fill_keys($keys, null)),
            ])->save();
        }

        foreach (['glitchtip.token', 'glitchtip.email', 'glitchtip.password'] as $key) {
            Setting::putValue($key, null);
        }

        return 'Error tracking is off: GlitchTip and its errors are removed. '
            .($websites->isEmpty() ? '' : 'The key left the .env of '.$websites->count().' '.str('website')->plural($websites->count()).'; it stops being sent from their next deploy.');
    }

    /**
     * Connect every website that has no project yet, so each one shows in GlitchTip.
     *
     * @return array{connected: int, failed: array<string, string>} failed: website name => why
     */
    public function connectAll(): array
    {
        $connected = 0;
        $failed = [];

        foreach (Website::query()->whereNull('glitchtip_project')->orderBy('id')->get() as $website) {
            try {
                $this->connect($website);
                $connected++;
            } catch (Throwable $exception) {
                $failed[$website->name] = $exception->getMessage();
            }
        }

        return ['connected' => $connected, 'failed' => $failed];
    }

    /**
     * Give the website a project (once) and put its key in the website's .env.
     *
     * @return array{dsn: string, keys: list<string>}
     */
    public function connect(Website $website): array
    {
        if (! $this->installed()) {
            throw new RuntimeException('Turn on error tracking first.');
        }

        if ($website->glitchtip_project === null) {
            $project = $this->api()->post('/teams/'.self::ORGANIZATION.'/'.self::TEAM.'/projects/', [
                'name' => $website->name,
                'platform' => $this->platform($website),
            ])->throw()->json();
            $website->forceFill(['glitchtip_project' => $project['slug'], 'glitchtip_project_id' => (int) $project['id']])->save();
        }

        $dsn = $this->dsn($website);
        $keys = $this->envKeys($website);
        $website->update(['env_content' => EnvFile::set((string) $website->env_content, array_fill_keys($keys, $dsn))]);

        return ['dsn' => $dsn, 'keys' => $keys];
    }

    /** Delete the website's project (and its errors) from GlitchTip, when the website is deleted. */
    public function forget(Website $website): void
    {
        if ($website->glitchtip_project === null || ! $this->installed()) {
            return;
        }

        $response = $this->api()->delete('/projects/'.self::ORGANIZATION.'/'.$website->glitchtip_project.'/');

        // Already deleted in GlitchTip itself.
        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    public function dsn(Website $website): string
    {
        $keys = $this->api()->get('/projects/'.self::ORGANIZATION.'/'.$website->glitchtip_project.'/keys/')->throw()->json();

        return (string) ($keys[0]['dsn']['public'] ?? throw new RuntimeException('That project has no key.'));
    }

    /**
     * The website's open issues, newest first.
     *
     * @return list<array{id: string, title: string, culprit: string, count: int, users: int, level: string, lastSeen: ?string, url: string}>
     */
    public function issues(Website $website, int $limit = 10): array
    {
        if ($website->glitchtip_project === null || ! $this->installed()) {
            return [];
        }

        $issues = $this->api()->get('/projects/'.self::ORGANIZATION.'/'.$website->glitchtip_project.'/issues/', ['query' => 'is:unresolved', 'limit' => $limit])->throw()->json();

        return array_map(fn (array $issue) => [
            'id' => (string) $issue['id'],
            'title' => (string) ($issue['title'] ?? $issue['metadata']['value'] ?? 'Error'),
            'culprit' => (string) ($issue['culprit'] ?? ''),
            'count' => (int) ($issue['count'] ?? 0),
            'users' => (int) ($issue['userCount'] ?? 0),
            'level' => (string) ($issue['level'] ?? 'error'),
            'lastSeen' => $issue['lastSeen'] ?? null,
            'url' => $this->open(self::ORGANIZATION.'/issues/'.$issue['id']),
        ], array_slice((array) $issues, 0, $limit));
    }

    /**
     * The organization's projects (one per website), by id.
     *
     * @return array<int, string> id => name
     */
    public function projects(): array
    {
        return collect($this->api()->get('/organizations/'.self::ORGANIZATION.'/projects/')->throw()->json())
            ->mapWithKeys(fn (array $project) => [(int) $project['id'] => (string) $project['name']])
            ->sort()
            ->all();
    }

    /**
     * One page of issues, as GlitchTip's own Issues page lists them, with each issue's trend.
     *
     * @param  array{status?: string, search?: string, projects?: list<int>, sort?: string, period?: string}  $filters
     * @return array{items: list<array<string, mixed>>, next: ?string, total: ?int}
     */
    public function issuePage(array $filters, ?string $cursor = null, int $limit = 25): array
    {
        $status = in_array($filters['status'] ?? '', self::ISSUE_STATUSES, true) ? $filters['status'] : 'unresolved';
        $query = trim('is:'.$status.' '.trim((string) ($filters['search'] ?? '')));
        $response = $this->list('/organizations/'.self::ORGANIZATION.'/issues/', [
            'query' => $query,
            'project' => $filters['projects'] ?? [],
            'sort' => in_array($filters['sort'] ?? '', array_keys(self::ISSUE_SORTS), true) ? $filters['sort'] : '-last_seen',
            'limit' => $limit,
            'cursor' => $cursor,
        ]);
        $period = ($filters['period'] ?? '24h') === '14d' ? '14d' : '24h';
        $trends = $this->issueTrends(array_column($response['items'], 'id'), $period);

        $response['items'] = array_map(fn (array $issue) => [
            'id' => (int) $issue['id'],
            'shortId' => (string) ($issue['shortId'] ?? ''),
            'title' => (string) ($issue['title'] ?? $issue['metadata']['value'] ?? 'Error'),
            'culprit' => (string) ($issue['culprit'] ?: trim(($issue['metadata']['filename'] ?? '').' '.($issue['metadata']['function'] ?? ''))),
            'type' => (string) ($issue['metadata']['type'] ?? ''),
            'project' => (string) ($issue['project']['name'] ?? ''),
            'projectId' => (int) ($issue['project']['id'] ?? 0),
            'count' => (int) ($issue['count'] ?? 0),
            'users' => (int) ($issue['userCount'] ?? 0),
            'level' => (string) ($issue['level'] ?? 'error'),
            'status' => (string) ($issue['status'] ?? 'unresolved'),
            'firstSeen' => $issue['firstSeen'] ?? null,
            'lastSeen' => $issue['lastSeen'] ?? null,
            'release' => $issue['lastRelease']['shortVersion'] ?? null,
            'trend' => $trends[(int) $issue['id']] ?? [],
        ], $response['items']);

        return $response;
    }

    /**
     * Events per hour (24h) or per day (14d) of each issue, oldest first, with empty buckets filled in.
     *
     * @param  list<int|string>  $ids
     * @return array<int, list<int>>
     */
    public function issueTrends(array $ids, string $period = '24h'): array
    {
        if ($ids === []) {
            return [];
        }

        $stats = $this->api()->get('/organizations/'.self::ORGANIZATION.'/issues-stats/?'.$this->query(['groups' => $ids, 'statsPeriod' => $period]))->throw()->json();
        [$buckets, $size] = $period === '14d' ? [14, 86400] : [24, 3600];
        $end = intdiv(time(), $size) * $size;
        $trends = [];

        foreach ((array) $stats as $issue) {
            $points = array_fill(0, $buckets, 0);

            foreach ($issue['stats'][$period] ?? [] as [$at, $count]) {
                $index = $buckets - 1 - intdiv($end - intdiv((int) $at, $size) * $size, $size);

                if ($index >= 0 && $index < $buckets) {
                    $points[$index] += (int) $count;
                }
            }

            $trends[(int) $issue['id']] = $points;
        }

        return $trends;
    }

    /**
     * The project of each of these issues, whatever their status.
     *
     * @param  list<int>  $ids
     * @return array<int, int> issue id => project id
     */
    public function issueProjects(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return collect($this->list('/organizations/'.self::ORGANIZATION.'/issues/', ['id' => $ids, 'limit' => 100])['items'])
            ->mapWithKeys(fn (array $issue) => [(int) $issue['id'] => (int) ($issue['project']['id'] ?? 0)])
            ->all();
    }

    /**
     * Resolve, ignore, or reopen issues.
     *
     * @param  list<int>  $ids
     */
    public function setIssueStatus(array $ids, string $status): void
    {
        if ($ids === [] || ! in_array($status, self::ISSUE_STATUSES, true)) {
            return;
        }

        $this->api()->put('/organizations/'.self::ORGANIZATION.'/issues/?'.$this->query(['id' => $ids]), ['status' => $status])->throw();
    }

    /**
     * One issue with its latest event: the stack trace, breadcrumbs, request, and tags.
     *
     * @return array<string, mixed>
     */
    public function issueDetail(int $id): array
    {
        $issue = $this->api()->get('/organizations/'.self::ORGANIZATION.'/issues/'.$id.'/')->throw()->json();
        $event = $this->api()->get('/organizations/'.self::ORGANIZATION.'/issues/'.$id.'/events/latest/')->json() ?? [];
        $entries = collect($event['entries'] ?? [])->keyBy('type');

        $exceptions = array_map(fn (array $exception) => [
            'type' => (string) ($exception['type'] ?? ''),
            'value' => (string) ($exception['value'] ?? ''),
            'frames' => array_reverse(array_map(fn (array $frame) => [
                'file' => (string) ($frame['filename'] ?? $frame['absPath'] ?? $frame['module'] ?? '?'),
                'function' => (string) ($frame['function'] ?? ''),
                'line' => $frame['lineNo'] ?? null,
                'inApp' => (bool) ($frame['inApp'] ?? false),
                'context' => array_map(fn ($line) => ['line' => (int) $line[0], 'code' => (string) $line[1]], $frame['context'] ?? []),
            ], $exception['stacktrace']['frames'] ?? [])),
        ], $entries['exception']['data']['values'] ?? []);

        $request = $entries['request']['data'] ?? null;

        return [
            'id' => (int) $issue['id'],
            'shortId' => (string) ($issue['shortId'] ?? ''),
            'title' => (string) ($issue['title'] ?? 'Error'),
            'culprit' => (string) ($issue['culprit'] ?? ''),
            'level' => (string) ($issue['level'] ?? 'error'),
            'status' => (string) ($issue['status'] ?? 'unresolved'),
            'project' => (string) ($issue['project']['name'] ?? ''),
            'projectId' => (int) ($issue['project']['id'] ?? 0),
            'count' => (int) ($issue['count'] ?? 0),
            'users' => (int) ($issue['userCount'] ?? 0),
            'firstSeen' => $issue['firstSeen'] ?? null,
            'lastSeen' => $issue['lastSeen'] ?? null,
            'firstRelease' => $issue['firstRelease']['shortVersion'] ?? null,
            'lastRelease' => $issue['lastRelease']['shortVersion'] ?? null,
            'eventAt' => $event['dateCreated'] ?? null,
            'message' => (string) ($event['message'] ?? ''),
            'exceptions' => $exceptions,
            'breadcrumbs' => array_map(fn (array $crumb) => [
                'at' => $crumb['timestamp'] ?? null,
                'category' => (string) ($crumb['category'] ?? $crumb['type'] ?? ''),
                'message' => (string) ($crumb['message'] ?? json_encode($crumb['data'] ?? [])),
                'level' => (string) ($crumb['level'] ?? 'info'),
            ], array_slice($entries['breadcrumbs']['data']['values'] ?? [], -30)),
            'request' => $request === null ? null : [
                'method' => (string) ($request['method'] ?? ''),
                'url' => (string) ($request['url'] ?? ''),
            ],
            'tags' => collect($event['tags'] ?? [])->mapWithKeys(fn (array $tag) => [(string) $tag['key'] => (string) $tag['value']])->all(),
            'url' => $this->open(self::ORGANIZATION.'/issues/'.$issue['id']),
        ];
    }

    /**
     * One page of transaction groups (Performance): each route or job, how often it ran and how long it took.
     *
     * @param  array{search?: string, projects?: list<int>, sort?: string, period?: string}  $filters
     * @return array{items: list<array<string, mixed>>, next: ?string, total: ?int}
     */
    public function transactionPage(array $filters, ?string $cursor = null, int $limit = 25): array
    {
        $response = $this->list('/organizations/'.self::ORGANIZATION.'/transaction-groups/', [
            'query' => trim((string) ($filters['search'] ?? '')) ?: null,
            'project' => $filters['projects'] ?? [],
            'sort' => in_array($filters['sort'] ?? '', array_keys(self::TRANSACTION_SORTS), true) ? $filters['sort'] : '-avg_duration',
            'start' => $this->since($filters['period'] ?? '24h'),
            'limit' => $limit,
            'cursor' => $cursor,
        ]);

        $response['items'] = array_map(fn (array $group) => [
            'id' => (int) $group['id'],
            'name' => (string) $group['transaction'],
            'op' => (string) ($group['op'] ?? ''),
            'method' => (string) ($group['method'] ?? ''),
            'projectId' => (int) ($group['project'] ?? 0),
            'count' => (int) ($group['count'] ?? 0),
            'avg' => (float) ($group['avgDuration'] ?? 0),
            'p50' => isset($group['p50']) ? (float) $group['p50'] : null,
            'p95' => isset($group['p95']) ? (float) $group['p95'] : null,
            'errors' => (int) ($group['errorCount'] ?? 0),
            'errorRate' => (float) ($group['errorRate'] ?? 0),
            'lastSeen' => $group['lastSeen'] ?? null,
        ], $response['items']);

        return $response;
    }

    /**
     * Where one transaction spends its time (span groups) and how its speed changed (trend).
     *
     * @return array{group: array<string, mixed>, spans: list<array<string, mixed>>, trend: list<array<string, mixed>>}
     */
    public function transactionDetail(int $id, string $period = '24h'): array
    {
        $base = '/organizations/'.self::ORGANIZATION.'/transaction-groups/'.$id.'/';
        $range = $this->query(['start' => $this->since($period)]);
        $group = $this->api()->get($base)->throw()->json();

        return [
            'group' => [
                'id' => (int) $group['id'],
                'name' => (string) $group['transaction'],
                'op' => (string) ($group['op'] ?? ''),
                'method' => (string) ($group['method'] ?? ''),
                'projectId' => (int) ($group['project'] ?? 0),
                'count' => (int) ($group['count'] ?? 0),
                'avg' => (float) ($group['avgDuration'] ?? 0),
                'p50' => isset($group['p50']) ? (float) $group['p50'] : null,
                'p95' => isset($group['p95']) ? (float) $group['p95'] : null,
                'errorRate' => (float) ($group['errorRate'] ?? 0),
            ],
            'spans' => array_map(fn (array $span) => [
                'op' => (string) $span['op'],
                'description' => (string) $span['description'],
                'count' => (int) $span['count'],
                'avg' => (float) $span['avgDuration'],
                'p95' => (float) $span['p95Duration'],
                'total' => (float) $span['totalTime'],
            ], (array) $this->api()->get($base.'spans/?'.$range)->json()),
            'trend' => array_map(fn (array $point) => [
                'at' => $point['date'],
                'count' => (int) $point['transactionCount'],
                'avg' => (float) $point['avgDuration'],
            ], (array) $this->api()->get($base.'trend/?'.$range)->json()),
        ];
    }

    /**
     * One page of log lines, newest first.
     *
     * @param  array{search?: string, projects?: list<int>, levels?: list<string>, period?: string}  $filters
     * @return array{items: list<array<string, mixed>>, next: ?string, total: ?int}
     */
    public function logPage(array $filters, ?string $cursor = null, int $limit = 50): array
    {
        $response = $this->list('/organizations/'.self::ORGANIZATION.'/logs/', [
            'query' => trim((string) ($filters['search'] ?? '')) ?: null,
            'project' => $filters['projects'] ?? [],
            'level' => array_values(array_intersect($filters['levels'] ?? [], self::LOG_LEVELS)),
            'start' => $this->since($filters['period'] ?? '24h'),
            'limit' => $limit,
            'cursor' => $cursor,
        ]);

        $response['items'] = array_map(fn (array $log) => [
            'id' => (string) $log['id'],
            'at' => $log['timestamp'],
            'level' => (string) $log['level'],
            'body' => (string) $log['body'],
            'service' => (string) ($log['service'] ?? ''),
            'environment' => (string) ($log['environment'] ?? ''),
            'host' => (string) ($log['host'] ?? ''),
            'traceId' => $log['traceID'] ?? null,
            'projectId' => (int) ($log['projectId'] ?? 0),
            'data' => (array) ($log['data'] ?? []),
        ], $response['items']);

        return $response;
    }

    /**
     * Log lines per level over time, for the chart above the logs.
     *
     * @param  array{projects?: list<int>, period?: string}  $filters
     * @return array{intervals: list<string>, series: array<string, list<int>>}
     */
    public function logStats(array $filters): array
    {
        $stats = $this->api()->get('/organizations/'.self::ORGANIZATION.'/logs/stats/?'.$this->query([
            'project' => $filters['projects'] ?? [],
            'start' => $this->since($filters['period'] ?? '24h'),
        ]))->throw()->json();

        return [
            'intervals' => (array) ($stats['intervals'] ?? []),
            'series' => collect($stats['series'] ?? [])->mapWithKeys(fn (array $series) => [(string) $series['name'] => array_map('intval', $series['data'])])->all(),
        ];
    }

    /**
     * A GET of a paginated list: its rows, the cursor of the next page (from the Link header), and
     * the total GlitchTip counted (X-Hits) when it says.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{items: list<array<string, mixed>>, next: ?string, total: ?int}
     */
    private function list(string $path, array $parameters): array
    {
        $response = $this->api()->get($path.'?'.$this->query($parameters))->throw();
        preg_match('/rel="next"; results="true"; cursor="([^"]+)"/', (string) $response->header('Link'), $next);

        return [
            'items' => array_values((array) $response->json()),
            'next' => $next[1] ?? null,
            'total' => $response->header('X-Hits') !== '' ? (int) $response->header('X-Hits') : null,
        ];
    }

    /**
     * A query string as GlitchTip reads it: a list repeats its name (project=1&project=2), empty values are left out.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function query(array $parameters): string
    {
        $pairs = [];

        foreach ($parameters as $name => $value) {
            foreach ((array) $value as $item) {
                if ($item !== null && $item !== '') {
                    $pairs[] = rawurlencode($name).'='.rawurlencode((string) $item);
                }
            }
        }

        return implode('&', $pairs);
    }

    /** The start of a time range, as GlitchTip's relative dates (now-24h). */
    private function since(string $period): string
    {
        return 'now-'.(array_key_exists($period, self::PERIODS) ? $period : '24h');
    }

    /** The website's issues in GlitchTip. */
    public function projectUrl(Website $website): string
    {
        return $this->open(self::ORGANIZATION.'/issues?project='.$website->glitchtip_project_id);
    }

    /**
     * The .env names the website's SDK reads its key from: the server's, and the browser's when the
     * build puts it in the page (VITE_, NEXT_PUBLIC_, NUXT_PUBLIC_, PUBLIC_).
     *
     * @return list<string>
     */
    public function envKeys(Website $website): array
    {
        return match ($website->type) {
            'laravel' => ['SENTRY_LARAVEL_DSN', 'VITE_SENTRY_DSN'],
            'nextjs' => ['SENTRY_DSN', 'NEXT_PUBLIC_SENTRY_DSN'],
            'nuxt' => ['SENTRY_DSN', 'NUXT_PUBLIC_SENTRY_DSN'],
            'sveltekit' => ['SENTRY_DSN', 'PUBLIC_SENTRY_DSN'],
            'astro' => ['PUBLIC_SENTRY_DSN'],
            'react', 'vue', 'svelte' => ['VITE_SENTRY_DSN'],
            'angular', 'static' => [],
            default => ['SENTRY_DSN'],
        };
    }

    /**
     * Whether the website's code has a Sentry SDK: in composer.json (PHP), package.json (Node and
     * built front ends), or index.html (plain HTML, the CDN script). Null before the first deploy.
     */
    public function sdkFound(Website $website): ?bool
    {
        $root = rtrim((string) $website->deploy_path, '/');
        $folder = trim((string) $website->public_dir, '/');
        [$file, $needle] = match ($website->type) {
            'laravel', 'php' => ['composer.json', '"sentry/'],
            'static' => [($folder !== '' && $folder !== '.' ? $folder.'/' : '').'index.html', 'Sentry.init'],
            'spring' => [is_file($root.'/pom.xml') ? 'pom.xml' : (is_file($root.'/build.gradle.kts') ? 'build.gradle.kts' : 'build.gradle'), 'io.sentry'],
            'aspnet' => [basename((string) (glob($root.'/*.csproj')[0] ?? 'project.csproj')), 'Sentry.AspNetCore'],
            'django', 'flask', 'fastapi' => ['requirements.txt', 'sentry-sdk'],
            'go' => ['go.mod', 'getsentry/sentry-go'],
            default => ['package.json', '"@sentry/'],
        };

        if (! is_file($root.'/'.$file) || ! is_readable($root.'/'.$file)) {
            return null;
        }

        return str_contains((string) file_get_contents($root.'/'.$file), $needle);
    }

    /** Which setup guide the website page shows. */
    public function guide(Website $website): string
    {
        return match ($website->type) {
            'laravel' => 'laravel',
            'php' => 'php',
            'nextjs' => 'nextjs',
            'nuxt' => 'nuxt',
            'sveltekit' => 'sveltekit',
            'react', 'vue', 'svelte', 'astro' => 'vite',
            'angular' => 'angular',
            'static', 'hugo', 'gatsby', 'docusaurus', 'vitepress', 'eleventy' => 'browser',
            'spring' => 'java',
            'aspnet' => 'dotnet',
            'django', 'flask', 'fastapi' => 'python',
            'go' => 'go',
            'bun' => 'bun',
            default => 'node',
        };
    }

    private function platform(Website $website): string
    {
        return match ($website->type) {
            'laravel' => 'php-laravel',
            'php' => 'php',
            'nextjs' => 'javascript-nextjs',
            'react' => 'javascript-react',
            'vue', 'nuxt' => 'javascript-vue',
            'angular' => 'javascript-angular',
            'svelte', 'sveltekit' => 'javascript-svelte',
            'astro', 'static', 'hugo', 'gatsby', 'docusaurus', 'vitepress', 'eleventy' => 'javascript',
            'spring' => 'java-spring-boot',
            'aspnet' => 'dotnet-aspnetcore',
            'django' => 'python-django',
            'flask' => 'python-flask',
            'fastapi' => 'python-fastapi',
            'go' => 'go',
            'bun' => 'bun',
            default => 'node',
        };
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl(self::API)->withToken((string) Setting::getValue('glitchtip.token'))->acceptJson()->asJson()->timeout(20);
    }
}
