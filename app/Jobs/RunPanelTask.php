<?php

namespace App\Jobs;

use App\Models\PanelTask;
use App\Services\PanelTaskRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunPanelTask implements ShouldQueue
{
    use Queueable;

    public int $timeout = 14400;

    public int $tries = 1;

    public function __construct(public int $taskId) {}

    public function handle(PanelTaskRunner $runner): void
    {
        $task = PanelTask::query()->find($this->taskId);

        if ($task && $task->status === PanelTask::QUEUED) {
            $runner->run($task);
        }
    }
}
