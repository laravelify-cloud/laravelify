<?php

namespace App\Jobs;

use App\Models\BackupSchedule;
use App\Services\BackupManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunBackupSchedule implements ShouldQueue
{
    use Queueable;

    public int $timeout = 14400;

    public int $tries = 1;

    public function __construct(public int $scheduleId, public string $trigger = 'schedule') {}

    public function handle(BackupManager $backups): void
    {
        $schedule = BackupSchedule::query()->with('storage')->find($this->scheduleId);

        if ($schedule) {
            $backups->run($schedule, $this->trigger);
        }
    }
}
