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

        <x-layer-card title="{{ $isDatabase ? 'Databases' : 'Folders' }}" helper="{{ $isDatabase ? 'Pick the databases this schedule backs up. Make another schedule for a different frequency.' : 'Tick folders and files in '.$rootLabel.' and add them to the backup, or skip ones you do not need.' }}">
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

                    @php $extras = array_values(array_diff($databases, $availableDatabases)); @endphp
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
                <label class="check-card mb-4">
                    <input class="mt-0.5" type="checkbox" wire:model.live="all_websites">
                    <span>
                        <span class="block font-medium">Every website</span>
                        <span class="block text-[12.5px] text-muted">One archive per website, including websites added later. What a deploy rebuilds is skipped by site type: node_modules, vendor, dist, .next, caches, logs.</span>
                    </span>
                </label>

                @if ($all_websites)
                    <div class="overflow-hidden rounded-lg border border-line">
                        <table class="table">
                            <thead>
                                <tr><th>Website</th><th>Folder</th><th>Skipped automatically</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($websiteSites as $site)
                                    <tr wire:key="ws-{{ $site['folder'] }}">
                                        <td><span class="font-medium">{{ $site['name'] }}</span> <span class="pill">{{ $site['type'] }}</span></td>
                                        <td class="font-mono text-[13px] text-muted">{{ $site['folder'] }}</td>
                                        <td>
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($site['skips'] as $skip)
                                                    <span class="badge-exclude font-mono">{{ $skip }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="table-empty">No deployed websites in {{ $rootLabel }} yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                @php
                    $excludeList = $activeExcludes;
                    $crumbs = [];
                    $prefix = '';
                    foreach ($browse === '' ? [] : explode('/', $browse) as $part) {
                        $prefix = ltrim($prefix.'/'.$part, '/');
                        $crumbs[] = ['label' => $part, 'path' => $prefix];
                    }
                    $included = function (string $path) use ($paths) {
                        foreach ($paths as $chosen) {
                            if ($chosen === '' || $path === $chosen || str_starts_with($path, $chosen.'/')) {
                                return true;
                            }
                        }

                        return false;
                    };
                @endphp

                <div
                    class="card fm overflow-hidden"
                    x-data="{
                        selected: [],
                        query: '',
                        rows: @js(array_column($listing, 'relative')),
                        names: @js(array_combine(array_column($listing, 'relative'), array_column($listing, 'name')) ?: (object) []),
                        matches(name) { return this.query.trim() === '' || name.toLowerCase().includes(this.query.trim().toLowerCase()); },
                        visible() { return this.rows.filter((path) => this.matches(this.names[path] ?? path)); },
                        toggle(path) { this.selected = this.selected.includes(path) ? this.selected.filter((item) => item !== path) : this.selected.concat([path]); },
                        allSelected() { const visible = this.visible(); return visible.length > 0 && visible.every((path) => this.selected.includes(path)); },
                        toggleAll() { this.selected = this.allSelected() ? [] : this.visible(); },
                    }"
                    @picker-done.window="selected = []"
                    wire:key="picker-{{ md5($browse) }}"
                >
                    <div class="fm-toolbar">
                        <div class="fm-nav">
                            <button class="icon-btn" type="button" wire:click="openParent" title="Up one folder" aria-label="Up one folder" @disabled($browse === '')><x-menu-icon name="up" /></button>
                            <nav class="fm-crumbs" aria-label="Current folder">
                                <button type="button" class="fm-crumb" wire:click="openRoot"><x-menu-icon name="home" class="size-4" /> <span>{{ basename($rootLabel) ?: '/' }}</span></button>
                                @foreach ($crumbs as $crumb)
                                    <x-menu-icon name="chevron-right" class="size-3.5 shrink-0 text-muted" />
                                    <button type="button" class="fm-crumb" wire:click="openPath(@js($crumb['path']))">{{ $crumb['label'] }}</button>
                                @endforeach
                            </nav>
                        </div>
                        <div class="fm-tools">
                            <label class="fm-search">
                                <x-menu-icon name="search" class="size-4" />
                                <input type="search" x-model="query" placeholder="Filter this folder" autocomplete="off">
                            </label>
                            <button class="btn btn-sm" type="button" wire:click="addBrowsedFolder" title="Back up the folder you are in">
                                <x-menu-icon name="plus" /> {{ $browse === '' ? 'Back up everything' : 'Back up this folder' }}
                            </button>
                        </div>
                    </div>

                    <div class="fm-selection" x-cloak x-show="selected.length > 0">
                        <div class="flex items-center gap-2">
                            <button class="icon-btn" type="button" @click="selected = []" aria-label="Clear selection"><x-menu-icon name="close" class="size-4" /></button>
                            <span class="font-medium"><span x-text="selected.length"></span> selected</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button class="btn btn-sm btn-primary" type="button" @click="$wire.includePaths(selected)"><x-menu-icon name="plus" /> Add to backup</button>
                            <button class="btn btn-sm" type="button" @click="$wire.excludePaths(selected)"><x-menu-icon name="ban" /> Skip</button>
                        </div>
                    </div>

                    @if ($pickerError)
                        <p class="fm-alert">{{ $pickerError }}</p>
                    @elseif ($listing === [])
                        <p class="px-4 py-8 text-center text-muted">This folder is empty.</p>
                    @else
                        <div class="fm-table" role="table" style="max-height: 30rem; overflow-y: auto">
                            <div class="fm-row fm-row-head" role="row">
                                <span class="fm-check" role="columnheader">
                                    <input type="checkbox" aria-label="Select all" :checked="allSelected()" :indeterminate="selected.length > 0 && ! allSelected()" @change="toggleAll()">
                                </span>
                                <span role="columnheader">Name</span>
                                <span role="columnheader" class="fm-col-size">Size</span>
                                <span role="columnheader" class="fm-col-date">Modified</span>
                                <span role="columnheader" class="fm-col-perm">In backup</span>
                                <span role="columnheader" class="sr-only">Actions</span>
                            </div>
                            @foreach ($listing as $row)
                                @php $isSkipped = \App\Services\FileArchiver::excluded($row['relative'], $excludeList); @endphp
                                @php $isIncluded = ! $isSkipped && $included($row['relative']); @endphp
                                <div class="fm-row" role="row" wire:key="pk-{{ $row['relative'] }}" x-show="matches(@js($row['name']))" :class="{ 'is-selected': selected.includes(@js($row['relative'])) }">
                                    <span class="fm-check" role="cell">
                                        <input type="checkbox" aria-label="Select {{ $row['name'] }}" :checked="selected.includes(@js($row['relative']))" @change="toggle(@js($row['relative']))">
                                    </span>
                                    <button type="button" class="fm-name-cell" role="cell" @if ($row['dir']) wire:click="openPath(@js($row['relative']))" @else @click="toggle(@js($row['relative']))" @endif>
                                        @include('livewire.websites.partials.file-icon', ['kind' => $row['dir'] ? 'folder' : 'file'])
                                        <span class="fm-name">{{ $row['name'] }}</span>
                                        @if ($row['link'])
                                            <span class="pill">link</span>
                                        @endif
                                    </button>
                                    <span role="cell" class="fm-col-size">{{ $row['dir'] ? '—' : \Illuminate\Support\Number::fileSize($row['size'], precision: 1) }}</span>
                                    <span role="cell" class="fm-col-date">{{ $row['modified'] ? app(\App\Services\DisplayTime::class)->datetime($row['modified']) : '' }}</span>
                                    <span role="cell" class="fm-col-perm">
                                        @if ($isSkipped)
                                            <span class="badge-exclude">Skipped</span>
                                        @elseif ($isIncluded)
                                            <span class="badge-include">Included</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </span>
                                    <span role="cell" class="fm-row-actions">
                                        @if ($isSkipped)
                                            <button class="icon-btn" type="button" title="Stop skipping" aria-label="Stop skipping {{ $row['name'] }}" wire:click="includePaths(@js([$row['relative']]))"><x-menu-icon name="refresh" class="size-4" /></button>
                                        @elseif (! $isIncluded)
                                            <button class="icon-btn" type="button" title="Add to backup" aria-label="Add {{ $row['name'] }} to the backup" wire:click="includePaths(@js([$row['relative']]))"><x-menu-icon name="plus" class="size-4" /></button>
                                        @endif
                                        @unless ($isSkipped)
                                            <button class="icon-btn icon-btn-danger" type="button" title="Skip" aria-label="Skip {{ $row['name'] }}" wire:click="excludePaths(@js([$row['relative']]))"><x-menu-icon name="ban" class="size-4" /></button>
                                        @endunless
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="mt-5 grid gap-5 lg:grid-cols-2">
                    <div>
                        <p class="mb-2 font-medium">Backed up <span class="text-muted">({{ count($paths) }})</span></p>
                        @if ($paths === [])
                            <p class="text-[13px] text-muted">Nothing yet. Tick folders or files above and press Add to backup.</p>
                        @else
                            <div class="flex flex-wrap gap-2">
                                @foreach ($paths as $index => $path)
                                    <span class="pill gap-1 font-mono" wire:key="path-{{ $index }}-{{ $path }}">
                                        <x-menu-icon name="{{ $path === '' ? 'home' : 'groups' }}" class="size-3.5" /> {{ $path === '' ? basename($rootLabel).' (everything)' : $path }}
                                        <button type="button" class="opacity-70 hover:opacity-100" wire:click="removePath({{ $index }})" aria-label="Remove {{ $path }}"><x-menu-icon name="close" class="size-3.5" /></button>
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        @error('paths') <small class="error mt-2 block">{{ $message }}</small> @enderror
                    </div>

                    <div>
                        <p class="mb-2 font-medium">Skipped <span class="text-muted">({{ count($activeExcludes) }})</span></p>
                        @if ($activeExcludes !== [])
                            <div class="mb-3 flex flex-wrap gap-2">
                                @foreach ($activeExcludes as $pattern)
                                    <span class="pill gap-1 font-mono" wire:key="ex-{{ $pattern }}">
                                        <x-menu-icon name="ban" class="size-3.5" /> {{ $pattern }}
                                        <button type="button" class="opacity-70 hover:opacity-100" wire:click="removeExclude(@js($pattern))" aria-label="Stop skipping {{ $pattern }}"><x-menu-icon name="close" class="size-3.5" /></button>
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        <p class="mb-1.5 text-[12.5px] text-muted">Common ones (a name is skipped in every folder):</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (array_diff($suggestedExcludes, $activeExcludes) as $pattern)
                                <button type="button" class="pill opacity-70 hover:opacity-100" wire:click="toggleExclude(@js($pattern))"><x-menu-icon name="plus" class="size-3.5" /> {{ $pattern }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <details class="mt-4">
                    <summary class="cursor-pointer text-[13px] text-muted">Edit skip patterns as text</summary>
                    <textarea class="textarea mt-2 font-mono" style="min-height: 96px" wire:model.live.debounce.500ms="excludes" placeholder="node_modules&#10;storage/logs&#10;*.log"></textarea>
                    <small class="text-muted">One per line. A name (node_modules) is skipped in every folder; a path (shop/storage/logs) only there. * and ? work.</small>
                    @error('excludes') <small class="error block">{{ $message }}</small> @enderror
                </details>
                @endif
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
