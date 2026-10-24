<?php

namespace App\Livewire\Concerns;

use App\Models\PanelTask;
use App\Services\PanelTaskRunner;

/**
 * Work that takes more than a moment (Cloudflare API calls, server changes) runs as a background
 * task instead of holding the page: the page says it is running, keeps working, and shows the
 * result when it is done. The view includes livewire.partials.followed-tasks.
 */
trait FollowsTasks
{
    /** @var list<int> Tasks this page started and still waits for. */
    public array $followed = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function runInBackground(string $kind, string $scope, string $label, array $payload): PanelTask
    {
        $task = app(PanelTaskRunner::class)->start($kind, $scope, $label, $payload);
        $this->followed[] = $task->id;

        return $task;
    }

    /** Polled while tasks run: a finished one shows its result. */
    public function pollFollowedTasks(): void
    {
        $tasks = PanelTask::query()->whereKey($this->followed)->get();

        foreach ($tasks as $task) {
            if (in_array($task->status, [PanelTask::QUEUED, PanelTask::RUNNING], true)) {
                continue;
            }

            $this->followed = array_values(array_diff($this->followed, [$task->id]));
            $this->toast($task->message ?: $task->label.($task->status === PanelTask::FAILED ? ' failed.' : ' done.'), $task->status === PanelTask::FAILED ? 'warning' : 'success');
        }

        // Tasks removed meanwhile are not waited for.
        $this->followed = array_values(array_intersect($this->followed, $tasks->modelKeys()));
    }

    /**
     * @return list<array{label: string, message: ?string, queued: bool}>
     */
    public function followedTasks(): array
    {
        return PanelTask::query()->whereKey($this->followed)->get()
            ->map(fn (PanelTask $task) => ['label' => $task->label, 'message' => $task->message, 'queued' => $task->status === PanelTask::QUEUED])
            ->values()->all();
    }
}
