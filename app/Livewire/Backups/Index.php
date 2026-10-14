<?php

namespace App\Livewire\Backups;

use App\Livewire\PanelComponent;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\BackupStorage;
use App\Models\PanelTask;
use App\Services\BackupInspector;
use App\Services\BackupManager;
use App\Services\FileArchiver;
use App\Services\PanelTaskRunner;
use App\Support\BackupCron;
use Illuminate\Support\Collection;
use Throwable;

class Index extends PanelComponent
{
    public const TABLES_PER_PAGE = 25;

    public const TASK_SCOPE = 'backups';

    public string $kind = BackupSchedule::KIND_DATABASE;

    /** Show only the backups of one database (or one file schedule). */
    public string $filter = '';

    /** @var list<int> Background tasks whose end was already shown. */
    public array $announced = [];

    public ?int $logRunId = null;

    public bool $restoreOpen = false;

    public int $restoreStep = 1;

    /** The database name, or the schedule id of a file backup. */
    public string $restoreTarget = '';

    public ?int $restoreRunId = null;

    public bool $restoreWhole = true;

    public string $restoreDatabase = '';

    /** @var list<string> */
    public array $restoreTables = [];

    public string $tableSearch = '';

    public int $tablePage = 1;

    /** @var list<string> Folders and files picked from the archive. */
    public array $restoreMembers = [];

    public string $archiveFolder = '';

    public bool $restoreToOriginal = false;

    public bool $understood = false;

    public ?int $viewRunId = null;

    public string $viewTab = 'tables';

    public string $viewSearch = '';

    public int $viewPage = 1;

    public string $viewItem = '';

    public ?int $downloadRunId = null;

    public string $downloadMode = 'parts';

    /** @var list<string> */
    public array $downloadParts = ['tables', 'procedures', 'functions', 'triggers', 'events'];

    public bool $downloadAllTables = true;

    /** @var list<string> */
    public array $downloadTables = [];

    public function mount(string $kind = BackupSchedule::KIND_DATABASE): void
    {
        $this->kind = $kind === BackupSchedule::KIND_FILES ? BackupSchedule::KIND_FILES : BackupSchedule::KIND_DATABASE;
        $this->announced = PanelTask::query()->where('scope', self::TASK_SCOPE)->whereIn('status', [PanelTask::SUCCESS, PanelTask::FAILED])->pluck('id')->all();
    }

    public function updatedTableSearch(): void
    {
        $this->tablePage = 1;
    }

    public function updatedViewSearch(): void
    {
        $this->viewPage = 1;
    }

    // Schedules

    public function runNow(BackupManager $backups, int $id): void
    {
        $schedule = BackupSchedule::query()->with('storage')->where('kind', $this->kind)->findOrFail($id);

        if (! $schedule->storage) {
            $this->toast('Choose an S3 storage for this schedule first (Edit).', 'error');

            return;
        }

        try {
            $started = $backups->queue($schedule, 'manual');
            $this->toast($started ? 'Backup started. Progress shows in the list below.' : 'This schedule is already running.', $started ? 'success' : 'error');
        } catch (Throwable $exception) {
            $this->toast('Could not start: '.$exception->getMessage(), 'error');
        }
    }

    /**
     * Every database, or every website, now.
     */
    public function fullBackup(BackupManager $backups): void
    {
        try {
            $schedule = $backups->fullBackup($this->kind);
            $names = $schedule->runs()->where('status', BackupRun::QUEUED)->orderBy('id')->pluck('target');
            $this->toast('Full backup started, one file each: '.$names->implode(', ').'. Progress shows in the list below.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    /**
     * @param  list<int>  $ids
     */
    public function runSchedules(BackupManager $backups, array $ids): void
    {
        $started = 0;

        foreach (BackupSchedule::query()->with('storage')->where('kind', $this->kind)->whereKey($ids)->get() as $schedule) {
            try {
                $started += $schedule->storage && $backups->queue($schedule, 'manual') ? 1 : 0;
            } catch (Throwable $exception) {
                $this->toast($schedule->name.': '.$exception->getMessage(), 'error');
            }
        }

        $this->dispatch('bulk-done');
        $this->toast($started === 0 ? 'Nothing started: the selected schedules are already running.' : 'Started '.$started.' schedule(s).');
    }

    /**
     * @param  list<int>  $ids
     */
    public function setSchedulesEnabled(array $ids, bool $enabled): void
    {
        $count = BackupSchedule::query()->where('kind', $this->kind)->whereKey($ids)->update(['enabled' => $enabled]);
        $this->dispatch('bulk-done');
        $this->toast(($enabled ? 'Resumed ' : 'Paused ').$count.' schedule(s).');
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteSchedules(array $ids): void
    {
        $count = 0;

        foreach (BackupSchedule::query()->where('kind', $this->kind)->whereKey($ids)->get() as $schedule) {
            $schedule->delete();
            $count++;
        }

        $this->dispatch('bulk-done');
        $this->toast('Deleted '.$count.' schedule(s). Their backups are kept.');
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteBackups(BackupManager $backups, array $ids): void
    {
        $deleted = 0;

        foreach (BackupRun::query()->with('storage')->where('kind', $this->kind)->where('type', BackupRun::TYPE_BACKUP)->whereKey($ids)->get() as $run) {
            try {
                $backups->deleteCopy($run);
                $deleted++;
            } catch (Throwable $exception) {
                $this->toast($run->target.': '.$exception->getMessage(), 'error');
            }
        }

        $this->dispatch('bulk-done');
        $this->toast('Deleted '.$deleted.' backup(s) from the bucket.');
    }

    public function cancelWaiting(BackupManager $backups, int $id): void
    {
        $schedule = BackupSchedule::query()->where('kind', $this->kind)->findOrFail($id);
        $count = $backups->cancelWaiting($schedule);
        $this->toast($count > 0 ? 'Cancelled '.$count.' waiting backup(s).' : 'Nothing was waiting.');
    }

    public function toggle(int $id): void
    {
        $schedule = BackupSchedule::query()->where('kind', $this->kind)->findOrFail($id);
        $schedule->update(['enabled' => ! $schedule->enabled]);
        $this->toast($schedule->enabled ? $schedule->name.' runs on schedule again.' : $schedule->name.' is paused.');
    }

    public function delete(int $id): void
    {
        BackupSchedule::query()->where('kind', $this->kind)->findOrFail($id)->delete();
        $this->toast('Schedule deleted. Backups it made stay in your bucket and in the list.');
    }

    /**
     * One click to the common setup: every database, on a schedule.
     */
    public function quickStart(string $preset): void
    {
        $storage = BackupStorage::query()->where('is_default', true)->first() ?? BackupStorage::query()->first();

        if (! $storage) {
            $this->toast('Add an S3 storage first.', 'error');

            return;
        }

        $presets = [
            'hourly' => ['name' => 'All databases, every hour', 'frequency' => 'hourly', 'run_time' => '00:00', 'retention' => 24],
            'daily' => ['name' => 'All databases, every day', 'frequency' => 'daily', 'run_time' => '02:00', 'retention' => 14],
        ];
        $choice = $presets[$preset] ?? null;

        if (! $choice) {
            return;
        }

        BackupSchedule::query()->create([
            'name' => $choice['name'],
            'kind' => BackupSchedule::KIND_DATABASE,
            'backup_storage_id' => $storage->id,
            'frequency' => $choice['frequency'],
            'interval' => 1,
            'run_time' => $choice['run_time'],
            'weekday' => 0,
            'cron' => BackupCron::build($choice['frequency'], 1, $choice['run_time'], 0),
            'targets' => ['all' => true, 'databases' => []],
            'retention_count' => $choice['retention'],
        ]);

        $this->toast('Schedule created: '.$choice['name'].'.');
    }

    // Backups

    public function showLog(int $id): void
    {
        $this->logRunId = $id;
    }

    public function deleteBackup(BackupManager $backups, int $id): void
    {
        $run = BackupRun::query()->with('storage')->where('kind', $this->kind)->where('type', BackupRun::TYPE_BACKUP)->findOrFail($id);

        try {
            $backups->deleteCopy($run);
            $this->toast('Backup deleted from the bucket.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    // Restore wizard: 1. what (database or file schedule), 2. which backup, 3. how

    public function openRestore(?int $runId = null): void
    {
        $this->resetRestore();
        $this->restoreOpen = true;

        if ($runId !== null) {
            $run = $this->restorableRun($runId);

            if (! $run) {
                return;
            }

            $this->restoreTarget = $this->targetKey($run);
            $this->chooseRestoreRun($runId);
        }
    }

    public function chooseRestoreTarget(string $target): void
    {
        $this->restoreTarget = $target;
        $this->restoreRunId = null;
        $this->restoreStep = 2;
    }

    public function chooseRestoreRun(int $id): void
    {
        $run = $this->restorableRun($id);

        if (! $run) {
            return;
        }

        $this->restoreRunId = $run->id;
        $this->restoreDatabase = $run->isDatabase() ? $run->target : '';
        $this->restoreWhole = true;
        $this->restoreTables = [];
        $this->restoreMembers = [];
        $this->archiveFolder = '';
        $this->tableSearch = '';
        $this->tablePage = 1;
        $this->understood = false;
        $this->restoreStep = 3;
        $this->resetErrorBag();

        if (! $run->isDatabase()) {
            $this->ensureManifest($run);
        }
    }

    public function restoreBack(): void
    {
        $this->restoreStep = max(1, $this->restoreStep - 1);
    }

    public function closeRestore(): void
    {
        $this->restoreOpen = false;
    }

    /**
     * Select or clear every table that matches the search.
     */
    public function selectTables(bool $select): void
    {
        $matching = $this->filterNames($this->tableNames($this->restorableRun((int) $this->restoreRunId)), $this->tableSearch);

        $this->restoreTables = $select
            ? array_values(array_unique([...$this->restoreTables, ...$matching]))
            : array_values(array_diff($this->restoreTables, $matching));
    }

    public function openArchiveFolder(string $folder): void
    {
        $this->archiveFolder = trim(FileArchiver::cleanPath($folder), '/');
    }

    public function toggleMember(string $path): void
    {
        $path = FileArchiver::cleanPath($path);
        $this->restoreMembers = in_array($path, $this->restoreMembers, true)
            ? array_values(array_diff($this->restoreMembers, [$path]))
            : [...$this->restoreMembers, $path];
    }

    public function restore(BackupManager $backups): void
    {
        $this->resetErrorBag();
        $run = $this->restorableRun((int) $this->restoreRunId);

        if (! $run) {
            return;
        }

        if ($run->isDatabase()) {
            $this->validate(['restoreDatabase' => ['required', 'regex:/^[A-Za-z0-9_-]{1,64}$/']], ['restoreDatabase.regex' => 'Use letters, numbers, dashes, and underscores.']);

            if (! $this->restoreWhole && $this->restoreTables === []) {
                $this->addError('restoreTables', 'Choose at least one table, or restore the whole database.');

                return;
            }

            $options = ['database' => $this->restoreDatabase, 'tables' => $this->restoreWhole ? [] : array_values($this->restoreTables)];
        } else {
            if (! $this->restoreWhole && $this->restoreMembers === []) {
                $this->addError('restoreMembers', 'Tick the folders and files to restore, or restore everything.');

                return;
            }

            $options = ['members' => $this->restoreWhole ? [] : array_values($this->restoreMembers), 'original' => $this->restoreToOriginal];
        }

        if (($run->isDatabase() || $this->restoreToOriginal) && ! $this->understood) {
            $this->addError('understood', 'Tick the box to confirm.');

            return;
        }

        try {
            $backups->queueRestore($run, $options);
            $this->restoreOpen = false;
            $this->toast('Restore started. Follow it in the list below.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    // View what is inside a backup

    public function openView(int $id): void
    {
        $run = $this->restorableRun($id);

        if (! $run) {
            return;
        }

        $this->viewRunId = $run->id;
        $this->viewTab = $run->isDatabase() ? 'tables' : 'files';
        $this->viewSearch = '';
        $this->viewPage = 1;
        $this->viewItem = '';
        $this->archiveFolder = '';
        $this->ensureManifest($run);
    }

    public function closeView(): void
    {
        $this->viewRunId = null;
    }

    public function setViewTab(string $tab): void
    {
        if (array_key_exists($tab, BackupInspector::PARTS)) {
            $this->viewTab = $tab;
            $this->viewSearch = '';
            $this->viewPage = 1;
            $this->viewItem = '';
        }
    }

    public function showItem(string $name): void
    {
        $this->viewItem = $this->viewItem === $name ? '' : $name;
    }

    // Download parts of a backup

    public function openDownload(int $id): void
    {
        $run = $this->restorableRun($id);

        if (! $run) {
            return;
        }

        $this->downloadRunId = $run->id;
        $this->downloadMode = $run->isDatabase() ? 'parts' : 'original';
        $this->downloadParts = array_keys(BackupInspector::PARTS);
        $this->downloadAllTables = true;
        $this->downloadTables = [];
        $this->tableSearch = '';
        $this->resetErrorBag();
    }

    public function closeDownload(): void
    {
        $this->downloadRunId = null;
    }

    public function startDownload(PanelTaskRunner $tasks): void
    {
        $this->resetErrorBag();
        $run = $this->restorableRun((int) $this->downloadRunId);

        if (! $run) {
            return;
        }

        $original = $this->downloadMode === 'original' || ! $run->isDatabase();
        $parts = array_values(array_intersect(array_keys(BackupInspector::PARTS), $this->downloadParts));

        if (! $original && $parts === []) {
            $this->addError('downloadParts', 'Choose at least one part.');

            return;
        }

        if (! $original && in_array('tables', $parts, true) && ! $this->downloadAllTables && $this->downloadTables === []) {
            $this->addError('downloadTables', 'Choose the tables, or download all of them.');

            return;
        }

        $task = $tasks->start('backup.export', self::TASK_SCOPE, 'Preparing '.$run->target.($original ? ' (backup file)' : ' (SQL)'), [
            'run_id' => $run->id,
            'original' => $original,
            'parts' => $parts,
            'tables' => $this->downloadAllTables ? null : array_values($this->downloadTables),
        ]);

        $this->downloadRunId = null;
        $task->isFinished() ? $this->pollTasks() : $this->toast('Preparing the download. It starts by itself when it is ready.');
    }

    // Background tasks (reading a backup, preparing a download)

    public function pollTasks(): void
    {
        $finished = PanelTask::query()
            ->where('scope', self::TASK_SCOPE)
            ->whereIn('status', [PanelTask::SUCCESS, PanelTask::FAILED])
            ->whereNotIn('id', $this->announced ?: [0])
            ->get();

        foreach ($finished as $task) {
            $this->announced[] = $task->id;

            if ($task->status === PanelTask::FAILED) {
                $this->toast($task->label.' failed: '.$task->message, 'error');
            } elseif ($task->kind === 'backup.export') {
                $this->dispatch('download-ready', url: route('tasks.download', $task));
                $this->toast((string) $task->message);
            }
        }
    }

    public function dismissTask(int $id): void
    {
        $task = PanelTask::query()->where('scope', self::TASK_SCOPE)->find($id);

        if ($task && ! $task->isActive()) {
            if ($file = $task->resultFile()) {
                @unlink($file);
            }

            $task->delete();
        }
    }

    public function render(BackupManager $backups, BackupInspector $inspector)
    {
        $isDatabase = $this->kind === BackupSchedule::KIND_DATABASE;
        $schedules = BackupSchedule::query()->with('storage')->where('kind', $this->kind)->orderBy('name')->get();
        $lastRuns = BackupRun::query()->where('kind', $this->kind)->where('type', BackupRun::TYPE_BACKUP)->whereIn('backup_schedule_id', $schedules->pluck('id'))
            ->latest('id')->get()->unique('backup_schedule_id')->keyBy('backup_schedule_id');

        $runs = BackupRun::query()->with(['storage', 'schedule', 'source'])->where('kind', $this->kind)
            ->when($this->filter !== '', fn ($query) => $isDatabase
                ? $query->where('target', $this->filter)
                : $query->where('backup_schedule_id', (int) $this->filter))
            ->latest('id')->limit(30)->get();

        $restorable = BackupRun::query()->with(['storage', 'schedule'])->where('kind', $this->kind)->where('type', BackupRun::TYPE_BACKUP)
            ->where('status', BackupRun::SUCCESS)->whereNotNull('object_key')->latest('id')->get()
            ->filter(fn (BackupRun $run) => $run->storage !== null);

        $tasks = PanelTask::query()->where('scope', self::TASK_SCOPE)->where('updated_at', '>', now()->subHours(6))->latest('id')->limit(4)->get();
        $restoreRun = $this->restoreRunId ? $restorable->firstWhere('id', $this->restoreRunId) : null;
        $viewRun = $this->viewRunId ? $restorable->firstWhere('id', $this->viewRunId) : null;
        $downloadRun = $this->downloadRunId ? $restorable->firstWhere('id', $this->downloadRunId) : null;
        $active = BackupRun::query()->where('kind', $this->kind)->whereIn('status', [BackupRun::QUEUED, BackupRun::RUNNING])->exists();

        return view('livewire.backups.index', [
            'isDatabase' => $isDatabase,
            'schedules' => $schedules,
            'lastRuns' => $lastRuns,
            'running' => $schedules->mapWithKeys(fn (BackupSchedule $schedule) => [$schedule->id => $backups->isRunning($schedule)]),
            'runs' => $runs,
            'filters' => $this->filterOptions($restorable, $schedules),
            'hasStorage' => BackupStorage::query()->exists(),
            'tasks' => $tasks,
            'polling' => $active || $tasks->contains(fn (PanelTask $task) => $task->isActive()),
            'logRun' => $this->logRunId ? BackupRun::query()->find($this->logRunId) : null,
            'restoreTargets' => $this->restoreOpen ? $this->restoreTargets($restorable) : collect(),
            'restoreChoices' => $this->restoreOpen && $this->restoreTarget !== ''
                ? $restorable->filter(fn (BackupRun $run) => $this->targetKey($run) === $this->restoreTarget)->values()
                : collect(),
            'restoreRun' => $restoreRun,
            'restoreTableList' => $restoreRun && $restoreRun->isDatabase() ? $this->tableRows($restoreRun, $inspector, $this->tableSearch) : [],
            'restoreManifest' => $restoreRun && ! $restoreRun->isDatabase() ? $inspector->manifest($restoreRun) : null,
            'viewRun' => $viewRun,
            'viewManifest' => $viewRun ? $inspector->manifest($viewRun) : null,
            'downloadRun' => $downloadRun,
            'downloadTableList' => $downloadRun && $downloadRun->isDatabase() ? $this->tableRows($downloadRun, $inspector, $this->tableSearch) : [],
            'parts' => BackupInspector::PARTS,
            'inspecting' => $this->inspectingIds(),
        ])->layout('layouts.app')->title($isDatabase ? 'Database backups' : 'File backups');
    }

    /**
     * Read a backup in the background when its contents list is not here yet.
     */
    private function ensureManifest(BackupRun $run): void
    {
        if (app(BackupInspector::class)->manifest($run) !== null || in_array($run->id, $this->inspectingIds(), true)) {
            return;
        }

        $task = app(PanelTaskRunner::class)->start('backup.inspect', self::TASK_SCOPE, 'Reading the backup of '.$run->target, ['run_id' => $run->id]);
        $this->announced[] = $task->id;
    }

    /**
     * @return list<int> Backups being read right now.
     */
    private function inspectingIds(): array
    {
        return PanelTask::query()->where('scope', self::TASK_SCOPE)->where('kind', 'backup.inspect')->active()->get()
            ->map(fn (PanelTask $task) => (int) ($task->payload['run_id'] ?? 0))->all();
    }

    private function restorableRun(int $id): ?BackupRun
    {
        $run = BackupRun::query()->with('storage')->where('kind', $this->kind)->find($id);

        if (! $run || ! $run->restorable()) {
            $this->toast('That backup is not available. It may have been deleted from the bucket.', 'error');

            return null;
        }

        return $run;
    }

    /**
     * Backups are grouped by database name, or by schedule and folder for files.
     */
    private function targetKey(BackupRun $run): string
    {
        return $run->isDatabase() ? $run->target : $run->backup_schedule_id.'|'.$run->target;
    }

    /**
     * Step 1 of the restore: each database (or file schedule) with its newest backup.
     *
     * @param  Collection<int, BackupRun>  $restorable
     * @return Collection<int, array{key: string, label: string, count: int, latest: BackupRun}>
     */
    private function restoreTargets(Collection $restorable): Collection
    {
        return $restorable->groupBy(fn (BackupRun $run) => $this->targetKey($run))
            ->map(fn (Collection $runs, string $key) => [
                'key' => $key,
                'label' => $runs->first()->target,
                'detail' => $runs->first()->isDatabase() ? null : ($runs->first()->schedule?->name ?? 'Deleted schedule'),
                'count' => $runs->count(),
                'latest' => $runs->first(),
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * @param  Collection<int, BackupRun>  $restorable
     * @param  Collection<int, BackupSchedule>  $schedules
     * @return array<string, string>
     */
    private function filterOptions(Collection $restorable, Collection $schedules): array
    {
        if ($this->kind === BackupSchedule::KIND_DATABASE) {
            $names = BackupRun::query()->where('kind', $this->kind)->where('type', BackupRun::TYPE_BACKUP)->distinct()->orderBy('target')->pluck('target')->all();

            return array_combine($names, $names);
        }

        return $schedules->mapWithKeys(fn (BackupSchedule $schedule) => [(string) $schedule->id => $schedule->name])->all();
    }

    /**
     * Tables of a database backup, from its contents list when it has one.
     *
     * @return list<string>
     */
    private function tableNames(?BackupRun $run): array
    {
        if (! $run) {
            return [];
        }

        $manifest = app(BackupInspector::class)->manifest($run);

        return $manifest ? array_column($manifest['tables'] ?? [], 'name') : array_values($run->meta['tables'] ?? []);
    }

    /**
     * @return list<array{name: string, rows: ?int, bytes: ?int}>
     */
    private function tableRows(BackupRun $run, BackupInspector $inspector, string $search): array
    {
        $manifest = $inspector->manifest($run);
        $rows = $manifest
            ? array_map(fn (array $table) => ['name' => $table['name'], 'rows' => $table['rows'], 'bytes' => $table['bytes']], $manifest['tables'] ?? [])
            : array_map(fn (string $name) => ['name' => $name, 'rows' => null, 'bytes' => null], $run->meta['tables'] ?? []);

        return array_values(array_filter($rows, fn (array $row) => in_array($row['name'], $this->filterNames([$row['name']], $search), true)));
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function filterNames(array $names, string $search): array
    {
        $search = mb_strtolower(trim($search));

        return array_values(array_filter($names, fn (string $name) => $search === '' || str_contains(mb_strtolower($name), $search)));
    }

    private function resetRestore(): void
    {
        $this->restoreStep = 1;
        $this->restoreTarget = '';
        $this->restoreRunId = null;
        $this->restoreWhole = true;
        $this->restoreDatabase = '';
        $this->restoreTables = [];
        $this->restoreMembers = [];
        $this->archiveFolder = '';
        $this->restoreToOriginal = false;
        $this->understood = false;
        $this->tableSearch = '';
        $this->tablePage = 1;
        $this->resetErrorBag();
    }
}
