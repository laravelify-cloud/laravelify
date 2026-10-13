<?php

namespace App\Console\Commands;

use App\Models\BackupSchedule;
use App\Services\BackupManager;
use App\Services\DisplayTime;
use App\Services\Telegram;
use App\Support\BackupCron;
use Illuminate\Console\Command;
use Throwable;

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

    public function handle(BackupManager $backups, DisplayTime $time, Telegram $telegram): int
    {
        $timezone = $time->settings()['timezone'] ?? config('app.timezone');
        $queued = 0;

        BackupSchedule::query()->where('enabled', true)->each(function (BackupSchedule $schedule) use ($backups, $timezone, $telegram, &$queued) {
            $ranThisMinute = $schedule->last_run_at && $schedule->last_run_at->diffInSeconds(now()) < 55;

            if ($ranThisMinute || ! BackupCron::isDue($schedule->cron, $timezone)) {
                return;
            }

            try {
                $queued += $backups->queue($schedule, 'schedule') ? 1 : 0;
            } catch (Throwable $exception) {
                // For example MySQL being down while the list of databases is read.
                $this->error($schedule->name.': '.$exception->getMessage());
                $telegram->notify('backup_failed', '❌ <b>Backup could not start</b>'."\n".Telegram::escape($schedule->name)."\n".Telegram::escape($exception->getMessage()));
            }
        });

        $this->info($queued.' backup schedule(s) queued.');

        return self::SUCCESS;
    }
}
