<?php

namespace App\Services;

use App\Models\Heartbeat;

/**
 * Cron and scheduled-task heartbeats: a ping marks the job alive, and a job that
 * misses its interval alerts once, then once more when it reports again.
 */
class HeartbeatMonitor
{
    public function __construct(private Telegram $telegram) {}

    /**
     * Record a ping. Tells Telegram when a late job reports again.
     */
    public function ping(Heartbeat $heartbeat): void
    {
        $wasLate = $heartbeat->late_since !== null;
        $lateSince = $heartbeat->late_since;

        $heartbeat->forceFill(['last_ping_at' => now(), 'late_since' => null])->save();

        if ($wasLate) {
            $this->telegram->notify('heartbeat_missed', '✅ Laravelify: <b>'.Telegram::escape($heartbeat->name).'</b> on <b>'.Telegram::escape($heartbeat->website->name).'</b> reported again'."\n\n"
                .'It was silent for '.$lateSince->diffForHumans(now(), syntax: true).'.', $this->buttons($heartbeat));
        }
    }

    /**
     * Mark heartbeats that missed their interval, alerting once each.
     *
     * @return list<string>
     */
    public function check(): array
    {
        $report = [];

        foreach (Heartbeat::query()->with('website')->whereNull('late_since')->get() as $heartbeat) {
            if (! $heartbeat->isLate()) {
                continue;
            }

            $heartbeat->forceFill(['late_since' => $heartbeat->dueAt()])->save();

            $html = '💔 Laravelify: <b>'.Telegram::escape($heartbeat->name).'</b> on <b>'.Telegram::escape($heartbeat->website->name).'</b> missed its heartbeat'."\n\n"
                .'📊 Details:'."\n"
                .'• Expected: '.Heartbeat::INTERVALS[$heartbeat->interval_minutes]."\n"
                .'• Last ping: '.($heartbeat->last_ping_at ? app(DisplayTime::class)->datetime($heartbeat->last_ping_at) : 'never')."\n\n"
                .'The cron job or scheduled task may have stopped.';

            $this->telegram->notify('heartbeat_missed', $html, $this->buttons($heartbeat));
            $report[] = $heartbeat->website->name.': heartbeat "'.$heartbeat->name.'" missed, alerted';
        }

        return $report;
    }

    /**
     * @return list<array{text: string, url: string}>
     */
    private function buttons(Heartbeat $heartbeat): array
    {
        return [['text' => 'Open heartbeats', 'url' => route('websites.show', ['website' => $heartbeat->website, 'tab' => 'monitoring', 'view' => 'heartbeats'])]];
    }
}
