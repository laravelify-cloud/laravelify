<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Services\BackupManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunRestore implements ShouldQueue
{
    use Queueable;

    public int $timeout = 14400;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(BackupManager $backups): void
    {
        $restore = BackupRun::query()->with(['source.storage'])->find($this->runId);

        if ($restore && $restore->status === BackupRun::QUEUED) {
            $backups->restore($restore);
        }
    }
}
