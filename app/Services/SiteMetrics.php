<?php

namespace App\Services;

use App\Models\RequestMinute;
use App\Models\SiteRequest;
use App\Models\UptimeCheck;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Numbers and chart series for the Monitoring pages.
 */
class SiteMetrics
{
    /**
     * Range => [label, minutes, minutes per chart bar].
     *
     * @var array<string, array{string, int, int}>
     */
    public const RANGES = [
        '1h' => ['1H', 60, 1],
        '24h' => ['24H', 1440, 30],
        '7d' => ['7D', 10080, 180],
        '14d' => ['14D', 20160, 360],
        '30d' => ['30D', 43200, 720],
    ];

    public static function range(string $range): string
    {
        return array_key_exists($range, self::RANGES) ? $range : '24h';
    }

    public static function since(string $range): Carbon
    {
        return now()->subMinutes(self::RANGES[self::range($range)][1]);
    }

    /**
     * Totals and chart bars for requests and response times.
     *
     * @return array{summary: array<string, int|float|null>, buckets: list<array<string, mixed>>}
     */
    public function traffic(Website $website, string $range): array
    {
        return $this->cached('traffic', $website, $range, fn (): array => $this->buildTraffic($website, $range));
    }

    /**
     * @return array{summary: array<string, int|float|null>, buckets: list<array<string, mixed>>}
     */
    private function buildTraffic(Website $website, string $range): array
    {
        [$starts, $bucketSeconds] = $this->buckets($range);
        $from = Carbon::createFromTimestamp($starts[0], config('app.timezone'));

        $minutes = RequestMinute::query()
            ->where('website_id', $website->id)
            ->where('minute', '>=', $from)
            ->orderBy('minute')
            ->get();

        $buckets = array_map(fn (int $start): array => [
            'start' => $start,
            'requests' => 0, 'client_errors' => 0, 'server_errors' => 0,
            'duration_sum' => 0, 'duration_max' => 0, 'histogram' => null,
        ], $starts);

        foreach ($minutes as $minute) {
            $index = intdiv($minute->minute->getTimestamp() - $starts[0], $bucketSeconds);

            if (! isset($buckets[$index])) {
                continue;
            }

            $buckets[$index]['requests'] += $minute->requests;
            $buckets[$index]['client_errors'] += $minute->client_errors;
            $buckets[$index]['server_errors'] += $minute->server_errors;
            $buckets[$index]['duration_sum'] += $minute->duration_sum;
            $buckets[$index]['duration_max'] = max($buckets[$index]['duration_max'], $minute->duration_max);
            $buckets[$index]['histogram'] = LatencyHistogram::merge($buckets[$index]['histogram'], $minute->histogram);
        }

        $all = null;

        foreach ($buckets as $bucket) {
            $all = LatencyHistogram::merge($all, $bucket['histogram']);
        }

        $requests = (int) $minutes->sum('requests');
        $max = (int) $minutes->max('duration_max');

        $series = array_map(fn (array $bucket): array => [
            'label' => $this->label($bucket['start'], $range),
            'requests' => $bucket['requests'],
            'ok' => $bucket['requests'] - $bucket['client_errors'] - $bucket['server_errors'],
            'client_errors' => $bucket['client_errors'],
            'server_errors' => $bucket['server_errors'],
            'avg' => $bucket['requests'] > 0 ? (int) round($bucket['duration_sum'] / $bucket['requests']) : null,
            'p95' => LatencyHistogram::percentile($bucket['histogram'], 0.95, $bucket['duration_max']),
        ], $buckets);

        return [
            'summary' => [
                'requests' => $requests,
                'ok' => $requests - (int) $minutes->sum('client_errors') - (int) $minutes->sum('server_errors'),
                'client_errors' => (int) $minutes->sum('client_errors'),
                'server_errors' => (int) $minutes->sum('server_errors'),
                'avg' => $requests > 0 ? (int) round($minutes->sum('duration_sum') / $requests) : null,
                'p50' => LatencyHistogram::percentile($all, 0.5, $max),
                'p95' => LatencyHistogram::percentile($all, 0.95, $max),
                'max' => $requests > 0 ? $max : null,
                'error_rate' => $requests > 0 ? round($minutes->sum('server_errors') / $requests * 100, 2) : null,
            ],
            'buckets' => $series,
        ];
    }

    /**
     * @return array{latest: ?UptimeCheck, percent: ?float, checks: int, buckets: list<array{label: string, state: string}>}
     */
    public function uptime(Website $website, string $range): array
    {
        return $this->cached('uptime', $website, $range, fn (): array => $this->buildUptime($website, $range));
    }

    /**
     * @return array{latest: ?UptimeCheck, percent: ?float, checks: int, buckets: list<array{label: string, state: string}>}
     */
    private function buildUptime(Website $website, string $range): array
    {
        [$starts, $bucketSeconds] = $this->buckets($range);

        $checks = UptimeCheck::query()
            ->where('website_id', $website->id)
            ->where('checked_at', '>=', Carbon::createFromTimestamp($starts[0], config('app.timezone')))
            ->get(['ok', 'checked_at']);

        $states = array_fill(0, count($starts), ['up' => 0, 'down' => 0]);

        foreach ($checks as $check) {
            $index = intdiv($check->checked_at->getTimestamp() - $starts[0], $bucketSeconds);

            if (isset($states[$index])) {
                $states[$index][$check->ok ? 'up' : 'down']++;
            }
        }

        return [
            'latest' => UptimeCheck::query()->where('website_id', $website->id)->latest('checked_at')->latest('id')->first(),
            'percent' => $checks->isNotEmpty() ? round($checks->where('ok', true)->count() / $checks->count() * 100, 2) : null,
            'checks' => $checks->count(),
            'buckets' => array_map(fn (int $start, array $state): array => [
                'label' => $this->label($start, $range),
                'state' => match (true) {
                    $state['up'] + $state['down'] === 0 => 'none',
                    $state['down'] === 0 => 'up',
                    $state['up'] === 0 => 'down',
                    default => 'partial',
                },
            ], $starts, $states),
        ];
    }

    /**
     * Busiest routes with timing and error counts.
     *
     * @return Collection<int, SiteRequest>
     */
    public function routes(Website $website, string $range, int $limit = 12): Collection
    {
        return SiteRequest::query()
            ->where('website_id', $website->id)
            ->where('at', '>=', self::since($range))
            ->selectRaw('method, route, count(*) as hits, avg(duration_ms) as avg_ms, max(duration_ms) as max_ms')
            ->selectRaw('sum(case when status >= 500 then 1 else 0 end) as server_errors')
            ->selectRaw('sum(case when status >= 400 and status < 500 then 1 else 0 end) as client_errors')
            ->groupBy('method', 'route')
            ->orderByDesc('hits')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, SiteRequest>
     */
    public function countries(Website $website, string $range, int $limit = 8): Collection
    {
        return SiteRequest::query()
            ->where('website_id', $website->id)
            ->where('at', '>=', self::since($range))
            ->whereNotNull('country')
            ->selectRaw('country, count(*) as hits')
            ->groupBy('country')
            ->orderByDesc('hits')
            ->limit($limit)
            ->get();
    }

    /**
     * IPs behind flagged requests, riskiest first.
     *
     * @return Collection<int, SiteRequest>
     */
    public function attackers(Website $website, string $range, int $limit = 15): Collection
    {
        $attackers = SiteRequest::query()
            ->where('website_id', $website->id)
            ->where('at', '>=', self::since($range))
            ->where('risk', '>', 0)
            ->selectRaw('ip, count(*) as hits, max(risk) as risk, max(at) as last_at, max(country) as country')
            ->groupBy('ip')
            ->orderByDesc('risk')
            ->orderByDesc('hits')
            ->limit($limit)
            ->get();

        $types = SiteRequest::query()
            ->where('website_id', $website->id)
            ->where('at', '>=', self::since($range))
            ->whereIn('ip', $attackers->pluck('ip'))
            ->whereNotNull('threat')
            ->distinct()
            ->get(['ip', 'threat'])
            ->groupBy('ip');

        return $attackers->each(function (SiteRequest $attacker) use ($types): void {
            $attacker->setAttribute('threats', collect($types->get($attacker->ip, []))
                ->flatMap(fn (SiteRequest $row): array => explode(',', (string) $row->threat))
                ->unique()
                ->implode(','));
        });
    }

    /**
     * Flagged requests per category and per chart bar.
     *
     * @return array{total: int, categories: array<string, int>, levels: array<string, int>, buckets: list<array{label: string, count: int}>}
     */
    public function threats(Website $website, string $range): array
    {
        return $this->cached('threats', $website, $range, fn (): array => $this->buildThreats($website, $range));
    }

    /**
     * @return array{total: int, categories: array<string, int>, levels: array<string, int>, buckets: list<array{label: string, count: int}>}
     */
    private function buildThreats(Website $website, string $range): array
    {
        [$starts, $bucketSeconds] = $this->buckets($range);

        $rows = SiteRequest::query()
            ->where('website_id', $website->id)
            ->where('at', '>=', Carbon::createFromTimestamp($starts[0], config('app.timezone')))
            ->where('risk', '>', 0)
            ->limit(20000)
            ->get(['at', 'threat', 'risk']);

        $counts = array_fill(0, count($starts), 0);
        $categories = [];
        $levels = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($rows as $row) {
            $index = intdiv($row->at->getTimestamp() - $starts[0], $bucketSeconds);

            if (isset($counts[$index])) {
                $counts[$index]++;
            }

            $levels[ThreatDetector::level($row->risk)]++;

            foreach (explode(',', (string) $row->threat) as $category) {
                $categories[$category] = ($categories[$category] ?? 0) + 1;
            }
        }

        arsort($categories);

        return [
            'total' => $rows->count(),
            'categories' => $categories,
            'levels' => $levels,
            'buckets' => array_map(fn (int $start, int $count): array => ['label' => $this->label($start, $range), 'count' => $count], $starts, $counts),
        ];
    }

    /**
     * Last-24-hour numbers for every website at once (the Monitoring overview).
     *
     * @param  Collection<int, Website>  $websites
     * @return array<int, array<string, mixed>>
     */
    public function overview(Collection $websites): array
    {
        $since = now()->subDay()->startOfHour();
        $minutes = RequestMinute::query()
            ->whereIn('website_id', $websites->pluck('id'))
            ->where('minute', '>=', $since)
            ->get()
            ->groupBy('website_id');

        $checks = UptimeCheck::query()
            ->whereIn('website_id', $websites->pluck('id'))
            ->where('checked_at', '>=', now()->subDay())
            ->selectRaw('website_id, count(*) as total, sum(case when ok then 1 else 0 end) as up')
            ->groupBy('website_id')
            ->get()
            ->keyBy('website_id');

        $threats = SiteRequest::query()
            ->whereIn('website_id', $websites->pluck('id'))
            ->where('at', '>=', now()->subDay())
            ->where('risk', '>=', 65)
            ->selectRaw('website_id, count(*) as total')
            ->groupBy('website_id')
            ->pluck('total', 'website_id');

        $overview = [];

        foreach ($websites as $website) {
            /** @var Collection<int, RequestMinute> $rows */
            $rows = $minutes->get($website->id, collect());
            $requests = (int) $rows->sum('requests');
            $histogram = $rows->reduce(fn (?array $carry, RequestMinute $minute): array => LatencyHistogram::merge($carry, $minute->histogram));
            $hours = array_fill(0, 24, 0);

            foreach ($rows as $row) {
                $index = intdiv($row->minute->getTimestamp() - $since->getTimestamp(), 3600);

                if (isset($hours[$index])) {
                    $hours[$index] += $row->requests;
                }
            }

            $check = $checks->get($website->id);
            $latest = UptimeCheck::query()->where('website_id', $website->id)->latest('checked_at')->latest('id')->first();

            $overview[$website->id] = [
                'requests' => $requests,
                'server_errors' => (int) $rows->sum('server_errors'),
                'p95' => LatencyHistogram::percentile($histogram, 0.95, (int) $rows->max('duration_max')),
                'uptime' => $check && $check->total > 0 ? round($check->up / $check->total * 100, 2) : null,
                'up' => $latest?->ok,
                'checked_at' => $latest?->checked_at,
                'threats' => (int) ($threats[$website->id] ?? 0),
                'hours' => $hours,
            ];
        }

        return $overview;
    }

    /**
     * Results are kept briefly: the collector adds data once a minute, so a
     * re-render or a click between runs reuses them. Longer ranges change slower.
     *
     * @template T
     *
     * @param  callable(): T  $build
     * @return T
     */
    private function cached(string $name, Website $website, string $range, callable $build): mixed
    {
        $range = self::range($range);
        $seconds = in_array($range, ['1h', '24h'], true) ? 20 : 120;

        $display = md5((string) json_encode(app(DisplayTime::class)->settings()));

        return Cache::remember('metrics:'.$name.':'.$website->id.':'.$range.':'.$display, $seconds, $build);
    }

    /**
     * Bucket start timestamps for a range, the last one holding "now".
     *
     * @return array{list<int>, int}
     */
    private function buckets(string $range): array
    {
        [, $minutes, $perBucket] = self::RANGES[self::range($range)];
        $seconds = $perBucket * 60;
        $count = intdiv($minutes, $perBucket);
        $last = intdiv(now()->getTimestamp(), $seconds) * $seconds;

        return [array_map(fn (int $index): int => $last - ($count - 1 - $index) * $seconds, range(0, $count - 1)), $seconds];
    }

    private function label(int $timestamp, string $range): string
    {
        return app(DisplayTime::class)->label($timestamp, self::range($range) !== '1h');
    }
}
