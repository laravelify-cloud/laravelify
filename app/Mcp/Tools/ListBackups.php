<?php

namespace App\Mcp\Tools;

use App\Models\BackupRun;
use App\Models\BackupSchedule;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The backup schedules (database and folder backups to the S3 storage) with when they last ran, and the latest backups with their status and size. Run one now with run-backup.')]
class ListBackups extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::json([
            'schedules' => BackupSchedule::query()->with('storage')->orderBy('name')->get()->map(fn (BackupSchedule $schedule) => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'kind' => $schedule->kind,
                'enabled' => (bool) $schedule->enabled,
                'when' => $schedule->frequency,
                'storage' => $schedule->storage?->name,
                'keeps' => $schedule->retention_count,
                'last_run_at' => $schedule->last_run_at?->toIso8601String(),
            ])->all(),
            'latest_runs' => BackupRun::query()->latest('id')->limit(15)->get()->map(fn (BackupRun $run) => [
                'id' => $run->id,
                'type' => $run->type,
                'kind' => $run->kind,
                'target' => $run->target,
                'status' => $run->status,
                'size' => $run->isBackup() ? $run->sizeLabel() : null,
                'at' => $run->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
