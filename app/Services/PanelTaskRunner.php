<?php

namespace App\Services;

use App\Jobs\RunPanelTask;
use App\Models\PanelTask;
use App\Models\Website;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Starts background tasks and runs them in the queue worker.
 */
class PanelTaskRunner
{
    public function __construct(
        private FileOperations $files,
        private SitePaths $paths,
        private BackupInspector $backups,
    ) {}

    /**
     * Save a task and send it to the queue.
     *
     * @param  array<string, mixed>  $payload
     */
    public function start(string $kind, string $scope, string $label, array $payload): PanelTask
    {
        $task = PanelTask::query()->create([
            'kind' => $kind,
            'scope' => $scope,
            'label' => Str::limit($label, 180),
            'payload' => $payload,
        ]);

        RunPanelTask::dispatch($task->id);

        return $task->refresh();
    }

    public function run(PanelTask $task): void
    {
        $task->forceFill(['status' => PanelTask::RUNNING, 'started_at' => now(), 'progress' => 0])->save();
        $progress = fn (int $done, int $total) => $task->report((int) floor($done / max(1, $total) * 100));

        try {
            $result = $this->handle($task, $progress);
            $task->forceFill([
                'status' => PanelTask::SUCCESS,
                'progress' => 100,
                'result' => $result,
                'message' => $result['message'] ?? null,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $task->forceFill([
                'status' => PanelTask::FAILED,
                'message' => Str::limit($exception->getMessage(), 500),
                'finished_at' => now(),
            ])->save();
        }
    }

    /**
     * The folder a file manager task works in, from its scope. Never taken from the payload.
     */
    public function rootFor(string $scope): string
    {
        if ($scope === 'files:global') {
            return $this->paths->globalRoot();
        }

        if (preg_match('/^files:website:(\d+)$/', $scope, $match)) {
            $website = Website::query()->find((int) $match[1]) ?? throw new RuntimeException('That website no longer exists.');

            return $this->paths->root($website);
        }

        throw new RuntimeException('Unknown task scope.');
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(PanelTask $task, callable $progress): array
    {
        $payload = $task->payload ?? [];

        if (str_starts_with($task->kind, 'files.')) {
            $root = $this->rootFor($task->scope);
            $relatives = array_values(array_map('strval', (array) ($payload['relatives'] ?? [])));

            return match ($task->kind) {
                'files.compress' => ['path' => $path = $this->files->compress($root, (string) ($payload['directory'] ?? ''), $relatives, $progress, array_values(array_map('strval', (array) ($payload['skip'] ?? []))), isset($payload['name']) ? (string) $payload['name'] : null), 'message' => 'Created '.basename($path).'.'],
                'files.extract' => ['path' => $path = $this->files->extract($root, $relatives[0] ?? '', $progress), 'message' => 'Extracted to '.basename($path).'.'],
                'files.copy' => ['message' => $this->countLabel($this->files->copy($root, $relatives, (string) ($payload['destination'] ?? ''), $progress)).' copied to /'.($payload['destination'] ?? '').'.'],
                'files.delete' => ['message' => $this->countLabel($this->files->delete($root, $relatives, $progress)).' deleted.'],
                'files.download' => $this->prepareDownload($task, $root, $relatives[0] ?? '', $progress),
                default => throw new RuntimeException('Unknown task.'),
            };
        }

        return match ($task->kind) {
            'backup.inspect' => $this->backups->inspectTask($task, $progress),
            'backup.export' => $this->backups->exportTask($task, $progress),
            default => throw new RuntimeException('Unknown task.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareDownload(PanelTask $task, string $root, string $relative, callable $progress): array
    {
        $file = 'task-'.$task->id.'-'.Str::random(12).'.zip';
        $name = $this->files->zipForDownload($root, $relative, PanelTask::directory().DIRECTORY_SEPARATOR.$file, $task->scope !== 'files:global', $progress);

        return ['file' => $file, 'download_name' => $name, 'message' => $name.' is ready to download.'];
    }

    private function countLabel(int $count): string
    {
        return $count === 1 ? '1 item' : $count.' items';
    }
}
