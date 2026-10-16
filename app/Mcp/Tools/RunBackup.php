<?php

namespace App\Mcp\Tools;

use App\Models\BackupSchedule;
use App\Services\BackupManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Description('Start a backup now: one schedule (schedule_id from list-backups), or a full backup of every database or every website (full: "database" or "files"). It runs in the background; list-backups shows its status. Needs a backup storage set up in the panel (Backups → Backup storage): an S3 bucket or a folder on this server.')]
class RunBackup extends Tool
{
    public function handle(Request $request, BackupManager $backups): Response
    {
        $full = (string) $request->get('full', '');

        if ($full !== '') {
            if (! in_array($full, [BackupSchedule::KIND_DATABASE, BackupSchedule::KIND_FILES], true)) {
                throw ValidationException::withMessages(['full' => 'Use "database" or "files".']);
            }

            try {
                $schedule = $backups->fullBackup($full);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['full' => $exception->getMessage()]);
            }
        } else {
            $schedule = BackupSchedule::query()->with('storage')->find((int) $request->get('schedule_id'))
                ?? throw ValidationException::withMessages(['schedule_id' => 'Give a schedule_id (list-backups) or full: "database" / "files".']);

            if ($schedule->storage === null) {
                throw ValidationException::withMessages(['schedule_id' => 'This backup has no storage. Add one in the panel under Backups → Backup storage.']);
            }

            if (! $backups->queue($schedule)) {
                throw ValidationException::withMessages(['schedule_id' => $schedule->name.' is already running. Check list-backups.']);
            }
        }

        return Response::json(['started' => $schedule->name, 'kind' => $schedule->kind, 'next' => 'It runs in the background. Call list-backups to see its status.']);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'schedule_id' => $schema->integer()->description('The schedule to run (list-backups).'),
            'full' => $schema->string()->description('"database" or "files": a full backup of everything, with no schedule needed.'),
        ];
    }
}
