<div class="flex flex-col gap-6" @if ($active) wire:poll.3s @endif>
    <x-page-header
        :title="$isDatabase ? 'Database backups' : 'File backups'"
        :summary="$isDatabase ? 'Dump databases on a schedule, one by one, to your S3 storage. Restore a whole database or only some tables.' : 'Archive folders on a schedule to your S3 storage, without the folders you exclude. Restore everything or one folder.'"
    >
        <x-slot:actions>
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

    <x-layer-card title="Schedules" flush>
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>{{ $isDatabase ? 'Databases' : 'Folders' }}</th>
                    <th>When</th>
                    <th>Last run</th>
                    <th class="w-72"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($schedules as $schedule)
                    @php($last = $schedule->runs()->where('type', 'backup')->latest('id')->first())
                    <tr wire:key="schedule-{{ $schedule->id }}">
                        <td>
                            <span class="font-medium {{ $schedule->enabled ? '' : 'text-muted' }}">{{ $schedule->name }}</span>
                            @unless ($schedule->enabled)
                                <span class="pill">Paused</span>
                            @endunless
                            <span class="block text-[12px] text-muted">{{ $schedule->storage?->name ?? 'No storage' }} · keep {{ $schedule->retention_count }}{{ $schedule->retention_days ? ' / '.$schedule->retention_days.' days' : '' }}</span>
                        </td>
                        <td class="max-w-xs truncate font-mono text-[13px] text-muted" title="{{ $schedule->describeTargets() }}">{{ $schedule->describeTargets() ?: '—' }}</td>
                        <td class="text-muted">{{ $schedule->describeFrequency() }}</td>
                        <td>
                            @if ($last)
                                <x-status-badge :status="$last->status" />
                                <span class="block text-[12px] text-muted">{{ app(\App\Services\DisplayTime::class)->ago($last->created_at) }}</span>
                            @else
                                <span class="text-muted">Never</span>
                            @endif
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap justify-end gap-1.5">
                                <button class="btn" type="button" wire:click="runNow({{ $schedule->id }})" @disabled($running[$schedule->id] ?? false)><x-menu-icon name="play" /> Run now</button>
                                <button class="btn" type="button" wire:click="toggle({{ $schedule->id }})">{{ $schedule->enabled ? 'Pause' : 'Resume' }}</button>
                                <a class="btn" href="{{ route('backups.schedules.edit', $schedule) }}" wire:navigate>Edit</a>
                                <button class="btn btn-danger" type="button" wire:click="delete({{ $schedule->id }})" wire:confirm="Delete this schedule? Backups already made are kept.">Delete</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="table-empty">
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
    </x-layer-card>

    <x-layer-card title="Backups and restores" helper="The latest 30. A backup can be restored while its file is still in the bucket." flush>
        <table class="table">
            <thead>
                <tr>
                    <th>{{ $isDatabase ? 'Database' : 'Folders' }}</th>
                    <th>Status</th>
                    <th>Size</th>
                    <th>When</th>
                    <th class="w-64"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($runs as $run)
                    <tr wire:key="run-{{ $run->id }}">
                        <td class="max-w-xs">
                            <span class="flex items-center gap-2">
                                <x-menu-icon name="{{ $run->isBackup() ? 'backup' : 'restore' }}" class="size-4 shrink-0 text-muted" />
                                <span class="truncate font-mono text-[13px]" title="{{ $run->target }}">{{ $run->target }}</span>
                            </span>
                            <span class="block text-[12px] text-muted">
                                {{ $run->isBackup() ? ($run->schedule?->name ?? 'Deleted schedule').' · '.$run->trigger : 'Restore of the backup from '.($run->source?->created_at ? app(\App\Services\DisplayTime::class)->datetime($run->source->created_at) : 'a deleted backup') }}
                            </span>
                        </td>
                        <td><x-status-badge :status="$run->status" /></td>
                        <td class="text-muted">{{ $run->isBackup() ? $run->sizeLabel() : '—' }}</td>
                        <td class="text-muted">
                            {{ app(\App\Services\DisplayTime::class)->datetime($run->created_at) }}
                            @if ($run->durationLabel())
                                <span class="block text-[12px]">{{ $run->durationLabel() }}</span>
                            @endif
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap justify-end gap-1.5">
                                <button class="btn" type="button" wire:click="showLog({{ $run->id }})">Log</button>
                                @if ($run->restorable())
                                    <button class="btn btn-primary" type="button" wire:click="openRestore({{ $run->id }})"><x-menu-icon name="restore" /> Restore</button>
                                    <button class="btn btn-danger" type="button" wire:click="deleteBackup({{ $run->id }})" wire:confirm="Delete this backup from the bucket? This cannot be undone.">Delete</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="table-empty">Nothing yet. Press Run now on a schedule to make the first backup.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-layer-card>

    {{-- Log --}}
    <div>
        <x-modal-frame open="$wire.logRunId !== null" close="$wire.set('logRunId', null)">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Log: {{ $logRun?->target }}</h2>
                <button class="btn" type="button" wire:click="$set('logRunId', null)">Close</button>
            </div>
            <pre class="log-view max-h-[60vh] overflow-auto rounded-lg border border-line bg-raised p-3 font-mono text-[12.5px] whitespace-pre-wrap">{{ $logRun?->log ?: 'No log yet.' }}</pre>
        </x-modal-frame>
    </div>

    {{-- Restore --}}
    <div>
        <x-modal-frame open="$wire.restoreRunId !== null" close="$wire.closeRestore()">
            @if ($restoreRun)
                <form class="grid gap-4" wire:submit="restore">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold">Restore {{ $restoreRun->isDatabase() ? 'a database' : 'files' }}</h2>
                            <p class="text-[13px] text-muted">From <span class="font-mono">{{ $restoreRun->target }}</span>, made {{ app(\App\Services\DisplayTime::class)->datetime($restoreRun->created_at) }} ({{ $restoreRun->sizeLabel() }}).</p>
                        </div>
                        <button class="btn" type="button" wire:click="closeRestore">Close</button>
                    </div>

                    @if ($restoreRun->isDatabase())
                        <label class="field">
                            <span>Restore into database</span>
                            <input class="input font-mono" wire:model="restoreDatabase">
                            <small class="text-muted">The same name overwrites the live database. Use a new name to restore next to it. It is created when missing.</small>
                            @error('restoreDatabase') <small class="error">{{ $message }}</small> @enderror
                        </label>

                        <div class="field">
                            <span>What to restore</span>
                            <div class="segmented self-start" role="group">
                                <button type="button" aria-pressed="{{ $restoreWhole ? 'true' : 'false' }}" wire:click="$set('restoreWhole', true)">Whole database</button>
                                <button type="button" aria-pressed="{{ $restoreWhole ? 'false' : 'true' }}" wire:click="$set('restoreWhole', false)">Some tables</button>
                            </div>
                        </div>

                        @unless ($restoreWhole)
                            <div class="grid max-h-56 gap-1.5 overflow-auto sm:grid-cols-2">
                                @forelse (($restoreRun->meta['tables'] ?? []) as $table)
                                    <label class="flex items-center gap-2 rounded-lg border border-line px-3 py-1.5" wire:key="rt-{{ $table }}">
                                        <input type="checkbox" wire:model="restoreTables" value="{{ $table }}">
                                        <x-menu-icon name="table" class="size-4 text-muted" />
                                        <span class="truncate font-mono text-[13px]">{{ $table }}</span>
                                    </label>
                                @empty
                                    <p class="text-muted">This backup has no table list.</p>
                                @endforelse
                            </div>
                            @error('restoreTables') <small class="error">{{ $message }}</small> @enderror
                        @endunless
                    @else
                        <div class="field">
                            <span>What to restore</span>
                            <div class="segmented self-start" role="group">
                                <button type="button" aria-pressed="{{ $restoreWhole ? 'true' : 'false' }}" wire:click="$set('restoreWhole', true)">Everything</button>
                                <button type="button" aria-pressed="{{ $restoreWhole ? 'false' : 'true' }}" wire:click="$set('restoreWhole', false)">One folder</button>
                            </div>
                        </div>

                        @unless ($restoreWhole)
                            <label class="field">
                                <span>Folder</span>
                                <select class="select font-mono" wire:model="restoreMember">
                                    <option value="">Choose a folder</option>
                                    @foreach (($restoreRun->meta['entries'] ?? []) as $entry)
                                        <option value="{{ $entry }}">{{ $entry }}</option>
                                    @endforeach
                                </select>
                                <small class="text-muted">A file or folder path inside the backup works too: type it in the folder box below.</small>
                                <input class="input font-mono" wire:model="restoreMember" placeholder="site/public/uploads">
                                @error('restoreMember') <small class="error">{{ $message }}</small> @enderror
                            </label>
                        @endunless

                        <div class="field">
                            <span>Where</span>
                            <div class="segmented self-start" role="group">
                                <button type="button" aria-pressed="{{ $restoreToOriginal ? 'false' : 'true' }}" wire:click="$set('restoreToOriginal', false)">A new restore folder</button>
                                <button type="button" aria-pressed="{{ $restoreToOriginal ? 'true' : 'false' }}" wire:click="$set('restoreToOriginal', true)">Original place</button>
                            </div>
                            <small class="text-muted">{{ $restoreToOriginal ? 'Files with the same name are overwritten.' : 'Safest: the files are unpacked into laravelify-restore/ inside the file manager root, and nothing is overwritten.' }}</small>
                        </div>
                    @endif

                    @if ($restoreRun->isDatabase() || $restoreToOriginal)
                        <label class="modal-warning flex items-start gap-2">
                            <input class="mt-1" type="checkbox" wire:model="understood">
                            <span>I understand this overwrites current {{ $restoreRun->isDatabase() ? 'data in '.($restoreDatabase ?: 'the database') : 'files' }} with the backup.</span>
                        </label>
                        @error('understood') <small class="error">{{ $message }}</small> @enderror
                    @endif

                    <div class="flex justify-end gap-2 border-t border-line pt-4">
                        <button class="btn" type="button" wire:click="closeRestore">Cancel</button>
                        <button class="btn btn-primary" type="submit"><x-menu-icon name="restore" /> Start restore</button>
                    </div>
                </form>
            @endif
        </x-modal-frame>
    </div>
</div>
