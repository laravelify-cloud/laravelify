<?php

namespace App\Livewire\Backups;

use App\Livewire\Concerns\ListsTables;
use App\Livewire\PanelComponent;
use App\Models\BackupSchedule;
use App\Models\BackupStorage;
use App\Services\DatabaseDumper;
use App\Services\FileArchiver;
use App\Support\BackupCron;
use App\Support\BackupExcludes;
use App\Support\SiteTypes;
use App\Support\SkippedTables;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class ScheduleForm extends PanelComponent
{
    use ListsTables;

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

    /** Every website, each in its own archive, with skips by site type. */
    public bool $all_websites = false;

    /** @var list<string> */
    public array $databases = [];

    public string $extraDatabase = '';

    /** @var list<string> Tables left out: "database.table" or "*.table". */
    public array $skip_tables = [];

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
        $this->all_websites = (bool) ($targets['websites'] ?? false);
        $this->databases = array_values($targets['databases'] ?? []);
        $this->skip_tables = $this->schedule->skippedTables();
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

    /**
     * Open a folder by its path from the root (a row of the picker).
     */
    public function openPath(string $relative): void
    {
        try {
            $this->browse = FileArchiver::cleanPath($relative);
        } catch (Throwable) {
            $this->browse = '';
        }
    }

    public function addBrowsedFolder(): void
    {
        $this->addPath($this->browse);
    }

    public function addFolder(string $name): void
    {
        $this->addPath(trim($this->browse.'/'.$name, '/'));
    }

    /**
     * Add the picked folders and files to the backup. A path that is skipped stops being skipped.
     *
     * @param  list<string>  $relatives
     */
    public function includePaths(array $relatives): void
    {
        $skipped = [];
        $sites = collect(BackupExcludes::websites(app(FileArchiver::class)->root()))->keyBy('folder');

        foreach ($relatives as $relative) {
            $relative = trim((string) $relative, '/');
            $this->addPath($relative);
            $lines = array_values(array_diff($this->excludeLines(), [$relative]));

            // A website folder: leave out what a deploy rebuilds (node_modules, vendor, dist ...).
            foreach ($sites->get($relative)['excludes'] ?? [] as $pattern) {
                if (! in_array($pattern, $lines, true)) {
                    $lines[] = $pattern;
                    $skipped[] = $pattern;
                }
            }

            $this->excludes = implode("\n", $lines);
        }

        if ($skipped !== []) {
            $this->toast('Skipped for the website type: '.implode(', ', array_values(array_unique(array_map('basename', $skipped)))).'. Remove any you want to keep.');
        }

        $this->dispatch('picker-done');
    }

    /**
     * Skip the picked folders and files. They are left out even when a parent folder is backed up.
     *
     * @param  list<string>  $relatives
     */
    public function excludePaths(array $relatives): void
    {
        $lines = $this->excludeLines();

        foreach ($relatives as $relative) {
            try {
                $path = FileArchiver::cleanPath((string) $relative);
            } catch (Throwable) {
                continue;
            }

            if ($path !== '' && ! in_array($path, $lines, true)) {
                $lines[] = $path;
            }

            $this->paths = array_values(array_diff($this->paths, [$path]));
        }

        $this->excludes = implode("\n", $lines);
        $this->dispatch('picker-done');
    }

    public function removePath(int $index): void
    {
        unset($this->paths[$index]);
        $this->paths = array_values($this->paths);
    }

    public function removeExclude(string $pattern): void
    {
        $this->excludes = implode("\n", array_values(array_diff($this->excludeLines(), [$pattern])));
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
            'backup_storage_id.required' => 'Choose where to store the backups. Add a storage (an S3 bucket or a folder on this server) first if the list is empty.',
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

            $targets = ['all' => $this->all_databases, 'databases' => $this->all_databases ? [] : $databases, 'skip_tables' => SkippedTables::normalize($this->skip_tables)];
        } else {
            if (! $this->all_websites && $this->paths === []) {
                $this->addError('paths', 'Choose at least one folder to back up.');

                return;
            }

            try {
                $targets = [
                    'websites' => $this->all_websites,
                    'paths' => $this->all_websites ? [] : array_values(array_unique(array_map(FileArchiver::cleanPath(...), $this->paths))),
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

    /**
     * Each website with its folder and what is skipped for its type, for the "every website" preview.
     *
     * @return list<array{name: string, type: string, folder: string, skips: list<string>}>
     */
    private function websiteSummaries(FileArchiver $archiver): array
    {
        try {
            $sites = BackupExcludes::websites($archiver->root());
        } catch (Throwable) {
            return [];
        }

        return array_map(fn (array $site) => [
            'name' => $site['website']->name,
            'type' => SiteTypes::label((string) $site['website']->type),
            'folder' => $site['folder'],
            'skips' => array_map(fn (string $pattern) => str_starts_with($pattern, $site['folder'].'/') ? substr($pattern, strlen($site['folder']) + 1) : $pattern, $site['excludes']),
        ], $sites);
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
                $folders = $archiver->listing($this->browse);
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
            'listing' => $folders,
            'websiteSites' => $isDatabase ? [] : $this->websiteSummaries($archiver),
            'pickerError' => $pickerError,
            'suggestedExcludes' => FileArchiver::SUGGESTED_EXCLUDES,
            'activeExcludes' => $this->excludeLines(),
            'rootLabel' => rtrim((string) (config('laravelify.files_root') ?: config('laravelify.sites_root')), '/'),
        ])->layout('layouts.app')->title($this->schedule ? 'Edit backup schedule' : 'New backup schedule');
    }
}
