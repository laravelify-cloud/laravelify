@php
    $rootPathLabel = $workingDirectory !== '' ? $workingDirectory : $configuredRoot;
    $rootLabel = basename(str_replace('\\', '/', $rootPathLabel)) ?: '/';
    $downloadUrl = fn (string $path, bool $inline = false) => $downloadBase.'?'.http_build_query(array_filter(['path' => $path, 'inline' => $inline ? 1 : null], fn ($value) => $value !== null));
    $sortIcon = fn (string $column) => $sortBy === $column ? ($sortDescending ? '↓' : '↑') : '';
    $targetOf = fn (array $entry) => [
        'relative' => $entry['relative'],
        'name' => $entry['name'],
        'directory' => $entry['directory'],
        'link' => $entry['link'],
        'extension' => $entry['extension'],
        'permissions' => $entry['permissions'],
    ];
@endphp

<div
    class="card fm"
    x-data="fileManager({
        downloadBase: @js($downloadBase),
        root: @js($workingDirectory),
        rootLabel: @js($rootLabel),
        canZip: @js($canZip),
    })"
    @dragenter="dragEnter($event)"
    @dragover.prevent="dragOver($event)"
    @dragleave="dragLeave($event)"
    @drop.prevent="drop($event, $wire.directory)"
    @keydown.window="shortcut($event)"
    @directory-changed.window="clearSelection()"
    @entry-created.window="dialog = null"
    @entry-deleted.window="dialog = null; clearSelection()"
    @entries-transferred.window="dialog = null; clearSelection()"
    @entry-chmodded.window="dialog = null"
    @file-saved.window="dirty = false"
    @entries-queued.window="clearSelection()"
    @download-ready.window="window.location.href = $event.detail.url"
>
    <input class="sr-only" type="file" multiple x-ref="picker" @change="upload($event.target.files, pickTarget ?? $wire.directory); $event.target.value = ''">

    {{-- Header --}}
    <div class="fm-header">
        <div class="min-w-0">
            <h2 class="fm-title">File manager</h2>
            <p class="fm-subtitle">
                <code class="truncate">{{ $rootPathLabel }}</code>
            </p>
        </div>
        <div class="fm-actions">
            <button class="btn" type="button" @click="openDialog('folder')" @disabled($listingError !== '')>
                <x-menu-icon name="folder-plus" /> New folder
            </button>
            <button class="btn" type="button" @click="openDialog('file')" @disabled($listingError !== '')>
                <x-menu-icon name="file-plus" /> New file
            </button>
            <button class="btn btn-primary" type="button" @click="pick(null)" :disabled="uploading || @js($listingError !== '')">
                <x-menu-icon name="upload" /> Upload
            </button>
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="fm-toolbar">
        <div class="fm-nav">
            <button class="icon-btn" type="button" wire:click="up" title="Up one folder" aria-label="Up one folder" @disabled($directory === '')>
                <x-menu-icon name="up" />
            </button>
            <button class="icon-btn" type="button" wire:click="refreshList" title="Refresh" aria-label="Refresh">
                <x-menu-icon name="refresh" wire:loading.class="animate-spin" wire:target="refreshList" />
            </button>
            <div class="fm-path" :class="editingPath && 'is-editing'" @click.self="editPath()" title="Click empty space to type a path">
                <nav class="fm-crumbs" aria-label="Current folder" x-show="!editingPath" @click.self="editPath()">
                    <button type="button" class="fm-crumb" wire:click="openDirectory('')" @if ($directory === '') aria-current="page" @endif>
                        <x-menu-icon name="home" class="size-4" />
                        <span>{{ $rootLabel }}</span>
                    </button>
                    @foreach ($crumbs as $crumb)
                        <x-menu-icon name="chevron-right" class="size-3.5 shrink-0 text-muted" />
                        <button type="button" class="fm-crumb" wire:click="openDirectory(@js($crumb['relative']))" @if ($loop->last) aria-current="page" @endif>{{ $crumb['label'] }}</button>
                    @endforeach
                </nav>
                <span class="fm-path-prefix" x-cloak x-show="editingPath"><x-menu-icon name="home" class="size-4" /></span>
                <input
                    class="fm-path-input"
                    x-cloak
                    x-show="editingPath"
                    x-ref="pathInput"
                    x-model="pathValue"
                    @keydown.enter.prevent="submitPath()"
                    @keydown.escape.prevent.stop="cancelPath()"
                    @blur="cancelPath()"
                    placeholder="/public/index.php"
                    aria-label="Path to open"
                    autocomplete="off"
                    spellcheck="false"
                >
                <span class="fm-path-hint" x-cloak x-show="editingPath"><kbd>Enter</kbd> go · <kbd>Esc</kbd> cancel</span>
                <button class="icon-btn fm-path-edit" type="button" x-show="!editingPath" @click="editPath()" title="Type a path (Ctrl+L)" aria-label="Type a path">
                    <x-menu-icon name="pencil" class="size-4" />
                </button>
                <button class="icon-btn fm-path-edit" type="button" x-show="!editingPath" @click="copyText(joinRoot($wire.directory), 'Path copied.')" title="Copy full path" aria-label="Copy full path">
                    <x-menu-icon name="copy" class="size-4" />
                </button>
            </div>
        </div>
        <div class="fm-tools">
            <label class="fm-search">
                <x-menu-icon name="search" class="size-4" />
                <input type="search" x-ref="search" x-model="query" placeholder="Filter this folder" aria-label="Filter this folder" autocomplete="off">
                <kbd x-show="query === ''">/</kbd>
            </label>
            <div class="segmented" role="group" aria-label="View">
                <button type="button" title="List view" aria-label="List view" :aria-pressed="view === 'list'" @click="setView('list')"><x-menu-icon name="list" class="size-4" /></button>
                <button type="button" title="Grid view" aria-label="Grid view" :aria-pressed="view === 'grid'" @click="setView('grid')"><x-menu-icon name="grid" class="size-4" /></button>
            </div>
        </div>
    </div>

    {{-- Selection bar --}}
    <div class="fm-selection" x-cloak x-show="selected.length > 0" x-transition.opacity>
        <div class="flex items-center gap-2">
            <button class="icon-btn" type="button" @click="clearSelection()" title="Clear selection" aria-label="Clear selection"><x-menu-icon name="close" class="size-4" /></button>
            <span class="font-medium"><span x-text="selected.length"></span> selected</span>
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            <button class="btn" type="button" @click="askTransfer('copy', selectedTargets())"><x-menu-icon name="copy" /> Copy to…</button>
            <button class="btn" type="button" @click="askTransfer('move', selectedTargets())"><x-menu-icon name="move" /> Move to…</button>
            @if ($canZip)
                <button class="btn" type="button" @click="askCompress(selectedTargets())"><x-menu-icon name="archive" /> Compress to .zip</button>
            @endif
            <button class="btn btn-danger" type="button" @click="askDelete(selectedTargets())"><x-menu-icon name="trash" /> Delete</button>
        </div>
    </div>

    {{-- Upload progress --}}
    <div class="fm-progress" x-cloak x-show="uploading" x-transition.opacity>
        <div class="flex items-center justify-between gap-3">
            <span class="truncate" x-text="uploadLabel"></span>
            <span class="tabular-nums text-muted" x-text="progress + '%'"></span>
        </div>
        <div class="fm-progress-track"><span :style="`width: ${progress}%`"></span></div>
    </div>

    <x-task-tray :tasks="$tasks" :active="$tasksActive" class="fm-tasks" />

    @error('uploads') <p class="fm-alert">{{ $message }}</p> @enderror
    @error('uploads.*') <p class="fm-alert">{{ $message }}</p> @enderror

    {{-- Body --}}
    <div class="fm-body" wire:loading.class="is-busy" wire:target="openDirectory, up, refreshList, sort">
        @if ($listingError !== '')
            <section class="fm-state" wire:key="fm-state-error-{{ md5($directory) }}">
                <span class="fm-state-badge is-danger"><x-menu-icon name="danger" /></span>
                <h3>Cannot open this folder</h3>
                <p>{{ $listingError }}</p>
            </section>
        @elseif ($entries === [])
            <section class="fm-state" wire:key="fm-state-empty-{{ md5($directory) }}">
                <span class="fm-state-badge">@include('livewire.websites.partials.file-icon', ['kind' => 'folder'])</span>
                <h3>This folder is empty</h3>
                <p>Drop files anywhere in this panel, or create something new.</p>
                <div class="fm-state-actions">
                    <button class="btn btn-primary" type="button" @click="pick(null)"><x-menu-icon name="upload" /> Upload files</button>
                    <button class="btn" type="button" @click="openDialog('file')"><x-menu-icon name="file-plus" /> New file</button>
                    <button class="btn" type="button" @click="openDialog('folder')"><x-menu-icon name="folder-plus" /> New folder</button>
                </div>
            </section>
        @else
            {{-- List view --}}
            <div class="fm-table" role="table" wire:key="fm-list-{{ md5($directory) }}" x-show="view === 'list'">
                <div class="fm-row fm-row-head" role="row">
                    <span class="fm-check" role="columnheader">
                        <input type="checkbox" aria-label="Select all" :checked="allSelected()" :indeterminate="selected.length > 0 && !allSelected()" @change="toggleAll()">
                    </span>
                    <button type="button" role="columnheader" wire:click="sort('name')">Name <span>{{ $sortIcon('name') }}</span></button>
                    <button type="button" role="columnheader" class="fm-col-size" wire:click="sort('size')">Size <span>{{ $sortIcon('size') }}</span></button>
                    <button type="button" role="columnheader" class="fm-col-date" wire:click="sort('modified')">Modified <span>{{ $sortIcon('modified') }}</span></button>
                    <span role="columnheader" class="fm-col-perm">Permissions</span>
                    <span role="columnheader" class="sr-only">Actions</span>
                </div>
                @foreach ($entries as $entry)
                    @php($target = $targetOf($entry))
                    <div
                        class="fm-row"
                        role="row"
                        wire:key="row-{{ $entry['relative'] }}"
                        x-show="matches(@js($entry['name']))"
                        :class="{ 'is-selected': isSelected(@js($entry['relative'])), 'is-drop': dropTarget === @js($entry['relative']) }"
                        @contextmenu.prevent="openMenu($event, @js($target))"
                        @if ($entry['directory'])
                            @dragover.prevent.stop="dropTarget = @js($entry['relative'])"
                            @dragleave="dropTarget = null"
                            @drop.prevent.stop="drop($event, @js($entry['relative']))"
                        @endif
                    >
                        <span class="fm-check" role="cell">
                            <input type="checkbox" aria-label="Select {{ $entry['name'] }}" :checked="isSelected(@js($entry['relative']))" @change="toggle(@js($entry['relative']))">
                        </span>
                        <button
                            type="button"
                            class="fm-name-cell"
                            role="cell"
                            @if ($entry['directory'])
                                wire:click="openDirectory(@js($entry['relative']))"
                            @elseif (! $entry['link'])
                                wire:click="openFile(@js($entry['relative']))"
                            @endif
                        >
                            @include('livewire.websites.partials.file-icon', ['kind' => $entry['kind']])
                            <span class="fm-name">{{ $entry['name'] }}</span>
                            @if ($entry['link'])
                                <span class="pill">link</span>
                            @endif
                        </button>
                        <span role="cell" class="fm-col-size">{{ $entry['directory'] ? '—' : $entry['size'] }}</span>
                        <span role="cell" class="fm-col-date">{{ $entry['modified'] }}</span>
                        <span role="cell" class="fm-col-perm"><code>{{ $entry['permissions'] ?: '—' }}</code></span>
                        <span role="cell" class="fm-row-actions">
                            @if ($entry['directory'])
                                <button class="icon-btn" type="button" title="Upload into {{ $entry['name'] }}" aria-label="Upload into {{ $entry['name'] }}" @click="pick(@js($entry['relative']))"><x-menu-icon name="upload" class="size-4" /></button>
                            @elseif (! $entry['link'])
                                <a class="icon-btn" title="Download" aria-label="Download {{ $entry['name'] }}" href="{{ $downloadUrl($entry['relative']) }}" download><x-menu-icon name="download" class="size-4" /></a>
                            @endif
                            <button class="icon-btn" type="button" title="More actions" aria-label="More actions for {{ $entry['name'] }}" @click.stop="openMenu($event, @js($target))"><x-menu-icon name="more" class="icon-dots size-4" /></button>
                        </span>
                    </div>
                @endforeach
            </div>

            {{-- Grid view --}}
            <div class="fm-grid" wire:key="fm-grid-{{ md5($directory) }}" x-cloak x-show="view === 'grid'">
                @foreach ($entries as $entry)
                    @php($target = $targetOf($entry))
                    <div
                        class="fm-tile"
                        wire:key="tile-{{ $entry['relative'] }}"
                        x-show="matches(@js($entry['name']))"
                        :class="{ 'is-selected': isSelected(@js($entry['relative'])), 'is-drop': dropTarget === @js($entry['relative']) }"
                        @contextmenu.prevent="openMenu($event, @js($target))"
                        @if ($entry['directory'])
                            @dragover.prevent.stop="dropTarget = @js($entry['relative'])"
                            @dragleave="dropTarget = null"
                            @drop.prevent.stop="drop($event, @js($entry['relative']))"
                        @endif
                    >
                        <input class="fm-tile-check" type="checkbox" aria-label="Select {{ $entry['name'] }}" :checked="isSelected(@js($entry['relative']))" @change="toggle(@js($entry['relative']))">
                        <button
                            type="button"
                            class="fm-tile-main"
                            @if ($entry['directory'])
                                wire:click="openDirectory(@js($entry['relative']))"
                            @elseif (! $entry['link'])
                                wire:click="openFile(@js($entry['relative']))"
                            @endif
                        >
                            @if ($entry['kind'] === 'image' && ! $entry['link'])
                                <img class="fm-thumb" src="{{ $downloadUrl($entry['relative'], true) }}" alt="" loading="lazy">
                            @else
                                @include('livewire.websites.partials.file-icon', ['kind' => $entry['kind']])
                            @endif
                            <span class="fm-name" title="{{ $entry['name'] }}">{{ $entry['name'] }}</span>
                            <span class="fm-meta">{{ $entry['directory'] ? 'Folder' : $entry['size'] }}</span>
                        </button>
                        <button class="icon-btn fm-tile-more" type="button" aria-label="More actions for {{ $entry['name'] }}" @click.stop="openMenu($event, @js($target))"><x-menu-icon name="more" class="icon-dots size-4" /></button>
                    </div>
                @endforeach
            </div>

            <p class="fm-empty-filter" x-cloak x-show="query !== '' && ! $wire.entries.some(entry => matches(entry.name))">
                Nothing in this folder matches “<span x-text="query"></span>”.
            </p>
        @endif

        {{-- Drop overlay --}}
        <div class="fm-dropzone" x-cloak x-show="dragging && dropTarget === null" x-transition.opacity>
            <x-menu-icon name="upload" class="size-8" />
            <p class="font-medium">Drop to upload into <span x-text="$wire.directory === '' ? rootLabel : '/' + $wire.directory"></span></p>
            <p class="text-muted">Drop onto a folder to upload there instead.</p>
        </div>
    </div>

    {{-- Footer --}}
    <div class="fm-footer">
        <span>
            {{ $folderCount }} {{ \Illuminate\Support\Str::plural('folder', $folderCount) }},
            {{ $fileCount }} {{ \Illuminate\Support\Str::plural('file', $fileCount) }}
        </span>
        <span class="hidden md:inline">Right-click an item for more actions · Drag files in to upload · Max 32 MB per file</span>
        @if ($canZip && $listingError === '' && ($website || $directory !== ''))
            <button class="fm-link" type="button" wire:click="prepareDownload(@js($directory))">
                <x-menu-icon name="archive" class="size-4" /> Download folder (.zip)
            </button>
        @endif
    </div>

    {{-- Action menu --}}
    <template x-teleport="body">
        <div
            class="fm-menu"
            x-cloak
            x-show="menu.open"
            x-transition:enter="dropdown-in"
            x-transition:enter-start="dropdown-from"
            x-transition:enter-end="dropdown-to"
            x-transition:leave="dropdown-out"
            x-transition:leave-start="dropdown-to"
            x-transition:leave-end="dropdown-from"
            x-ref="menu"
            :style="`left: ${menu.x}px; top: ${menu.y}px`"
            @click.outside="menu.open = false"
            @keydown.escape.window="menu.open = false"
            @scroll.window="menu.open = false"
            @resize.window="menu.open = false"
            role="menu"
        >
            <p class="fm-menu-title" x-text="menu.target.name"></p>
            <button type="button" role="menuitem" x-show="!menu.target.link" @click="act('open')">
                <x-menu-icon name="eye" /> <span x-text="menu.target.directory ? 'Open folder' : 'Open'"></span>
            </button>
            <button type="button" role="menuitem" x-show="menu.target.directory" @click="act('upload')">
                <x-menu-icon name="upload" /> Upload here
            </button>
            <a role="menuitem" x-show="!menu.target.link && !menu.target.directory" :href="downloadUrl(menu.target.relative)" download @click="menu.open = false">
                <x-menu-icon name="download" /> Download
            </a>
            <button type="button" role="menuitem" x-show="!menu.target.link && menu.target.directory && canZip" @click="act('downloadZip')">
                <x-menu-icon name="download" /> Download as .zip
            </button>
            <div class="fm-menu-sep"></div>
            <button type="button" role="menuitem" @click="act('rename')"><x-menu-icon name="pencil" /> Rename <kbd>F2</kbd></button>
            <button type="button" role="menuitem" x-show="!menu.target.link" @click="act('duplicate')"><x-menu-icon name="copy" /> Duplicate</button>
            <button type="button" role="menuitem" x-show="!menu.target.link" @click="act('copy')"><x-menu-icon name="copy" /> Copy to…</button>
            <button type="button" role="menuitem" @click="act('move')"><x-menu-icon name="move" /> Move to…</button>
            <template x-if="canZip">
                <div>
                    <button type="button" role="menuitem" x-show="!menu.target.link" @click="act('compress')"><x-menu-icon name="archive" /> Compress to .zip</button>
                    <button type="button" role="menuitem" x-show="menu.target.extension === 'zip'" @click="act('extract')"><x-menu-icon name="archive" /> Extract here</button>
                </div>
            </template>
            <div class="fm-menu-sep"></div>
            <button type="button" role="menuitem" x-show="!menu.target.link" @click="act('chmod')"><x-menu-icon name="keys" /> Permissions <code x-text="menu.target.permissions"></code></button>
            <button type="button" role="menuitem" @click="act('copyPath')"><x-menu-icon name="link" /> Copy path</button>
            <div class="fm-menu-sep"></div>
            <button type="button" role="menuitem" class="is-danger" @click="act('delete')"><x-menu-icon name="trash" /> Delete <kbd>Del</kbd></button>
        </div>
    </template>

    {{-- New folder / new file --}}
    <template x-teleport="body">
        <x-modal-frame class="modal-root-top" open="dialog === 'folder' || dialog === 'file'" close="dialog = null">
            <form class="flex flex-col gap-4" x-show="dialog === 'folder'" wire:submit="createFolder">
                <div class="modal-head">
                    <h2>New folder</h2>
                    <button class="icon-btn" type="button" @click="dialog = null" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>
                <label class="field">
                    <span>Folder name</span>
                    <input class="input" x-ref="folderInput" wire:model="folderName" placeholder="assets" autocomplete="off">
                    <small class="text-muted">Created inside <code>/{{ $directory }}</code></small>
                    @error('folderName') <small class="error">{{ $message }}</small> @enderror
                </label>
                <div class="modal-foot">
                    <button class="btn" type="button" @click="dialog = null">Cancel</button>
                    <button class="btn btn-primary" type="submit">Create folder</button>
                </div>
            </form>
            <form class="flex flex-col gap-4" x-show="dialog === 'file'" wire:submit="createFile">
                <div class="modal-head">
                    <h2>New file</h2>
                    <button class="icon-btn" type="button" @click="dialog = null" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>
                <label class="field">
                    <span>File name</span>
                    <input class="input" x-ref="fileInput" wire:model="fileName" placeholder="notes.txt" autocomplete="off">
                    <small class="text-muted">Created inside <code>/{{ $directory }}</code> and opened in the editor.</small>
                    @error('fileName') <small class="error">{{ $message }}</small> @enderror
                </label>
                <div class="modal-foot">
                    <button class="btn" type="button" @click="dialog = null">Cancel</button>
                    <button class="btn btn-primary" type="submit">Create file</button>
                </div>
            </form>
        </x-modal-frame>
    </template>

    {{-- Rename --}}
    <template x-teleport="body">
        <x-modal-frame class="modal-root-top" open="dialog === 'rename'" close="dialog = null">
            <form class="flex flex-col gap-4" @submit.prevent="submitRename()">
                <div class="modal-head">
                    <h2>Rename</h2>
                    <button class="icon-btn" type="button" @click="dialog = null" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>
                <label class="field">
                    <span>New name for <strong class="text-ink" x-text="targets[0]?.name"></strong></span>
                    <input class="input" x-ref="renameInput" x-model="renameValue" autocomplete="off">
                </label>
                <div class="modal-foot">
                    <button class="btn" type="button" @click="dialog = null">Cancel</button>
                    <button class="btn btn-primary" type="submit" :disabled="renameValue.trim() === '' || renameValue === targets[0]?.name">Rename</button>
                </div>
            </form>
        </x-modal-frame>
    </template>

    {{-- Copy / move --}}
    <template x-teleport="body">
        <x-modal-frame class="modal-root-top" open="dialog === 'transfer'" close="dialog = null">
            <div class="modal-head">
                <h2 x-text="(transfer.mode === 'move' ? 'Move ' : 'Copy ') + targetLabel()"></h2>
                <button class="icon-btn" type="button" @click="dialog = null" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>
            <p class="mb-3 text-muted">Choose the destination folder.</p>
            <div class="fm-picker">
                <div class="fm-picker-path">
                    <button class="icon-btn" type="button" title="Up" aria-label="Up one folder" :disabled="transfer.path === ''" @click="browse(parentOf(transfer.path))"><x-menu-icon name="up" class="size-4" /></button>
                    <button class="fm-crumb" type="button" @click="browse('')"><x-menu-icon name="home" class="size-4" /> <span x-text="rootLabel"></span></button>
                    <template x-for="(crumb, index) in crumbsOf(transfer.path)" :key="crumb.path">
                        <span class="flex items-center">
                            <x-menu-icon name="chevron-right" class="size-3.5 text-muted" />
                            <button class="fm-crumb" type="button" @click="browse(crumb.path)" x-text="crumb.label"></button>
                        </span>
                    </template>
                </div>
                <div class="fm-picker-list">
                    <p class="px-3 py-6 text-center text-muted" x-show="transfer.loading">Loading…</p>
                    <p class="px-3 py-6 text-center text-danger" x-show="!transfer.loading && transfer.error" x-text="transfer.error"></p>
                    <p class="px-3 py-6 text-center text-muted" x-show="!transfer.loading && !transfer.error && transfer.folders.length === 0">No folders inside.</p>
                    <template x-for="folder in transfer.folders" :key="folder">
                        <button class="fm-picker-item" type="button" @click="browse(transfer.path === '' ? folder : transfer.path + '/' + folder)" :disabled="isTransferSource(transfer.path === '' ? folder : transfer.path + '/' + folder)">
                            @include('livewire.websites.partials.file-icon', ['kind' => 'folder'])
                            <span class="truncate" x-text="folder"></span>
                            <x-menu-icon name="chevron-right" class="ml-auto size-4 text-muted" />
                        </button>
                    </template>
                </div>
            </div>
            <div class="modal-foot">
                <span class="mr-auto self-center truncate text-[13px] text-muted">Into <code x-text="'/' + transfer.path"></code></span>
                <button class="btn" type="button" @click="dialog = null">Cancel</button>
                <button class="btn btn-primary" type="button" :disabled="transfer.loading || !!transfer.error" @click="submitTransfer()" x-text="transfer.mode === 'move' ? 'Move here' : 'Copy here'"></button>
            </div>
        </x-modal-frame>
    </template>

    {{-- Compress --}}
    <template x-teleport="body">
        <x-modal-frame class="modal-root-top modal-medium" open="dialog === 'compress'" close="dialog = null">
            <form class="flex flex-col gap-4" @submit.prevent="submitCompress()">
                <div class="modal-head">
                    <h2 x-text="'Compress ' + targetLabel() + ' to .zip'"></h2>
                    <button class="icon-btn" type="button" @click="dialog = null" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>

                <label class="field">
                    <span>Zip name</span>
                    <input class="input" x-model="zip.name" placeholder="archive.zip" autocomplete="off">
                    <small class="text-muted">Saved in this folder. Big folders are zipped in the background.</small>
                </label>

                <div class="field">
                    <div class="flex items-center justify-between gap-2">
                        <span>Leave out</span>
                        <span class="flex items-center gap-1.5" x-show="zip.folders.length > 0">
                            <span class="text-[12px] text-muted"><span x-text="zip.skip.length"></span> of <span x-text="zip.folders.length"></span></span>
                            <button class="btn btn-sm" type="button" @click="skipAll(true)">All</button>
                            <button class="btn btn-sm" type="button" @click="skipAll(false)">None</button>
                        </span>
                    </div>
                    <p class="text-[12.5px] text-muted">Dependencies, caches, and build output that an install or a build makes again. Untick a folder to put it in the zip.</p>

                    <div class="pick-list" style="max-height: 20rem">
                        <p class="flex items-center justify-center gap-2 bg-card px-3 py-8 text-muted" x-show="zip.loading"><span class="task-spinner"></span> Looking for folders to leave out…</p>
                        <p class="bg-card px-3 py-8 text-center text-danger" x-show="!zip.loading && zip.error" x-text="zip.error"></p>
                        <p class="bg-card px-3 py-8 text-center text-muted" x-show="!zip.loading && !zip.error && zip.folders.length === 0">Nothing to leave out: everything goes in the zip.</p>
                        <template x-for="folder in zip.folders" :key="folder.path">
                            <label class="pick-item cursor-pointer" :class="zip.skip.includes(folder.path) && 'is-selected'">
                                <input type="checkbox" :checked="zip.skip.includes(folder.path)" @change="toggleSkip(folder.path)">
                                @include('livewire.websites.partials.file-icon', ['kind' => 'folder'])
                                <span class="min-w-0 flex-1 truncate font-mono text-[13px]" x-text="folder.path"></span>
                                <span class="pill shrink-0" x-text="folder.name"></span>
                            </label>
                        </template>
                    </div>
                </div>

                <div class="modal-foot">
                    <button class="btn" type="button" @click="dialog = null">Cancel</button>
                    <button class="btn btn-primary" type="submit" :disabled="zip.loading"><x-menu-icon name="archive" /> Compress</button>
                </div>
            </form>
        </x-modal-frame>
    </template>

    {{-- Permissions --}}
    <template x-teleport="body">
        <x-modal-frame class="modal-root-top" open="dialog === 'chmod'" close="dialog = null">
            <form class="flex flex-col gap-4" @submit.prevent="$wire.chmodEntry(targets[0].relative, chmod)">
                <div class="modal-head">
                    <h2>Permissions of <span x-text="targets[0]?.name"></span></h2>
                    <button class="icon-btn" type="button" @click="dialog = null" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>
                <table class="fm-perm-table">
                    <thead><tr><th></th><th>Read</th><th>Write</th><th>Execute</th></tr></thead>
                    <tbody>
                        <template x-for="(who, row) in ['Owner', 'Group', 'Others']" :key="who">
                            <tr>
                                <th x-text="who"></th>
                                <template x-for="bit in [4, 2, 1]" :key="bit">
                                    <td><input type="checkbox" :checked="hasBit(row, bit)" @change="toggleBit(row, bit)" :aria-label="who + ' ' + bit"></td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <label class="field">
                    <span>Numeric value</span>
                    <input class="input w-32 font-mono" x-model="chmod" maxlength="3" inputmode="numeric" autocomplete="off">
                    <small class="text-muted">Common: 644 for files, 755 for folders and scripts.</small>
                </label>
                <div class="modal-foot">
                    <button class="btn" type="button" @click="dialog = null">Cancel</button>
                    <button class="btn btn-primary" type="submit" :disabled="!/^[0-7]{3}$/.test(chmod)">Apply</button>
                </div>
            </form>
        </x-modal-frame>
    </template>

    {{-- Delete --}}
    <template x-teleport="body">
        <x-modal-frame class="modal-root-top" open="dialog === 'delete'" close="dialog = null">
            <div class="modal-head">
                <h2 x-text="'Delete ' + targetLabel() + '?'"></h2>
                <button class="icon-btn" type="button" @click="dialog = null" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>
            <div class="modal-warning">
                <p><strong>This cannot be undone.</strong></p>
                <p>Folders are deleted together with everything inside them.</p>
            </div>
            <ul class="fm-delete-list" x-show="targets.length > 1">
                <template x-for="item in targets" :key="item.relative">
                    <li x-text="item.name + (item.directory ? '/' : '')"></li>
                </template>
            </ul>
            <label class="field mt-3">
                <span>Type <strong class="text-ink" x-text="deleteWord()"></strong> to confirm</span>
                <input class="input" x-ref="deleteInput" wire:model="confirmName" @input="typed = $event.target.value" autocomplete="off" @keydown.enter.prevent="submitDelete()">
                @error('confirmName') <small class="error">{{ $message }}</small> @enderror
            </label>
            <div class="modal-foot">
                <button class="btn" type="button" @click="dialog = null">Cancel</button>
                <button class="btn btn-danger-solid" type="button" :disabled="typed !== deleteWord()" @click="submitDelete()">
                    <x-menu-icon name="trash" class="size-4" /> Delete
                </button>
            </div>
        </x-modal-frame>
    </template>

    {{-- Viewer / editor --}}
    @if ($openPath !== '')
        @include('livewire.websites.partials.file-editor')
    @endif
</div>
