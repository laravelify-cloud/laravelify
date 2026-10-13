<?php

namespace App\Services;

use App\Models\RequestMinute;
use App\Models\SiteRequest;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads the JSON request log nginx writes per site (see SiteSecurity::metricsLog)
 * into site_requests and per-minute totals. Successful static files (js, css,
 * images, fonts) are skipped, like an app monitor would not see them.
 */
class RequestCollector
{
    private const MAX_BYTES = 16 * 1024 * 1024;

    private const STATIC_FILE = '/\.(css|js|mjs|map|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|eot|otf|mp4|webm|mp3|wav|pdf|txt|xml|webmanifest)$/i';

    public function __construct(
        private LogTailer $tailer,
        private ThreatDetector $threats,
    ) {}

    /**
     * @return int How many requests were stored.
     */
    public function collect(Website $website): int
    {
        $read = $this->tailer->read($website, 'requests', SiteSecurity::metricsLog($website), self::MAX_BYTES);

        if ($read === null) {
            return 0;
        }

        $rows = [];

        foreach (explode("\n", $read['text']) as $line) {
            $row = $this->parse($website, $line);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        DB::transaction(function () use ($website, $rows, $read): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                SiteRequest::query()->insert($chunk);
            }

            $this->addMinutes($website, $rows);
            $this->tailer->commit($read);
        });

        return count($rows);
    }

    /**
     * One log line to a site_requests row, or null for noise.
     *
     * @return array<string, mixed>|null
     */
    public function parse(Website $website, string $line): ?array
    {
        $data = json_decode(trim($line), true);

        if (! is_array($data) || ! is_numeric($data['t'] ?? null) || ! is_numeric($data['s'] ?? null)) {
            return null;
        }

        $uri = mb_substr((string) ($data['u'] ?? '/'), 0, 500) ?: '/';
        $path = explode('?', $uri, 2)[0] ?: '/';
        $status = (int) $data['s'];
        $agent = $this->clean($data['ua'] ?? null, 255);
        $threat = $this->threats->inspect($uri, $agent);

        if ($status < 400 && $threat['risk'] === 0 && (preg_match(self::STATIC_FILE, $path) === 1 || str_starts_with($path, '/_next/static/'))) {
            return null;
        }

        $country = strtoupper((string) ($data['c'] ?? ''));

        return [
            'website_id' => $website->id,
            'at' => Carbon::createFromTimestamp((int) floor((float) $data['t']), config('app.timezone'))->format('Y-m-d H:i:s'),
            'method' => mb_substr(strtoupper((string) ($data['m'] ?? '')), 0, 10) ?: '-',
            'uri' => $uri,
            'route' => self::route($path),
            'status' => max(0, min($status, 999)),
            'duration_ms' => (int) round(max(0, (float) ($data['d'] ?? 0)) * 1000),
            'bytes' => max(0, min((int) ($data['b'] ?? 0), 4294967295)),
            'ip' => mb_substr((string) ($data['ip'] ?? ''), 0, 45),
            'country' => preg_match('/^[A-Z]{2}$/', $country) === 1 && $country !== 'XX' ? $country : null,
            'user_agent' => $agent,
            'referer' => $this->clean($data['r'] ?? null, 255),
            'threat' => $threat['threat'],
            'risk' => $threat['risk'],
        ];
    }

    /**
     * Group URLs that differ only by IDs: /orders/123/items/5 => /orders/{id}/items/{id}.
     */
    public static function route(string $path): string
    {
        $segments = array_map(function (string $segment): string {
            return match (true) {
                $segment === '' => '',
                ctype_digit($segment) => '{id}',
                preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1 => '{uuid}',
                preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $segment) === 1 => '{ulid}',
                preg_match('/^[0-9a-f]{16,}$/i', $segment) === 1 => '{hash}',
                strlen($segment) >= 24 && preg_match('/\d/', $segment) === 1 && preg_match('/^[A-Za-z0-9_-]+$/', $segment) === 1 => '{token}',
                default => $segment,
            };
        }, explode('/', $path));

        return mb_substr(implode('/', $segments), 0, 255) ?: '/';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function addMinutes(Website $website, array $rows): void
    {
        $minutes = [];

        foreach ($rows as $row) {
            $key = substr($row['at'], 0, 16).':00';
            $minute = $minutes[$key] ?? ['requests' => 0, 'client_errors' => 0, 'server_errors' => 0, 'duration_sum' => 0, 'duration_max' => 0, 'histogram' => LatencyHistogram::empty()];
            $minute['requests']++;
            $minute['client_errors'] += $row['status'] >= 400 && $row['status'] < 500 ? 1 : 0;
            $minute['server_errors'] += $row['status'] >= 500 ? 1 : 0;
            $minute['duration_sum'] += $row['duration_ms'];
            $minute['duration_max'] = max($minute['duration_max'], $row['duration_ms']);
            $minute['histogram'] = LatencyHistogram::add($minute['histogram'], $row['duration_ms']);
            $minutes[$key] = $minute;
        }

        if ($minutes === []) {
            return;
        }

        $existing = RequestMinute::query()
            ->where('website_id', $website->id)
            ->whereIn('minute', array_keys($minutes))
            ->get()
            ->keyBy(fn (RequestMinute $minute): string => $minute->minute->format('Y-m-d H:i:s'));

        foreach ($minutes as $key => $minute) {
            $row = $existing->get($key) ?? new RequestMinute(['website_id' => $website->id, 'minute' => $key, 'histogram' => LatencyHistogram::empty()]);
            $row->requests = (int) $row->requests + $minute['requests'];
            $row->client_errors = (int) $row->client_errors + $minute['client_errors'];
            $row->server_errors = (int) $row->server_errors + $minute['server_errors'];
            $row->duration_sum = (int) $row->duration_sum + $minute['duration_sum'];
            $row->duration_max = max((int) $row->duration_max, $minute['duration_max']);
            $row->histogram = LatencyHistogram::merge($row->histogram, $minute['histogram']);
            $row->save();
        }
    }

    private function clean(mixed $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '-' ? null : mb_substr($value, 0, $length);
    }
}
