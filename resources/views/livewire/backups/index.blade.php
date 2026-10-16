@php
    $time = app(\App\Services\DisplayTime::class);
    $bytes = fn (?int $value) => $value === null ? '—' : \Illuminate\Support\Number::fileSize($value, precision: 1);
@endphp

<div
    class="flex flex-col gap-6"
    @if ($polling) wire:poll.2s="pollTasks" @endif
    @download-ready.window="window.location.href = $event.detail.url"
>
    <x-page-header
        :title="$isDatabase ? 'Database backups' : 'File backups'"
        :summary="$isDatabase ? 'Dump databases to your storage (S3 or a folder on this server) on a schedule. Restore a whole database or some tables, look inside a backup, or download parts of it as SQL.' : 'Archive folders to your storage (S3 or a folder on this server) on a schedule. Restore everything or picked folders and files, or download a backup.'"
    >
        <x-slot:actions>
            <button class="btn" type="button" wire:click="fullBackup" wire:loading.attr="disabled" wire:target="fullBackup" @disabled(! $hasStorage) title="{{ $isDatabase ? 'Back up every database now, each in its own file, and choose tables to leave out' : 'Back up every website now, each in its own archive, without dependencies and build output' }}"><x-menu-icon name="backup" /> Full backup</button>
            <button class="btn" type="button" wire:click="openRestore" @disabled(! $hasStorage)><x-menu-icon name="restore" /> Restore</button>
            <a class="btn" href="{{ route('backups.storages') }}" wire:navigate><x-menu-icon name="s3" /> Storage</a>
            <a class="btn btn-primary" href="{{ route('backups.schedules.create', $isDatabase ? 'database' : 'files') }}" wire:navigate><x-menu-icon name="plus" /> New schedule</a>
        </x-slot:actions>
    </x-page-header>

    @unless ($hasStorage)
        <div class="callout" role="note">
            <x-menu-icon name="s3" class="size-4 shrink-0 text-muted" />
            <p class="min-w-0 flex-1 text-[13px] text-muted">
                <span class="font-medium text-ink">Add a backup storage first (an S3 bucket or a folder on this server).</span>
                Backups need a bucket to go to.
                <a class="underline" href="{{ route('backups.storages') }}" wire:navigate>Add storage</a>
            </p>
        </div>
    @endunless

    <x-task-tray :tasks="$tasks" />

    {{-- Schedules --}}
    <x-layer-card title="Schedules" flush>
        <div x-data="{ selected: [], all: @js($schedules->pluck('id')->all()) }" @bulk-done.window="selected = []">
        <div class="fm-selection" x-cloak x-show="selected.length > 0">
            <div class="flex items-center gap-2">
                <button class="icon-btn" type="button" @click="selected = []" aria-label="Clear selection"><x-menu-icon name="close" class="size-4" /></button>
                <span class="font-medium"><span x-text="selected.length"></span> selected</span>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                <button class="btn btn-sm btn-primary" type="button" @click="$wire.runSchedules(selected)"><x-menu-icon name="play" /> Run now</button>
                <button class="btn btn-sm" type="button" @click="$wire.setSchedulesEnabled(selected, false)"><x-menu-icon name="pause" /> Pause</button>
                <button class="btn btn-sm" type="button" @click="$wire.setSchedulesEnabled(selected, true)"><x-menu-icon name="play" /> Resume</button>
                <button class="btn btn-sm btn-danger" type="button" @click="if (confirm('Delete the selected schedules? Backups they made are kept.')) $wire.deleteSchedules(selected)"><x-menu-icon name="trash" /> Delete</button>
            </div>
        </div>
        <table class="table">
            <thead>
                <tr>
                    <th class="w-10"><input type="checkbox" aria-label="Select all schedules" :checked="all.length > 0 && selected.length === all.length" @change="selected = $event.target.checked ? [...all] : []"></th>
                    <th>Name</th>
                    <th>{{ $isDatabase ? 'Databases' : 'Folders' }}</th>
                    <th>When</th>
                    <th>Last run</th>
                    <th class="w-44"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($schedules as $schedule)
                    @php $last = $lastRuns[$schedule->id] ?? null; @endphp
                    @php $isRunning = $running[$schedule->id] ?? false; @endphp
                    <tr wire:key="schedule-{{ $schedule->id }}">
                        <td><input type="checkbox" aria-label="Select {{ $schedule->name }}" :checked="selected.includes({{ $schedule->id }})" @change="selected = $event.target.checked ? [...selected, {{ $schedule->id }}] : selected.filter((id) => id !== {{ $schedule->id }})"></td>
                        <td>
                            <span class="flex items-center gap-2">
                                <span class="font-medium {{ $schedule->enabled ? '' : 'text-muted' }}">{{ $schedule->name }}</span>
                                @unless ($schedule->enabled)
                                    <span class="pill">Paused</span>
                                @endunless
                            </span>
                            <span class="block text-[12px] text-muted">{{ $schedule->storage?->name ?? 'No storage' }} · keeps {{ $schedule->retention_count }}{{ $schedule->retention_days ? ', '.$schedule->retention_days.' days' : '' }}</span>
                        </td>
                        <td class="max-w-xs truncate font-mono text-[13px] text-muted" title="{{ $schedule->describeTargets() }}">{{ $schedule->describeTargets() ?: '—' }}</td>
                        <td class="text-muted">{{ $schedule->describeFrequency() }}</td>
                        <td>
                            @if ($isRunning)
                                <x-status-badge status="running" />
                            @elseif ($last)
                                <x-status-badge :status="$last->status" />
                                <span class="block text-[12px] text-muted">{{ $time->ago($last->created_at) }}</span>
                            @else
                                <span class="text-muted">Never</span>
                            @endif
                        </td>
                        <td>
                            <div class="row-actions w-full">
                                <button class="btn btn-sm" type="button" wire:click="runNow({{ $schedule->id }})" wire:loading.attr="disabled" wire:target="runNow({{ $schedule->id }})" @disabled($isRunning)>
                                    <x-menu-icon name="play" wire:loading.remove wire:target="runNow({{ $schedule->id }})" />
                                    <span class="task-spinner" wire:loading wire:target="runNow({{ $schedule->id }})"></span>
                                    {{ $isRunning ? 'Running' : 'Run now' }}
                                </button>
                                @include('livewire.backups.partials.row-menu', ['items' => array_filter([
                                    ['label' => 'Edit', 'icon' => 'pencil', 'href' => route('backups.schedules.edit', $schedule)],
                                    ['label' => $schedule->enabled ? 'Pause schedule' : 'Resume schedule', 'icon' => $schedule->enabled ? 'pause' : 'play', 'click' => 'toggle('.$schedule->id.')'],
                                    $isRunning ? ['label' => 'Cancel waiting runs', 'icon' => 'stop', 'click' => 'cancelWaiting('.$schedule->id.')'] : null,
                                    ['label' => 'Delete schedule', 'icon' => 'trash', 'click' => 'delete('.$schedule->id.')', 'danger' => true, 'confirm' => 'Delete '.$schedule->name.'? Backups it made are kept.'],
                                ])])
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="table-empty">
                            <p>No schedules yet.</p>
                            @if ($isDatabase && $hasStorage)
                                <div class="mt-3 flex flex-wrap justify-center gap-2">
                                    <button class="btn btn-primary" type="button" wire:click="quickStart('hourly')">Back up all databases every hour</button>
                                    <button class="btn" type="button" wire:click="quickStart('daily')">Back up all databases every day</button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-layer-card>

    {{-- Backups and restores --}}
    <x-layer-card title="Backups and restores" helper="{{ $isDatabase ? 'Grouped by schedule: its newest run lists one dump per database, older runs open below. Look inside, download, or restore one database on its own.' : 'Grouped by schedule: its newest run lists one archive per website or folder, older runs open below. Look inside, download, or restore one website on its own.' }} Any backup still in storage can be restored." flush>
        <x-slot:actions>
            @if ($filters !== [])
                <select class="select h-8 text-[13px]" wire:model.live="filter" aria-label="Show backups of">
                    <option value="">{{ $isDatabase ? 'All databases' : 'All schedules' }}</option>
                    @foreach ($filters as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            @endif
        </x-slot:actions>

        <div x-data="{ selected: [], all: @js($runs->filter->restorable()->pluck('id')->values()->all()) }" @bulk-done.window="selected = []">
        <div class="fm-selection" x-cloak x-show="selected.length > 0">
            <div class="flex items-center gap-2">
                <button class="icon-btn" type="button" @click="selected = []" aria-label="Clear selection"><x-menu-icon name="close" class="size-4" /></button>
                <span class="font-medium"><span x-text="selected.length"></span> selected</span>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                <button class="btn btn-sm btn-danger" type="button" @click="if (confirm('Delete the selected backups from the bucket? This cannot be undone.')) $wire.deleteBackups(selected)"><x-menu-icon name="trash" /> Delete from bucket</button>
            </div>
        </div>
        <table class="table">
            <thead>
                <tr>
                    <th class="w-10"><input type="checkbox" aria-label="Select all backups" :checked="all.length > 0 && selected.length === all.length" @change="selected = $event.target.checked ? [...all] : []" :disabled="all.length === 0"></th>
                    <th>{{ $isDatabase ? 'Database' : 'Folders' }}</th>
                    <th>Status</th>
                    <th>Size</th>
                    <th>When</th>
                    <th class="w-48"></th>
                </tr>
            </thead>
                @forelse ($runGroups as $key => $group)
                    @if (str_starts_with($key, 'schedule:'))
                        @include('livewire.backups.partials.schedule-group', ['group' => $group, 'first' => $loop->first])
                    @else
                        <tbody x-data="{ open: false }" wire:key="run-group-{{ md5($key) }}">
                            @include('livewire.backups.partials.run-row', ['run' => $group->first(), 'older' => $group->count() - 1, 'nested' => false])
                            @foreach ($group->slice(1) as $run)
                                @include('livewire.backups.partials.run-row', ['run' => $run, 'older' => 0, 'nested' => true])
                            @endforeach
                        </tbody>
                    @endif
                @empty
                    <tbody>
                        <tr>
                            <td colspan="6" class="table-empty">{{ $filter !== '' ? 'No backups for this choice yet.' : 'Nothing yet. Press Run now on a schedule to make the first backup.' }}</td>
                        </tr>
                    </tbody>
                @endforelse
        </table>
        </div>
    </x-layer-card>

    {{-- Log --}}
    <x-modal-frame class="modal-medium" open="$wire.logRunId !== null" close="$wire.set('logRunId', null)">
        <div class="modal-head mb-3">
            <h2>Log · {{ $logRun?->target }}</h2>
            <button class="icon-btn" type="button" wire:click="$set('logRunId', null)" aria-label="Close"><x-menu-icon name="close" /></button>
        </div>
        <pre class="sql-block whitespace-pre-wrap">{{ $logRun?->log ?: 'No log yet.' }}</pre>
    </x-modal-frame>

    @include('livewire.backups.partials.restore-dialog')
    @include('livewire.backups.partials.view-dialog')
    @include('livewire.backups.partials.download-dialog')
    @include('livewire.backups.partials.full-backup-dialog')
</div>
