<?php

namespace App\Livewire\Backups;

use App\Livewire\PanelComponent;
use App\Models\BackupSchedule;
use App\Models\BackupStorage;
use App\Services\DatabaseDumper;
use App\Services\FileArchiver;
use App\Support\BackupCron;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class ScheduleForm extends PanelComponent
{
    public ?BackupSchedule $schedule = null;

    public string $kind = BackupSchedule::KIND_DATABASE;

    public string $name = '';

    public string $backup_storage_id = '';

    public bool $enabled = true;

    public string $frequency = 'daily';

    public int $interval = 1;

    public string $run_time = '02:00';

    public int $weekday = 1;

    public string $custom_cron = '';

    public bool $all_databases = false;

    /** @var list<string> */
    public array $databases = [];

    public string $extraDatabase = '';

    /** @var list<string> */
    public array $paths = [];

    public string $excludes = '';

    public string $browse = '';

    public int $retention_count = 7;

    public string $retention_days = '';

    public function mount(?string $kind = null, ?BackupSchedule $schedule = null): void
    {
        $this->schedule = $schedule?->exists ? $schedule : null;
        $this->kind = $this->schedule?->kind ?? (in_array((string) $kind, [BackupSchedule::KIND_DATABASE, BackupSchedule::KIND_FILES], true) ? $kind : BackupSchedule::KIND_DATABASE);

        if (! $this->schedule) {
            $this->backup_storage_id = (string) (BackupStorage::query()->where('is_default', true)->value('id') ?? '');

            return;
        }

        $targets = $this->schedule->targets ?? [];
        $this->name = $this->schedule->name;
        $this->backup_storage_id = (string) $this->schedule->backup_storage_id;
        $this->enabled = $this->schedule->enabled;
        $this->frequency = $this->schedule->frequency;
        $this->interval = $this->schedule->interval;
        $this->run_time = $this->schedule->run_time;
        $this->weekday = $this->schedule->weekday;
        $this->custom_cron = $this->schedule->frequency === 'custom' ? $this->schedule->cron : '';
        $this->all_databases = (bool) ($targets['all'] ?? false);
        $this->databases = array_values($targets['databases'] ?? []);
        $this->paths = array_values($targets['paths'] ?? []);
        $this->excludes = implode("\n", $targets['excludes'] ?? []);
        $this->retention_count = $this->schedule->retention_count;
        $this->retention_days = (string) ($this->schedule->retention_days ?? '');
    }

    public function openFolder(string $name): void
    {
        $this->browse = trim($this->browse.'/'.$name, '/');
    }

    public function openParent(): void
    {
        $this->browse = str_contains($this->browse, '/') ? dirname($this->browse) : '';
    }

    public function openRoot(): void
    {
        $this->browse = '';
    }

    public function addBrowsedFolder(): void
    {
        $this->addPath($this->browse);
    }

    public function addFolder(string $name): void
    {
        $this->addPath(trim($this->browse.'/'.$name, '/'));
    }

    public function removePath(int $index): void
    {
        unset($this->paths[$index]);
        $this->paths = array_values($this->paths);
    }

    public function toggleExclude(string $pattern): void
    {
        $lines = $this->excludeLines();
        $lines = in_array($pattern, $lines, true) ? array_values(array_diff($lines, [$pattern])) : [...$lines, $pattern];
        $this->excludes = implode("\n", $lines);
    }

    public function addDatabase(): void
    {
        $name = trim($this->extraDatabase);

        if (! DatabaseDumper::validName($name)) {
            $this->addError('extraDatabase', 'Use letters, numbers, dashes, and underscores.');

            return;
        }

        $this->databases = array_values(array_unique([...$this->databases, $name]));
        $this->extraDatabase = '';
        $this->resetErrorBag('extraDatabase');
    }

    public function save(): void
    {
        $isDatabase = $this->kind === BackupSchedule::KIND_DATABASE;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('backup_schedules', 'name')->ignore($this->schedule?->id)],
            'backup_storage_id' => ['required', 'exists:backup_storages,id'],
            'frequency' => ['required', Rule::in(array_keys(BackupCron::FREQUENCIES))],
            'interval' => ['integer', 'min:1', 'max:'.($this->frequency === 'hours' ? 23 : 59)],
            'run_time' => ['required', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'weekday' => ['integer', 'between:0,6'],
            'retention_count' => ['required', 'integer', 'between:1,365'],
            'retention_days' => ['nullable', 'integer', 'between:1,3650'],
        ], [
            'backup_storage_id.required' => 'Choose where to store the backups. Add an S3 storage first if the list is empty.',
            'run_time.regex' => 'Use a time like 02:30.',
        ]);

        try {
            $cron = BackupCron::build($this->frequency, $this->interval, $this->run_time, $this->weekday, $this->custom_cron);
        } catch (InvalidArgumentException $exception) {
            $this->addError($this->frequency === 'custom' ? 'custom_cron' : 'frequency', $exception->getMessage());

            return;
        }

        if ($isDatabase) {
            $databases = array_values(array_unique(array_filter($this->databases, fn (string $name) => DatabaseDumper::validName($name))));

            if (! $this->all_databases && $databases === []) {
                $this->addError('databases', 'Choose at least one database, or back up all of them.');

                return;
            }

            $targets = ['all' => $this->all_databases, 'databases' => $this->all_databases ? [] : $databases];
        } else {
            if ($this->paths === []) {
                $this->addError('paths', 'Choose at least one folder to back up.');

                return;
            }

            try {
                $targets = [
                    'paths' => array_values(array_unique(array_map(FileArchiver::cleanPath(...), $this->paths))),
                    'excludes' => array_values(array_unique(array_map(FileArchiver::cleanPath(...), $this->excludeLines()))),
                ];
            } catch (Throwable $exception) {
                $this->addError('excludes', $exception->getMessage());

                return;
            }
        }

        $attributes = [
            'name' => $validated['name'],
            'kind' => $this->kind,
            'backup_storage_id' => (int) $validated['backup_storage_id'],
            'enabled' => $this->enabled,
            'frequency' => $validated['frequency'],
            'interval' => $validated['interval'],
            'run_time' => $validated['run_time'],
            'weekday' => $validated['weekday'],
            'cron' => $cron,
            'targets' => $targets,
            'retention_count' => $validated['retention_count'],
            'retention_days' => filled($validated['retention_days']) ? (int) $validated['retention_days'] : null,
        ];

        $this->schedule ? $this->schedule->update($attributes) : BackupSchedule::query()->create($attributes);

        $this->toastAfterRedirect('Backup schedule saved: '.BackupCron::describe($attributes['frequency'], $attributes['interval'], $attributes['run_time'], $attributes['weekday'], $cron).'.');
        $this->redirect(route($isDatabase ? 'backups.databases' : 'backups.files'), navigate: true);
    }

    private function addPath(string $relative): void
    {
        try {
            $relative = FileArchiver::cleanPath($relative);
        } catch (Throwable) {
            return;
        }

        if (! in_array($relative, $this->paths, true)) {
            $this->paths[] = $relative;
        }

        $this->resetErrorBag('paths');
    }

    /**
     * @return list<string>
     */
    private function excludeLines(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $this->excludes) ?: []), fn (string $line) => $line !== ''));
    }

    public function render(DatabaseDumper $dumper, FileArchiver $archiver)
    {
        $isDatabase = $this->kind === BackupSchedule::KIND_DATABASE;
        $available = [];
        $folders = [];
        $pickerError = null;

        try {
            if ($isDatabase) {
                $available = $dumper->databases();
            } else {
                $folders = $archiver->folders($this->browse);
            }
        } catch (Throwable $exception) {
            $pickerError = $exception->getMessage();
        }

        return view('livewire.backups.schedule-form', [
            'isDatabase' => $isDatabase,
            'storages' => BackupStorage::query()->orderBy('name')->get(),
            'frequencies' => BackupCron::FREQUENCIES,
            'weekdays' => BackupCron::WEEKDAYS,
            'availableDatabases' => $available,
            'folders' => $folders,
            'pickerError' => $pickerError,
            'suggestedExcludes' => FileArchiver::SUGGESTED_EXCLUDES,
            'activeExcludes' => $this->excludeLines(),
            'rootLabel' => rtrim((string) (config('laravelify.files_root') ?: config('laravelify.sites_root')), '/'),
        ])->layout('layouts.app')->title($this->schedule ? 'Edit backup schedule' : 'New backup schedule');
    }
}
