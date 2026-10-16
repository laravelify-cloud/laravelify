<?php

namespace App\Services;

use App\Jobs\RunBackupSchedule;
use App\Jobs\RunRestore;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\BackupStorage;
use App\Support\BackupCron;
use App\Support\BackupExcludes;
use App\Support\SkippedTables;
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
        private BackupInspector $inspector,
    ) {}

    /**
     * Queue a schedule to run now. One run per database (or one for the folders) is listed
     * right away as waiting, so the page shows it before the queue worker picks it up.
     * Returns false while the same schedule is still running.
     */
    public function queue(BackupSchedule $schedule, string $trigger = 'manual'): bool
    {
        if ($this->isRunning($schedule)) {
            return false;
        }

        $schedule->forceFill(['last_run_at' => now()])->save();
        $runs = $this->prepareRuns($schedule, $trigger);

        if ($runs === []) {
            $this->notifyNothingToDo($schedule);

            return true;
        }

        RunBackupSchedule::dispatch($schedule->id, $trigger, array_map(fn (BackupRun $run) => $run->id, $runs));

        return true;
    }

    /**
     * Back up everything now: every database, or every website (each in its own archive,
     * without dependencies and build output). Uses a "Full backup" schedule that only runs
     * by hand, made the first time, so the backups are listed and kept like any other.
     */
    /**
     * @param  list<string>|null  $skipTables  For databases: tables to leave out ("db.table", "*.table"),
     *                                         kept on the schedule for the next full backup. Null keeps the saved list.
     * @param  list<string>|null  $skipDatabases  For databases: whole databases to leave out, kept the same way.
     *                                            Databases added later are backed up.
     */
    public function fullBackup(string $kind, ?array $skipTables = null, ?array $skipDatabases = null): BackupSchedule
    {
        $isDatabase = $kind === BackupSchedule::KIND_DATABASE;
        $schedule = BackupSchedule::query()->where('kind', $kind)->where('name', 'Full backup')->first();

        if (! $schedule) {
            $storage = BackupStorage::query()->where('is_default', true)->first() ?? BackupStorage::query()->first()
                ?? throw new RuntimeException('Add a backup storage first (an S3 bucket or a folder on this server).');

            $schedule = BackupSchedule::query()->create([
                'name' => 'Full backup',
                'kind' => $kind,
                'backup_storage_id' => $storage->id,
                'frequency' => 'manual',
                'interval' => 1,
                'run_time' => '00:00',
                'weekday' => 0,
                'cron' => BackupCron::NEVER,
                'targets' => $isDatabase ? ['all' => true, 'databases' => []] : ['websites' => true, 'paths' => [], 'excludes' => []],
                'retention_count' => 5,
            ]);
        }

        if ($isDatabase && $skipTables !== null) {
            $schedule->targets = [...($schedule->targets ?? []), 'skip_tables' => SkippedTables::normalize($skipTables)];
            $schedule->save();
        }

        if ($isDatabase && $skipDatabases !== null) {
            $skip = array_values(array_unique(array_filter($skipDatabases, fn ($name): bool => is_string($name) && DatabaseDumper::validName($name))));
            $schedule->targets = [...($schedule->targets ?? []), 'skip_databases' => $skip];
            $schedule->save();
        }

        if (! $this->queue($schedule->load('storage'), 'manual')) {
            throw new RuntimeException('A full backup is already running.');
        }

        return $schedule;
    }

    /**
     * Back up some databases now (the Database tool's Back up button), each in its own dump, through
     * a by-hand schedule named "Database tool" so the backups are listed and kept like any other.
     *
     * @param  list<string>  $databases
     */
    public function backupDatabases(array $databases): BackupSchedule
    {
        $databases = array_values(array_filter($databases, fn (string $name): bool => DatabaseDumper::validName($name)));
        $storage = BackupStorage::query()->where('is_default', true)->first() ?? BackupStorage::query()->first()
            ?? throw new RuntimeException('Add a backup storage first (Backups → Backup storage): an S3 bucket or a folder on this server.');

        $schedule = BackupSchedule::query()->firstOrCreate(
            ['kind' => BackupSchedule::KIND_DATABASE, 'name' => 'Database tool'],
            ['backup_storage_id' => $storage->id, 'frequency' => 'manual', 'interval' => 1, 'run_time' => '00:00', 'weekday' => 0, 'cron' => BackupCron::NEVER, 'targets' => ['all' => false, 'databases' => []], 'retention_count' => 10],
        );

        $schedule->targets = [...($schedule->targets ?? []), 'all' => false, 'databases' => $databases];
        $schedule->save();

        if (! $this->queue($schedule->load('storage'), 'manual')) {
            throw new RuntimeException('A backup from the Database tool is already running. Try again when it is done.');
        }

        return $schedule;
    }

    /**
     * A waiting run for every target of the schedule. The runs of one go share a batch id, so the
     * list shows them together (a full backup, then each website in it).
     *
     * @return list<BackupRun>
     */
    public function prepareRuns(BackupSchedule $schedule, string $trigger): array
    {
        $batch = (string) Str::uuid();

        return array_map(fn (array $target) => BackupRun::query()->create([
            'backup_schedule_id' => $schedule->id,
            'backup_storage_id' => $schedule->backup_storage_id,
            'batch' => $batch,
            'type' => BackupRun::TYPE_BACKUP,
            'kind' => $schedule->kind,
            'target' => $target['label'],
            'status' => BackupRun::QUEUED,
            'trigger' => $trigger,
        ]), $this->targets($schedule));
    }

    /**
     * Mark the waiting runs of a schedule as cancelled, for when the queue worker is not running.
     */
    public function cancelWaiting(BackupSchedule $schedule): int
    {
        return $schedule->runs()->where('status', BackupRun::QUEUED)->update([
            'status' => BackupRun::FAILED,
            'log' => 'Cancelled before it started.',
            'finished_at' => now(),
        ]);
    }

    public function isRunning(BackupSchedule $schedule): bool
    {
        return $schedule->runs()
            ->whereIn('status', [BackupRun::QUEUED, BackupRun::RUNNING])
            ->where('created_at', '>', now()->subHours(6))
            ->exists();
    }

    /**
     * Back up every target of the schedule, one after the other. $runIds are the waiting runs
     * made by queue(); without them, new runs are made here.
     *
     * @param  list<int>|null  $runIds
     * @return list<BackupRun>
     */
    public function run(BackupSchedule $schedule, string $trigger, ?array $runIds = null): array
    {
        $waiting = $runIds === null
            ? $this->prepareRuns($schedule, $trigger)
            : BackupRun::query()->whereIn('id', $runIds)->where('status', BackupRun::QUEUED)->orderBy('id')->get()->all();

        if ($waiting === [] && $runIds === null) {
            $this->notifyNothingToDo($schedule);
        }

        $folders = $schedule->isDatabase() ? [] : collect($this->targets($schedule))->keyBy('label')->all();

        $done = array_map(fn (BackupRun $run) => $this->execute(
            $schedule,
            $run,
            $schedule->isDatabase()
                ? ['label' => $run->target, 'database' => $run->target, 'skip' => SkippedTables::forDatabase($schedule->skippedTables(), $run->target)]
                : ($folders[$run->target] ?? ['label' => $run->target, 'paths' => [], 'excludes' => []]),
        ), $waiting);

        $this->notifyFinished($schedule, $done);

        return $done;
    }

    /**
     * One Telegram message for a whole run of a schedule, with a download link for each backup
     * that works for a day.
     *
     * @param  list<BackupRun>  $runs
     */
    private function notifyFinished(BackupSchedule $schedule, array $runs): void
    {
        $succeeded = array_values(array_filter($runs, fn (BackupRun $run) => $run->status === BackupRun::SUCCESS));

        if ($succeeded === [] || ! $this->telegram->enabled('backup_succeeded')) {
            return;
        }

        $lines = [];
        $buttons = [];

        foreach ($succeeded as $run) {
            $url = $run->storage && ! $run->storage->isLocal() ? $this->storage->temporaryUrl($run->storage, (string) $run->object_key, self::LINK_SECONDS) : null;
            $name = Telegram::escape($run->target);
            $lines[] = '• '.($url ? '<a href="'.htmlspecialchars($url, ENT_QUOTES).'">'.$name.'</a>' : $name).' · '.$run->sizeLabel();

            if ($url && count($succeeded) === 1) {
                $buttons[] = ['text' => 'Download backup', 'url' => $url];
            }
        }

        $total = count($runs);
        $title = count($succeeded) === $total ? 'Backup finished' : 'Backup finished, '.($total - count($succeeded)).' of '.$total.' failed';

        $this->telegram->notify(
            'backup_succeeded',
            '💾 <b>'.$title.'</b>'."\n".Telegram::escape($schedule->name)."\n\n".implode("\n", array_slice($lines, 0, 40))."\n\n".'<i>Download links work for 24 hours.</i>',
            $buttons,
        );
    }

    /** How long the download links in Telegram work. */
    public const LINK_SECONDS = 86400;

    private function notifyNothingToDo(BackupSchedule $schedule): void
    {
        $this->telegram->notify('backup_failed', '⚠️ <b>Backup skipped</b>'."\n".Telegram::escape($schedule->name).': nothing to back up. Choose a database or folder.');
    }

    /**
     * @return list<array{label: string, database?: string, paths?: list<string>, excludes?: list<string>}>
     */
    public function targets(BackupSchedule $schedule): array
    {
        $targets = $schedule->targets ?? [];

        if ($schedule->isDatabase()) {
            $names = ! empty($targets['all']) ? $this->databases->databases() : array_values($targets['databases'] ?? []);
            $names = array_values(array_diff($names, (array) ($targets['skip_databases'] ?? [])));

            return array_map(fn (string $name) => ['label' => $name, 'database' => $name, 'skip' => SkippedTables::forDatabase($schedule->skippedTables(), $name)], $names);
        }

        // Every website, each in its own archive, without what a deploy rebuilds.
        if (! empty($targets['websites'])) {
            $extra = array_values($targets['excludes'] ?? []);

            return array_map(fn (array $site) => [
                'label' => $site['folder'],
                'paths' => [$site['folder']],
                'excludes' => array_values(array_unique([...$site['excludes'], ...$extra])),
            ], BackupExcludes::websites($this->files->root()));
        }

        $paths = array_values($targets['paths'] ?? []);

        if ($paths === []) {
            return [];
        }

        $excludes = array_values($targets['excludes'] ?? []);

        // "Everything" is one archive of the whole folder.
        if (in_array('', $paths, true)) {
            return [['label' => '(everything)', 'paths' => [''], 'excludes' => $excludes]];
        }

        // One archive per folder (a website, or a folder in it), so each restores and downloads on its own.
        return array_map(fn (string $path) => [
            'label' => $path,
            'paths' => [$path],
            'excludes' => $excludes,
        ], $paths);
    }

    /**
     * @param  array{label: string, database?: string, paths?: list<string>, excludes?: list<string>}  $target
     */
    private function execute(BackupSchedule $schedule, BackupRun $run, array $target): BackupRun
    {
        $log = new RunLog($run);
        $run->forceFill(['status' => BackupRun::RUNNING, 'started_at' => now(), 'phase' => 'Starting', 'progress' => null])->save();
        $phase = fn (string $text, ?int $percent = null) => $run->forceFill(['phase' => $text, 'progress' => $percent])->save();
        $workDir = $this->workDir();
        $extension = $schedule->isDatabase() ? 'sql.gz' : 'tar.gz';
        $file = $workDir.'/'.Str::random(12).'.'.$extension;

        try {
            $storage = $schedule->storage ?? throw new RuntimeException('This schedule has no storage. Choose a storage and save it.');
            $meta = [];

            if ($schedule->isDatabase()) {
                $phase('Dumping the database');
                $skip = array_values($target['skip'] ?? []);
                $log->line('Dumping database '.$target['database'].'...');

                if ($skip !== []) {
                    $log->line('Skipping '.count($skip).' table(s): '.implode(', ', $skip).'.');
                }

                $this->databases->dump($target['database'], $file, $skip);
                $meta['tables'] = array_values(array_diff($this->databases->tables($target['database']), $skip));
                $meta['skipped_tables'] = $skip;
                $log->line('Dumped '.count($meta['tables']).' tables.');
            } else {
                $phase('Packing the files');
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
            $run->forceFill(['size_bytes' => $size])->save();
            $phase('Uploading to '.$storage->name, 0);
            $savedAt = 0.0;
            $this->storage->upload($storage, $file, $key, function (int $percent) use ($phase, $storage, &$savedAt): void {
                if (microtime(true) - $savedAt >= 1 || $percent >= 100) {
                    $savedAt = microtime(true);
                    $phase('Uploading to '.$storage->name, $percent);
                }
            });

            $run->forceFill([
                'status' => BackupRun::SUCCESS,
                'phase' => null,
                'progress' => null,
                'object_key' => $key,
                'size_bytes' => $size,
                'meta' => $meta,
                'finished_at' => now(),
            ])->save();
            $log->line('Done. '.$key);

            try {
                $this->inspector->remember($run, $file);
            } catch (Throwable $exception) {
                $log->line('Saved, but the list of what is inside could not be read: '.$exception->getMessage());
            }

            $this->prune($schedule, $run, $log);
        } catch (Throwable $exception) {
            $log->line('FAILED: '.$exception->getMessage());
            $run->forceFill(['status' => BackupRun::FAILED, 'phase' => null, 'progress' => null, 'finished_at' => now()])->save();
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

        $this->inspector->forget($copy);
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
                $members = array_values(array_filter(array_map('strval', (array) ($options['members'] ?? (empty($options['member']) ? [] : [$options['member']])))));
                $folder = $this->files->restore($file, $members, (bool) ($options['original'] ?? false));
                $log->line('Restored '.($members === [] ? 'everything' : implode(', ', $members)).' into '.$folder.'.');
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
