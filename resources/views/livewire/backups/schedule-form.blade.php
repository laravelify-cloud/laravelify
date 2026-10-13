<div class="flex flex-col gap-6">
    <x-page-header
        :title="$schedule ? 'Edit '.($isDatabase ? 'database' : 'file').' backup' : 'New '.($isDatabase ? 'database' : 'file').' backup'"
        :summary="$isDatabase ? 'Each database is dumped on its own, compressed, and sent to your S3 storage.' : 'The folders are packed into one archive, compressed, and sent to your S3 storage.'"
    >
        <x-slot:actions>
            <a class="btn" href="{{ route($isDatabase ? 'backups.databases' : 'backups.files') }}" wire:navigate>Back</a>
        </x-slot:actions>
    </x-page-header>

    <form class="flex flex-col gap-6" wire:submit="save">
        <x-layer-card title="General">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="field">
                    <span>Name</span>
                    <input class="input" wire:model="name" placeholder="{{ $isDatabase ? 'App database, hourly' : 'Website files, daily' }}" required>
                    @error('name') <small class="error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>Store in</span>
                    <select class="select" wire:model="backup_storage_id">
                        <option value="">Choose a storage</option>
                        @foreach ($storages as $storage)
                            <option value="{{ $storage->id }}">{{ $storage->name }} ({{ $storage->bucket }})</option>
                        @endforeach
                    </select>
                    @if ($storages->isEmpty())
                        <small class="text-muted"><a class="underline" href="{{ route('backups.storages') }}" wire:navigate>Add an S3 storage</a> first.</small>
                    @endif
                    @error('backup_storage_id') <small class="error">{{ $message }}</small> @enderror
                </label>
            </div>
            <label class="mt-4 flex items-center gap-2">
                <input type="checkbox" wire:model="enabled">
                <span>Run on schedule</span>
            </label>
        </x-layer-card>

        <x-layer-card title="{{ $isDatabase ? 'Databases' : 'Folders' }}" helper="{{ $isDatabase ? 'Pick the databases this schedule backs up. Make another schedule for a different frequency.' : 'Folders are inside '.$rootLabel.'. Browse and add as many as you like.' }}">
            @if ($isDatabase)
                <label class="mb-3 flex items-center gap-2">
                    <input type="checkbox" wire:model.live="all_databases">
                    <span class="font-medium">All databases</span>
                    <span class="text-muted">(including ones added later)</span>
                </label>

                @unless ($all_databases)
                    @if ($availableDatabases !== [])
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($availableDatabases as $database)
                                <label class="flex items-center gap-2 rounded-lg border border-line px-3 py-2" wire:key="db-{{ $database }}">
                                    <input type="checkbox" wire:model="databases" value="{{ $database }}">
                                    <x-menu-icon name="database" class="size-4 text-muted" />
                                    <span class="truncate font-mono text-[13px]">{{ $database }}</span>
                                </label>
                            @endforeach
                        </div>
                    @elseif ($pickerError)
                        <p class="error">{{ $pickerError }}</p>
                    @else
                        <p class="text-muted">No databases found. You can type a name below.</p>
                    @endif

                    @php($extras = array_values(array_diff($databases, $availableDatabases)))
                    @if ($extras !== [])
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($extras as $extra)
                                <span class="pill font-mono">{{ $extra }}</span>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-3 flex items-end gap-2">
                        <label class="field flex-1">
                            <span>Another database name</span>
                            <input class="input font-mono" wire:model="extraDatabase" wire:keydown.enter.prevent="addDatabase" placeholder="app_db">
                            @error('extraDatabase') <small class="error">{{ $message }}</small> @enderror
                        </label>
                        <button class="btn" type="button" wire:click="addDatabase">Add</button>
                    </div>
                @endunless
                @error('databases') <small class="error mt-2 block">{{ $message }}</small> @enderror
            @else
                <div class="flex flex-wrap items-center gap-1.5 text-[13px]">
                    <button class="btn" type="button" wire:click="openRoot"><x-menu-icon name="home" /> {{ $rootLabel }}</button>
                    @if ($browse !== '')
                        <button class="btn" type="button" wire:click="openParent"><x-menu-icon name="up" /> Up</button>
                        <span class="font-mono text-muted">/{{ $browse }}</span>
                        <button class="btn btn-primary ml-auto" type="button" wire:click="addBrowsedFolder"><x-menu-icon name="plus" /> Add this folder</button>
                    @else
                        <button class="btn ml-auto" type="button" wire:click="addBrowsedFolder"><x-menu-icon name="plus" /> Add everything in {{ $rootLabel }}</button>
                    @endif
                </div>

                @if ($pickerError)
                    <p class="error mt-3">{{ $pickerError }}</p>
                @else
                    <ul class="mt-3 grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                        @forelse ($folders as $folder)
                            <li class="flex items-center gap-1 rounded-lg border border-line" wire:key="folder-{{ $browse }}-{{ $folder }}">
                                <button class="flex min-w-0 flex-1 items-center gap-2 px-3 py-2 text-left hover:text-accent" type="button" wire:click="openFolder(@js($folder))">
                                    <x-menu-icon name="groups" class="size-4 shrink-0 text-muted" />
                                    <span class="truncate">{{ $folder }}</span>
                                </button>
                                <button class="icon-btn" type="button" title="Add {{ $folder }}" wire:click="addFolder(@js($folder))"><x-menu-icon name="plus" class="size-4" /></button>
                            </li>
                        @empty
                            <li class="text-muted">No subfolders here.</li>
                        @endforelse
                    </ul>
                @endif

                <div class="mt-5 border-t border-line pt-4">
                    <p class="mb-2 font-medium">Selected folders</p>
                    @if ($paths === [])
                        <p class="text-muted">None yet. Add at least one.</p>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach ($paths as $index => $path)
                                <span class="pill gap-1 font-mono" wire:key="path-{{ $index }}-{{ $path }}">
                                    {{ $path === '' ? $rootLabel : $path }}
                                    <button type="button" class="opacity-70 hover:opacity-100" wire:click="removePath({{ $index }})" aria-label="Remove"><x-menu-icon name="close" class="size-3.5" /></button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    @error('paths') <small class="error mt-2 block">{{ $message }}</small> @enderror
                </div>

                <div class="mt-5 border-t border-line pt-4">
                    <p class="mb-1 font-medium">Do not back up</p>
                    <p class="mb-2 text-[12.5px] text-muted">One pattern per line, like <code>node_modules</code> or <code>storage/logs</code>. A name matches in every folder.</p>
                    <div class="mb-3 flex flex-wrap gap-1.5">
                        @foreach ($suggestedExcludes as $pattern)
                            <button type="button" class="pill {{ in_array($pattern, $activeExcludes, true) ? '' : 'opacity-60' }}" wire:click="toggleExclude(@js($pattern))">
                                <x-menu-icon name="{{ in_array($pattern, $activeExcludes, true) ? 'check' : 'plus' }}" class="size-3.5" /> {{ $pattern }}
                            </button>
                        @endforeach
                    </div>
                    <textarea class="textarea font-mono" style="min-height: 96px" wire:model.live.debounce.500ms="excludes" placeholder="node_modules&#10;storage/logs"></textarea>
                    @error('excludes') <small class="error">{{ $message }}</small> @enderror
                </div>
            @endif
        </x-layer-card>

        <x-layer-card title="How often" helper="Times use the timezone from Settings, General.">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <label class="field">
                    <span>Frequency</span>
                    <select class="select" wire:model.live="frequency">
                        @foreach ($frequencies as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('frequency') <small class="error">{{ $message }}</small> @enderror
                </label>

                @if (in_array($frequency, ['minutes', 'hours'], true))
                    <label class="field">
                        <span>Every</span>
                        <div class="flex items-center gap-2">
                            <input class="input" type="number" min="1" max="{{ $frequency === 'hours' ? 23 : 59 }}" wire:model="interval">
                            <span class="text-muted">{{ $frequency === 'hours' ? 'hours' : 'minutes' }}</span>
                        </div>
                        @error('interval') <small class="error">{{ $message }}</small> @enderror
                    </label>
                @endif

                @if ($frequency === 'weekly')
                    <label class="field">
                        <span>Day</span>
                        <select class="select" wire:model="weekday">
                            @foreach ($weekdays as $number => $label)
                                <option value="{{ $number }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if (in_array($frequency, ['hourly', 'hours', 'daily', 'weekly'], true))
                    <label class="field">
                        <span>{{ in_array($frequency, ['hourly', 'hours'], true) ? 'At minute (use 00:MM)' : 'At' }}</span>
                        <input class="input font-mono" wire:model="run_time" placeholder="02:00">
                        @error('run_time') <small class="error">{{ $message }}</small> @enderror
                    </label>
                @endif

                @if ($frequency === 'custom')
                    <label class="field sm:col-span-2">
                        <span>Cron expression</span>
                        <input class="input font-mono" wire:model="custom_cron" placeholder="*/10 * * * *">
                        <small class="text-muted">Minute, hour, day of month, month, day of week.</small>
                        @error('custom_cron') <small class="error">{{ $message }}</small> @enderror
                    </label>
                @endif
            </div>
        </x-layer-card>

        <x-layer-card title="Keep" helper="Older backups are deleted from the bucket after each run. The newest one is always kept.">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="field">
                    <span>Keep the last</span>
                    <div class="flex items-center gap-2">
                        <input class="input" type="number" min="1" max="365" wire:model="retention_count">
                        <span class="text-muted">backups</span>
                    </div>
                    @error('retention_count') <small class="error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>And delete older than (optional)</span>
                    <div class="flex items-center gap-2">
                        <input class="input" type="number" min="1" wire:model="retention_days" placeholder="no limit">
                        <span class="text-muted">days</span>
                    </div>
                    @error('retention_days') <small class="error">{{ $message }}</small> @enderror
                </label>
            </div>
        </x-layer-card>

        <div class="flex gap-2">
            <button class="btn btn-primary" type="submit">Save schedule</button>
            <a class="btn" href="{{ route($isDatabase ? 'backups.databases' : 'backups.files') }}" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
