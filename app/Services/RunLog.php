<?php

namespace App\Services;

use App\Models\BackupRun;

/**
 * Appends lines to a backup run's log as they happen, so the page can show progress.
 */
class RunLog
{
    public function __construct(private BackupRun $run) {}

    public function line(string $text): void
    {
        $this->run->forceFill(['log' => trim(($this->run->log ?? '')."\n[".now()->format('H:i:s').'] '.$text)])->save();
    }
}
