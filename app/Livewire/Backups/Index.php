<?php

namespace App\Livewire\Backups;

use App\Livewire\PanelComponent;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\BackupStorage;
use App\Services\BackupManager;
use App\Support\BackupCron;
use Throwable;

class Index extends PanelComponent
{
    public string $kind = BackupSchedule::KIND_DATABASE;

    public ?int $logRunId = null;

    public ?int $restoreRunId = null;

    public bool $restoreWhole = true;

    public string $restoreDatabase = '';

    /** @var list<string> */
    public array $restoreTables = [];

    public string $restoreMember = '';

    public bool $restoreToOriginal = false;

    public bool $understood = false;

    public function mount(string $kind = BackupSchedule::KIND_DATABASE): void
    {
        $this->kind = $kind === BackupSchedule::KIND_FILES ? BackupSchedule::KIND_FILES : BackupSchedule::KIND_DATABASE;
    }

    public function runNow(BackupManager $backups, int $id): void
    {
        $schedule = BackupSchedule::query()->where('kind', $this->kind)->findOrFail($id);

        $started = $backups->queue($schedule, 'manual');

        $this->toast($started ? 'Backup started. The list updates as it runs.' : 'This schedule is already running.', $started ? 'success' : 'error');
    }

    public function toggle(int $id): void
    {
        $schedule = BackupSchedule::query()->where('kind', $this->kind)->findOrFail($id);
        $schedule->update(['enabled' => ! $schedule->enabled]);
    }

    public function delete(int $id): void
    {
        BackupSchedule::query()->where('kind', $this->kind)->findOrFail($id)->delete();
        $this->toast('Schedule deleted. Backups already made stay in your bucket and in the list.');
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
            'hourly' => ['name' => 'All databases, every hour', 'frequency' => 'hourly', 'interval' => 1, 'run_time' => '00:00', 'retention' => 24],
            'daily' => ['name' => 'All databases, every day', 'frequency' => 'daily', 'interval' => 1, 'run_time' => '02:00', 'retention' => 14],
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
            'interval' => $choice['interval'],
            'run_time' => $choice['run_time'],
            'weekday' => 0,
            'cron' => BackupCron::build($choice['frequency'], $choice['interval'], $choice['run_time'], 0),
            'targets' => ['all' => true, 'databases' => []],
            'retention_count' => $choice['retention'],
        ]);

        $this->toast('Schedule created: '.$choice['name'].'.');
    }

    public function showLog(int $id): void
    {
        $this->logRunId = $id;
    }

    public function openRestore(int $id): void
    {
        $run = BackupRun::query()->with('storage')->where('kind', $this->kind)->findOrFail($id);

        if (! $run->restorable()) {
            $this->toast('That backup is not available to restore.', 'error');

            return;
        }

        $this->restoreRunId = $run->id;
        $this->restoreWhole = true;
        $this->restoreDatabase = $run->isDatabase() ? $run->target : '';
        $this->restoreTables = [];
        $this->restoreMember = '';
        $this->restoreToOriginal = false;
        $this->understood = false;
        $this->resetErrorBag();
    }

    public function closeRestore(): void
    {
        $this->restoreRunId = null;
    }

    public function restore(BackupManager $backups): void
    {
        $run = BackupRun::query()->with('storage')->where('kind', $this->kind)->findOrFail($this->restoreRunId);

        if ($run->isDatabase()) {
            $this->validate(['restoreDatabase' => ['required', 'regex:/^[A-Za-z0-9_-]{1,64}$/']], ['restoreDatabase.regex' => 'Use letters, numbers, dashes, and underscores.']);

            if (! $this->restoreWhole && $this->restoreTables === []) {
                $this->addError('restoreTables', 'Choose at least one table, or restore the whole database.');

                return;
            }

            $options = ['database' => $this->restoreDatabase, 'tables' => $this->restoreWhole ? [] : array_values($this->restoreTables)];
        } else {
            $options = ['member' => $this->restoreWhole ? '' : $this->restoreMember, 'original' => $this->restoreToOriginal];

            if (! $this->restoreWhole && trim($this->restoreMember) === '') {
                $this->addError('restoreMember', 'Choose the folder to restore.');

                return;
            }
        }

        if (($run->isDatabase() || $this->restoreToOriginal) && ! $this->understood) {
            $this->addError('understood', 'Tick the box to confirm.');

            return;
        }

        try {
            $backups->queueRestore($run, $options);
            $this->restoreRunId = null;
            $this->toast('Restore started. Follow it in the list below.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
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

    public function render(BackupManager $backups)
    {
        $schedules = BackupSchedule::query()->with('storage')->where('kind', $this->kind)->orderBy('name')->get();
        $runs = BackupRun::query()->with(['storage', 'schedule', 'source'])->where('kind', $this->kind)->latest('id')->limit(30)->get();
        $restoreRun = $this->restoreRunId ? BackupRun::query()->find($this->restoreRunId) : null;

        return view('livewire.backups.index', [
            'isDatabase' => $this->kind === BackupSchedule::KIND_DATABASE,
            'schedules' => $schedules,
            'runs' => $runs,
            'active' => $runs->contains(fn (BackupRun $run) => in_array($run->status, [BackupRun::QUEUED, BackupRun::RUNNING], true)),
            'hasStorage' => BackupStorage::query()->exists(),
            'logRun' => $this->logRunId ? BackupRun::query()->find($this->logRunId) : null,
            'restoreRun' => $restoreRun,
            'running' => $schedules->mapWithKeys(fn (BackupSchedule $schedule) => [$schedule->id => $backups->isRunning($schedule)]),
        ])->layout('layouts.app')->title($this->kind === BackupSchedule::KIND_FILES ? 'File backups' : 'Database backups');
    }
}
