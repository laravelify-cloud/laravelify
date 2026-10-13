<?php

namespace App\Console\Commands;

use App\Models\BackupSchedule;
use App\Services\BackupManager;
use App\Services\DisplayTime;
use App\Support\BackupCron;
use Illuminate\Console\Command;

class RunBackups extends Command
{
    /**
     * @var string
     */
    protected $signature = 'laravelify:run-backups';

    /**
     * @var string
     */
    protected $description = 'Queue every backup schedule that is due this minute';

    public function handle(BackupManager $backups, DisplayTime $time): int
    {
        $timezone = $time->settings()['timezone'] ?? config('app.timezone');
        $queued = 0;

        BackupSchedule::query()->where('enabled', true)->each(function (BackupSchedule $schedule) use ($backups, $timezone, &$queued) {
            $ranThisMinute = $schedule->last_run_at && $schedule->last_run_at->diffInSeconds(now()) < 55;

            if (! $ranThisMinute && BackupCron::isDue($schedule->cron, $timezone) && $backups->queue($schedule, 'schedule')) {
                $queued++;
            }
        });

        $this->info($queued.' backup schedule(s) queued.');

        return self::SUCCESS;
    }
}
