<?php

namespace App\Services;

use App\Jobs\RunBackupSchedule;
use App\Jobs\RunRestore;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Runs backup schedules (one dump or archive per database or folder set), keeps only the
 * newest copies, and restores from a copy. Every step is written to the run's log.
 */
class BackupManager
{
    public function __construct(
        private BackupStorageClient $storage,
        private DatabaseDumper $databases,
        private FileArchiver $files,
        private Telegram $telegram,
    ) {}

    /**
     * Queue a schedule to run now. Returns false while the same schedule is still running.
     */
    public function queue(BackupSchedule $schedule, string $trigger = 'manual'): bool
    {
        if ($this->isRunning($schedule)) {
            return false;
        }

        $schedule->forceFill(['last_run_at' => now()])->save();
        RunBackupSchedule::dispatch($schedule->id, $trigger);

        return true;
    }

    public function isRunning(BackupSchedule $schedule): bool
    {
        return $schedule->runs()
            ->whereIn('status', [BackupRun::QUEUED, BackupRun::RUNNING])
            ->where('created_at', '>', now()->subHours(6))
            ->exists();
    }

    /**
     * Back up every target of the schedule, one after the other.
     *
     * @return list<BackupRun>
     */
    public function run(BackupSchedule $schedule, string $trigger): array
    {
        $storage = $schedule->storage;
        $runs = [];

        foreach ($this->targets($schedule) as $target) {
            $run = BackupRun::query()->create([
                'backup_schedule_id' => $schedule->id,
                'backup_storage_id' => $storage?->id,
                'type' => BackupRun::TYPE_BACKUP,
                'kind' => $schedule->kind,
                'target' => $target['label'],
                'status' => BackupRun::QUEUED,
                'trigger' => $trigger,
            ]);

            $runs[] = $this->execute($schedule, $run, $target);
        }

        if ($runs === []) {
            $this->telegram->notify('backup_failed', '⚠️ <b>Backup skipped</b>'."\n".Telegram::escape($schedule->name).': nothing to back up. Choose a database or folder.');
        }

        return $runs;
    }

    /**
     * @return list<array{label: string, database?: string, paths?: list<string>, excludes?: list<string>}>
     */
    public function targets(BackupSchedule $schedule): array
    {
        $targets = $schedule->targets ?? [];

        if ($schedule->isDatabase()) {
            $names = ! empty($targets['all']) ? $this->databases->databases() : array_values($targets['databases'] ?? []);

            return array_map(fn (string $name) => ['label' => $name, 'database' => $name], $names);
        }

        $paths = array_values($targets['paths'] ?? []);

        if ($paths === []) {
            return [];
        }

        return [[
            'label' => implode(', ', array_map(fn (string $path) => $path === '' ? '(everything)' : $path, $paths)),
            'paths' => $paths,
            'excludes' => array_values($targets['excludes'] ?? []),
        ]];
    }

    /**
     * @param  array{label: string, database?: string, paths?: list<string>, excludes?: list<string>}  $target
     */
    private function execute(BackupSchedule $schedule, BackupRun $run, array $target): BackupRun
    {
        $log = new RunLog($run);
        $run->forceFill(['status' => BackupRun::RUNNING, 'started_at' => now()])->save();
        $workDir = $this->workDir();
        $extension = $schedule->isDatabase() ? 'sql.gz' : 'tar.gz';
        $file = $workDir.'/'.Str::random(12).'.'.$extension;

        try {
            $storage = $schedule->storage ?? throw new RuntimeException('This schedule has no storage. Choose an S3 storage and save it.');
            $meta = [];

            if ($schedule->isDatabase()) {
                $log->line('Dumping database '.$target['database'].'...');
                $this->databases->dump($target['database'], $file);
                $meta['tables'] = $this->databases->tables($target['database']);
                $log->line('Dumped '.count($meta['tables']).' tables.');
            } else {
                $log->line('Archiving '.$target['label'].'...');
                $archive = $this->files->archive($target['paths'], $target['excludes'], $file);
                $meta['entries'] = $archive['entries'];
                $meta['files'] = $archive['files'];
                $meta['excludes'] = $target['excludes'];
                $meta['paths'] = $target['paths'];

                if ($archive['warning']) {
                    $log->line($archive['warning']);
                }

                $log->line('Archived '.$archive['files'].' files.');
            }

            $key = $this->objectKey($schedule, $target['label'], $extension);
            $size = is_file($file) ? (int) filesize($file) : 0;
            $log->line('Uploading '.Number::fileSize($size, precision: 1).' to '.$storage->name.'...');
            $this->storage->upload($storage, $file, $key);

            $run->forceFill([
                'status' => BackupRun::SUCCESS,
                'object_key' => $key,
                'size_bytes' => $size,
                'meta' => $meta,
                'finished_at' => now(),
            ])->save();
            $log->line('Done. '.$key);

            $this->prune($schedule, $run, $log);
            $this->telegram->notify('backup_succeeded', '💾 <b>Backup finished</b>'."\n".Telegram::escape($schedule->name).' · '.Telegram::escape($target['label']).' · '.$run->sizeLabel());
        } catch (Throwable $exception) {
            $log->line('FAILED: '.$exception->getMessage());
            $run->forceFill(['status' => BackupRun::FAILED, 'finished_at' => now()])->save();
            $this->telegram->notify('backup_failed', '❌ <b>Backup failed</b>'."\n".Telegram::escape($schedule->name).' · '.Telegram::escape($target['label'])."\n".Telegram::escape(Str::limit($exception->getMessage(), 300)));
        } finally {
            @unlink($file);
        }

        return $run->refresh();
    }

    /**
     * Delete the copies of this target beyond the schedule's limits. The newest copy always stays.
     */
    private function prune(BackupSchedule $schedule, BackupRun $latest, RunLog $log): void
    {
        $copies = BackupRun::query()
            ->where('backup_schedule_id', $schedule->id)
            ->where('type', BackupRun::TYPE_BACKUP)
            ->where('target', $latest->target)
            ->where('status', BackupRun::SUCCESS)
            ->whereNotNull('object_key')
            ->orderByDesc('id')
            ->get();

        $expired = $copies->slice(max(1, $schedule->retention_count));

        if ($schedule->retention_days) {
            $cutoff = now()->subDays($schedule->retention_days);
            $expired = $expired->merge($copies->slice(1)->filter(fn (BackupRun $copy) => $copy->created_at < $cutoff))->unique('id');
        }

        foreach ($expired as $copy) {
            try {
                $this->deleteCopy($copy);
                $log->line('Removed the old backup from '.$copy->created_at->toDateTimeString().'.');
            } catch (Throwable $exception) {
                $log->line('Could not remove an old backup: '.$exception->getMessage());
            }
        }
    }

    /**
     * Remove the file from the bucket and the run from the list.
     */
    public function deleteCopy(BackupRun $copy): void
    {
        if ($copy->object_key && $copy->storage) {
            $this->storage->delete($copy->storage, $copy->object_key);
        }

        $copy->delete();
    }

    /**
     * Queue a restore of a backup. $options: database, tables (list), member (folder), original (bool).
     *
     * @param  array<string, mixed>  $options
     */
    public function queueRestore(BackupRun $source, array $options): BackupRun
    {
        if (! $source->restorable()) {
            throw new RuntimeException('That backup is not available to restore.');
        }

        $restore = BackupRun::query()->create([
            'backup_schedule_id' => $source->backup_schedule_id,
            'backup_storage_id' => $source->backup_storage_id,
            'source_run_id' => $source->id,
            'type' => BackupRun::TYPE_RESTORE,
            'kind' => $source->kind,
            'target' => $source->target,
            'status' => BackupRun::QUEUED,
            'trigger' => 'manual',
            'meta' => $options,
        ]);

        RunRestore::dispatch($restore->id);

        return $restore;
    }

    public function restore(BackupRun $restore): BackupRun
    {
        $log = new RunLog($restore);
        $restore->forceFill(['status' => BackupRun::RUNNING, 'started_at' => now()])->save();
        $source = $restore->source;
        $options = $restore->meta ?? [];
        $file = $this->workDir().'/'.Str::random(12).($restore->isDatabase() ? '.sql.gz' : '.tar.gz');

        try {
            if (! $source || ! $source->restorable()) {
                throw new RuntimeException('The backup is no longer available.');
            }

            $log->line('Downloading '.$source->object_key.'...');
            $this->storage->download($source->storage, $source->object_key, $file);

            if ($restore->isDatabase()) {
                $database = (string) ($options['database'] ?? $source->target);
                $tables = ! empty($options['tables']) ? array_values($options['tables']) : null;
                $log->line($tables === null ? 'Restoring the whole database into '.$database.'...' : 'Restoring '.implode(', ', $tables).' into '.$database.'...');
                $this->databases->restore($file, $database, $tables);
            } else {
                $member = ! empty($options['member']) ? (string) $options['member'] : null;
                $folder = $this->files->restore($file, $member, (bool) ($options['original'] ?? false));
                $log->line('Restored '.($member ?? 'everything').' into '.$folder.'.');
            }

            $restore->forceFill(['status' => BackupRun::SUCCESS, 'finished_at' => now()])->save();
            $log->line('Done.');
        } catch (Throwable $exception) {
            $log->line('FAILED: '.$exception->getMessage());
            $restore->forceFill(['status' => BackupRun::FAILED, 'finished_at' => now()])->save();
            $this->telegram->notify('backup_failed', '❌ <b>Restore failed</b>'."\n".Telegram::escape($restore->target)."\n".Telegram::escape(Str::limit($exception->getMessage(), 300)));
        } finally {
            @unlink($file);
        }

        return $restore->refresh();
    }

    /**
     * laravelify/databases/app_db/20261013-020000-ab12cd.sql.gz
     */
    public function objectKey(BackupSchedule $schedule, string $label, string $extension): string
    {
        $kind = $schedule->isDatabase() ? 'databases' : 'files';
        $name = Str::slug($schedule->isDatabase() ? $label : $schedule->name, '_') ?: 'backup';

        return $kind.'/'.$name.'/'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.'.$extension;
    }

    private function workDir(): string
    {
        $directory = storage_path('app/backup-work');
        File::ensureDirectoryExists($directory);

        return $directory;
    }
}
