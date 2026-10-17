<?php

use App\Services\TerminalSessions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Uptime checks and Telegram alerts: websites, workers, and disk every minute; apt patches once a day.
Schedule::command('laravelify:monitor')->everyMinute()->withoutOverlapping(10);
Schedule::command('laravelify:monitor --patches')->dailyAt('06:00')->withoutOverlapping(30);

// Background tasks (zips, backup exports) and their files are removed after a day.
Schedule::command('laravelify:prune-tasks')->hourly()->withoutOverlapping(10);

// Interactive terminal sessions (their output and input files) are forgotten after a day.
Schedule::call(fn () => app(TerminalSessions::class)->prune())->name('terminal-prune')->hourly();

// Backups: starts every schedule that is due this minute (Backups in the sidebar).
Schedule::command('laravelify:run-backups')->everyMinute()->withoutOverlapping(5);

// Monitoring tab: new requests and Laravel errors every minute, old data pruned nightly.
Schedule::command('laravelify:collect')->everyMinute()->withoutOverlapping(10);
Schedule::command('laravelify:sample-server')->everyMinute()->withoutOverlapping(5);
Schedule::command('laravelify:prune-monitoring')->dailyAt('03:30')->withoutOverlapping(60);
