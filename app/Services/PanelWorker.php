<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether the panel's own queue worker is alive. It runs every deploy and background task, so
 * when Supervisor gives up on it (FATAL after MySQL was down for a moment, as during an update)
 * deploys wait forever without a word. The worker beats every 30 seconds while it runs; the
 * scheduler checks the beat every minute, starts the worker again, and says so (Telegram and
 * a banner in the panel).
 */
class PanelWorker
{
    /** No beat for this long: the worker is not running. */
    public const STALE_SECONDS = 120;

    private const BEAT_KEY = 'panel-worker:beat';

    private const DOWN_KEY = 'panel-worker:down-since';

    private static int $lastBeat = 0;

    public function __construct(private CommandRunner $runner) {}

    /**
     * Called by the worker on every loop (Queue::looping); writes at most every 30 seconds.
     */
    public static function beat(): void
    {
        if (time() - self::$lastBeat < 30) {
            return;
        }

        self::$lastBeat = time();

        try {
            Cache::forever(self::BEAT_KEY, time());
        } catch (Throwable) {
            // The database is down: the worker is about to fail anyway, and the beat goes stale.
        }
    }

    public function lastBeat(): ?int
    {
        $beat = Cache::get(self::BEAT_KEY);

        return is_numeric($beat) ? (int) $beat : null;
    }

    /**
     * Running as far as the panel can tell. Where the panel runs no host commands (development,
     * tests) there is no Supervisor worker to watch.
     */
    public function running(): bool
    {
        if (! $this->runner->supportsHostCommands()) {
            return true;
        }

        $beat = $this->lastBeat();

        return $beat !== null && time() - $beat <= self::STALE_SECONDS;
    }

    /** Since when the worker is known to be down (set by the watchdog), or null. */
    public function downSince(): ?int
    {
        $since = Cache::get(self::DOWN_KEY);

        return is_numeric($since) ? (int) $since : null;
    }

    public function markDown(): bool
    {
        return Cache::add(self::DOWN_KEY, time());
    }

    public function markUp(): bool
    {
        return Cache::pull(self::DOWN_KEY) !== null;
    }
}
