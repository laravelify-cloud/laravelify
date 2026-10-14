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
        :summary="$isDatabase ? 'Dump databases to your S3 storage on a schedule. Restore a whole database or some tables, look inside a backup, or download parts of it as SQL.' : 'Archive folders to your S3 storage on a schedule. Restore everything or picked folders and files, or download a backup.'"
    >
        <x-slot:actions>
            <button class="btn" type="button" wire:click="fullBackup" wire:loading.attr="disabled" wire:target="fullBackup" @disabled(! $hasStorage) title="{{ $isDatabase ? 'Back up every database now' : 'Back up every website now, each in its own archive, without dependencies and build output' }}"><x-menu-icon name="backup" /> Full backup</button>
            <button class="btn" type="button" wire:click="openRestore" @disabled(! $hasStorage)><x-menu-icon name="restore" /> Restore</button>
            <a class="btn" href="{{ route('backups.storages') }}" wire:navigate><x-menu-icon name="s3" /> Storage</a>
            <a class="btn btn-primary" href="{{ route('backups.schedules.create', $isDatabase ? 'database' : 'files') }}" wire:navigate><x-menu-icon name="plus" /> New schedule</a>
        </x-slot:actions>
    </x-page-header>

    @unless ($hasStorage)
        <div class="callout" role="note">
            <x-menu-icon name="s3" class="size-4 shrink-0 text-muted" />
            <p class="min-w-0 flex-1 text-[13px] text-muted">
                <span class="font-medium text-ink">Add an S3 storage first.</span>
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
    <x-layer-card title="Backups and restores" helper="The latest 30. Look inside, download, or restore any backup that is still in the bucket." flush>
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
            <tbody>
                @forelse ($runs as $run)
                    <tr wire:key="run-{{ $run->id }}-{{ $run->status }}">
                        <td>
                            @if ($run->restorable())
                                <input type="checkbox" aria-label="Select backup {{ $run->id }}" :checked="selected.includes({{ $run->id }})" @change="selected = $event.target.checked ? [...selected, {{ $run->id }}] : selected.filter((id) => id !== {{ $run->id }})">
                            @endif
                        </td>
                        <td class="max-w-xs">
                            <span class="flex items-center gap-2">
                                <x-menu-icon name="{{ $run->isBackup() ? ($run->isDatabase() ? 'database' : 'backup') : 'restore' }}" class="size-4 shrink-0 text-muted" />
                                <span class="truncate font-mono text-[13px]" title="{{ $run->target }}">{{ $run->target }}</span>
                            </span>
                            <span class="block truncate text-[12px] text-muted">
                                @if ($run->isBackup())
                                    {{ $run->schedule?->name ?? 'Deleted schedule' }} · {{ $run->trigger === 'manual' ? 'Run now' : 'Scheduled' }}
                                @else
                                    Restore{{ ! empty($run->meta['tables']) ? ' of '.count($run->meta['tables']).' table(s)' : '' }}{{ ! empty($run->meta['database']) && $run->meta['database'] !== $run->target ? ' into '.$run->meta['database'] : '' }}
                                @endif
                            </span>
                        </td>
                        <td>
                            @if ($run->status === 'running')
                                <div class="min-w-[12rem] max-w-[16rem]">
                                    <div class="flex items-center gap-2 text-[12.5px]">
                                        <span class="task-spinner shrink-0"></span>
                                        <span class="truncate">{{ $run->phase ?: 'Working' }}</span>
                                        @if ($run->progress !== null)
                                            <span class="ml-auto font-medium tabular-nums">{{ $run->progress }}%</span>
                                        @endif
                                    </div>
                                    <div @class(['task-bar', 'is-waiting' => $run->progress === null])><span style="width: {{ max(3, (int) $run->progress) }}%"></span></div>
                                </div>
                            @else
                                <x-status-badge :status="$run->status" />
                                @if ($run->isStuck())
                                    <span class="block max-w-[14rem] text-[11.5px] text-muted">Waiting for the queue worker</span>
                                @endif
                            @endif
                        </td>
                        <td class="text-muted">{{ $run->isBackup() ? $run->sizeLabel() : '—' }}</td>
                        <td class="text-muted">
                            {{ $time->datetime($run->created_at) }}
                            @if ($run->durationLabel())
                                <span class="block text-[12px]">{{ $run->durationLabel() }}</span>
                            @endif
                        </td>
                        <td>
                            <div class="row-actions w-full">
                                @if ($run->restorable())
                                    <button class="icon-btn" type="button" wire:click="openView({{ $run->id }})" title="Look inside" aria-label="Look inside"><x-menu-icon name="eye" /></button>
                                    <button class="icon-btn" type="button" wire:click="openDownload({{ $run->id }})" title="Download" aria-label="Download"><x-menu-icon name="download" /></button>
                                    <button class="icon-btn is-accent" type="button" wire:click="openRestore({{ $run->id }})" title="Restore" aria-label="Restore"><x-menu-icon name="restore" /></button>
                                @endif
                                @include('livewire.backups.partials.row-menu', ['items' => array_filter([
                                    ['label' => 'Show log', 'icon' => 'logs', 'click' => 'showLog('.$run->id.')'],
                                    $run->restorable() ? ['label' => 'Delete backup', 'icon' => 'trash', 'click' => 'deleteBackup('.$run->id.')', 'danger' => true, 'confirm' => 'Delete this backup from the bucket? This cannot be undone.'] : null,
                                ])])
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="table-empty">{{ $filter !== '' ? 'No backups for this choice yet.' : 'Nothing yet. Press Run now on a schedule to make the first backup.' }}</td>
                    </tr>
                @endforelse
            </tbody>
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
</div>
