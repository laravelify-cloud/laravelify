<?php

namespace App\Services;

use App\Models\RequestMinute;
use App\Models\SiteIssue;
use App\Models\SiteRequest;
use App\Models\Website;
use Illuminate\Support\Facades\Cache;

/**
 * Telegram alerts from the collected data: new and returning issues, 5xx
 * spikes, slow responses, and attacks. Each has a cooldown so a bad minute
 * sends one message, not one per request.
 */
class MonitorAlerts
{
    /**
     * Minutes looked at for spikes, slowness, and attacks.
     */
    public const WINDOW = 5;

    private const ISSUES_PER_RUN = 5;

    /**
     * Requests needed before slowness counts, so one slow request is not an alert.
     */
    private const SLOW_MIN_REQUESTS = 20;

    /**
     * Risky requests from one IP in the window before it alerts.
     */
    private const ATTACK_MIN_REQUESTS = 5;

    public function __construct(private Telegram $telegram) {}

    /**
     * @param  list<array{issue: SiteIssue, kind: string}>  $changes
     * @return list<string>
     */
    public function issues(Website $website, array $changes): array
    {
        $report = [];

        foreach (array_slice($changes, 0, self::ISSUES_PER_RUN) as ['issue' => $issue, 'kind' => $kind]) {
            $headline = $kind === 'new'
                ? '🐞 Laravelify: New issue on <b>'.Telegram::escape($website->name).'</b>'
                : '♻️ Laravelify: Resolved issue came back on <b>'.Telegram::escape($website->name).'</b>';

            $html = $headline."\n\n"
                .'<b>'.Telegram::escape($issue->title()).'</b>'."\n"
                .'<code>'.Telegram::escape(mb_strimwidth($issue->message, 0, 500, '…')).'</code>'."\n\n"
                .'📊 Details:'."\n"
                .($issue->file ? '• Where: '.Telegram::escape($issue->file.':'.$issue->line)."\n" : '')
                .'• Level: '.Telegram::escape($issue->level)."\n"
                .'• Seen: '.$issue->occurrences.' '.($issue->occurrences === 1 ? 'time' : 'times');

            $this->telegram->notify('issue_new', $html, $this->buttons($website, 'issues'));
            $report[] = $website->name.': issue '.$kind.' ('.$issue->title().')';
        }

        return $report;
    }

    /**
     * @return list<string>
     */
    public function traffic(Website $website): array
    {
        $minutes = RequestMinute::query()
            ->where('website_id', $website->id)
            ->where('minute', '>=', now()->subMinutes(self::WINDOW)->startOfMinute())
            ->get();

        $requests = (int) $minutes->sum('requests');

        if ($requests === 0) {
            return [];
        }

        $report = [];
        $serverErrors = (int) $minutes->sum('server_errors');
        $threshold = max(1, (int) $website->monitor_error_threshold);

        if ($serverErrors >= $threshold && Cache::add('monitor:spike:'.$website->id, true, now()->addMinutes(30))) {
            $html = '🔥 Laravelify: Error spike on <b>'.Telegram::escape($website->name).'</b>'."\n\n"
                .'📊 Last '.self::WINDOW.' minutes:'."\n"
                .'• 5xx responses: '.number_format($serverErrors).' of '.number_format($requests).' requests'."\n"
                .'• Alert limit: '.number_format($threshold)."\n\n"
                .'Check the Issues and Logs tabs for the cause.';

            $this->telegram->notify('error_spike', $html, $this->buttons($website, 'requests'));
            $report[] = $website->name.': error spike alerted';
        }

        $histogram = $minutes->reduce(fn (?array $carry, RequestMinute $minute): array => LatencyHistogram::merge($carry, $minute->histogram));
        $p95 = LatencyHistogram::percentile($histogram, 0.95, (int) $minutes->max('duration_max'));
        $limit = max(100, (int) $website->monitor_slow_ms);

        if ($requests >= self::SLOW_MIN_REQUESTS && $p95 !== null && $p95 > $limit && Cache::add('monitor:slow:'.$website->id, true, now()->addMinutes(30))) {
            $html = '🐢 Laravelify: <b>'.Telegram::escape($website->name).'</b> is slow'."\n\n"
                .'📊 Last '.self::WINDOW.' minutes:'."\n"
                .'• p95: '.number_format($p95).' ms (limit '.number_format($limit).' ms)'."\n"
                .'• Average: '.number_format((int) round($minutes->sum('duration_sum') / $requests)).' ms'."\n"
                .'• Requests: '.number_format($requests);

            $this->telegram->notify('slow_site', $html, $this->buttons($website, 'requests'));
            $report[] = $website->name.': slow alerted';
        }

        return $report;
    }

    /**
     * @return list<string>
     */
    public function threats(Website $website): array
    {
        $attackers = SiteRequest::query()
            ->where('website_id', $website->id)
            ->where('risk', '>=', 65)
            ->where('at', '>=', now()->subMinutes(self::WINDOW))
            ->selectRaw('ip, count(*) as hits, max(risk) as risk')
            ->groupBy('ip')
            ->havingRaw('count(*) >= ?', [self::ATTACK_MIN_REQUESTS])
            ->orderByDesc('hits')
            ->limit(3)
            ->get();

        $report = [];

        foreach ($attackers as $attacker) {
            if (! Cache::add('monitor:attack:'.$website->id.':'.$attacker->ip, true, now()->addHours(6))) {
                continue;
            }

            $sample = SiteRequest::query()
                ->where('website_id', $website->id)
                ->where('ip', $attacker->ip)
                ->where('risk', '>=', 65)
                ->latest('at')
                ->first();

            $types = SiteRequest::query()
                ->where('website_id', $website->id)
                ->where('ip', $attacker->ip)
                ->where('at', '>=', now()->subMinutes(self::WINDOW))
                ->whereNotNull('threat')
                ->distinct()
                ->pluck('threat')
                ->flatMap(fn (string $threat): array => explode(',', $threat))
                ->unique()
                ->implode(',');

            $html = '🛡 Laravelify: Attack detected on <b>'.Telegram::escape($website->name).'</b>'."\n\n"
                .'📊 Last '.self::WINDOW.' minutes:'."\n"
                .'• IP: <code>'.Telegram::escape($attacker->ip).'</code>'.($sample?->country ? ' ('.$sample->country.')' : '')."\n"
                .'• Requests: '.$attacker->hits.' risky ('.ThreatDetector::level((int) $attacker->risk).')'."\n"
                .'• Types: '.Telegram::escape(ThreatDetector::label($types))."\n"
                .($sample ? '• Example: <code>'.Telegram::escape(mb_strimwidth($sample->method.' '.$sample->uri, 0, 200, '…')).'</code>' : '')."\n\n"
                .'Block the IP from the Security view of the Monitoring tab.';

            $this->telegram->notify('threat_detected', $html, $this->buttons($website, 'security'));
            $report[] = $website->name.': attack from '.$attacker->ip.' alerted';
        }

        return $report;
    }

    /**
     * @return list<array{text: string, url: string}>
     */
    private function buttons(Website $website, string $view): array
    {
        $buttons = [['text' => 'Open monitoring', 'url' => route('websites.show', ['website' => $website, 'tab' => 'monitoring', 'view' => $view])]];

        if ($website->publicUrl()) {
            $buttons[] = ['text' => 'Open website', 'url' => (string) $website->publicUrl()];
        }

        return $buttons;
    }
}
