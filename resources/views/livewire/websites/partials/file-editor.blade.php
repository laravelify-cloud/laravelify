@php
    $extension = strtolower(pathinfo($openName, PATHINFO_EXTENSION));
    $language = match (true) {
        str_starts_with($openName, '.env') => 'Environment',
        $extension === 'php' => str_ends_with($openName, '.blade.php') ? 'Blade' : 'PHP',
        in_array($extension, ['js', 'mjs', 'cjs', 'jsx'], true) => 'JavaScript',
        in_array($extension, ['ts', 'tsx'], true) => 'TypeScript',
        in_array($extension, ['yml', 'yaml'], true) => 'YAML',
        in_array($extension, ['md', 'markdown'], true) => 'Markdown',
        in_array($extension, ['sh', 'bash'], true) => 'Shell',
        $extension === 'json' => 'JSON',
        $extension === 'css' => 'CSS',
        $extension === 'html' => 'HTML',
        $extension === 'xml' => 'XML',
        $extension === 'sql' => 'SQL',
        in_array($extension, ['conf', 'ini'], true) => 'Config',
        default => 'Plain text',
    };
@endphp

<template x-teleport="body">
    <div
        class="modal-root modal-root-top fm-editor-root"
        x-data="fileEditor()"
        :class="full && 'is-full'"
        @keydown.escape.window="if (findOpen) { closeFind() } else { closeEditor() }"
    >
        <div class="modal-backdrop" @click="closeEditor()"></div>
        <div
            class="card modal-panel fm-editor"
            role="dialog"
            aria-modal="true"
            aria-label="{{ $openName }}"
            :class="{ 'is-shown': shown, 'is-full': full }"
            @keydown.ctrl.f.prevent="openFind()"
            @keydown.meta.f.prevent="openFind()"
        >
            <div class="fm-editor-head">
                <div class="flex min-w-0 items-center gap-2.5">
                    @include('livewire.websites.partials.file-icon', ['kind' => $openKind === 'image' ? 'image' : 'code'])
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 font-semibold">
                            <span class="truncate">{{ $openName }}</span>
                            <span class="pill pill-warn" x-cloak x-show="dirty">Unsaved</span>
                        </p>
                        <p class="truncate text-[12px] text-muted">/{{ $openPath }}</p>
                    </div>
                </div>
                <div class="fm-editor-tools">
                    @if ($openKind === 'text')
                        <button class="icon-btn" type="button" title="Find (Ctrl+F)" aria-label="Find" :aria-pressed="findOpen" @click="findOpen ? closeFind() : openFind()"><x-menu-icon name="search" class="size-4" /></button>
                        <button class="icon-btn" type="button" title="Word wrap" aria-label="Word wrap" :aria-pressed="wrap" @click="toggleWrap()"><x-menu-icon name="wrap" class="size-4" /></button>
                        <span class="fm-editor-zoom">
                            <button class="icon-btn" type="button" title="Smaller text" aria-label="Smaller text" @click="zoom(-1)"><x-menu-icon name="minus" class="size-4" /></button>
                            <span class="tabular-nums" x-text="fontSize + 'px'"></span>
                            <button class="icon-btn" type="button" title="Larger text" aria-label="Larger text" @click="zoom(1)"><x-menu-icon name="plus" class="size-4" /></button>
                        </span>
                        <button class="icon-btn" type="button" title="Copy all" aria-label="Copy all" @click="copyAll()"><x-menu-icon name="copy" class="size-4" /></button>
                        <button class="icon-btn" type="button" title="Reload from disk" aria-label="Reload from disk" @click="reload()"><x-menu-icon name="refresh" class="size-4" /></button>
                    @elseif ($openKind === 'image')
                        <button class="icon-btn" type="button" title="Fit to window / actual size" aria-label="Toggle image size" :aria-pressed="!fit" @click="fit = !fit"><x-menu-icon name="eye" class="size-4" /></button>
                    @endif
                    <span class="fm-editor-divider"></span>
                    <button class="icon-btn" type="button" :title="full ? 'Exit full window' : 'Full window'" :aria-label="full ? 'Exit full window' : 'Full window'" :aria-pressed="full" @click="toggleFull()">
                        <x-menu-icon name="expand" class="size-4" x-show="!full" />
                        <x-menu-icon name="shrink" class="size-4" x-show="full" x-cloak />
                    </button>
                    <a class="btn" href="{{ $downloadUrl($openPath) }}" download title="Download"><x-menu-icon name="download" /> <span class="hidden sm:inline">Download</span></a>
                    @if ($openKind === 'text')
                        <button class="btn btn-primary" type="button" wire:click="saveFile" wire:loading.attr="disabled" wire:target="saveFile" title="Save (Ctrl+S)">
                            <span wire:loading.remove wire:target="saveFile">Save</span>
                            <span wire:loading wire:target="saveFile">Saving…</span>
                        </button>
                    @endif
                    <button class="icon-btn" type="button" @click="closeEditor()" title="Close (Esc)" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>
            </div>

            @if ($openKind === 'text')
                <div class="fm-find" x-cloak x-show="findOpen">
                    <x-menu-icon name="search" class="size-4 text-muted" />
                    <input
                        x-ref="find"
                        x-model="findQuery"
                        @input="countMatches()"
                        @keydown.enter.prevent="$event.shiftKey ? find(-1) : find(1)"
                        placeholder="Find in file"
                        aria-label="Find in file"
                        autocomplete="off"
                        spellcheck="false"
                    >
                    <span class="tabular-nums text-[12px] text-muted" x-text="matchLabel"></span>
                    <button class="icon-btn" type="button" title="Previous (Shift+Enter)" aria-label="Previous match" @click="find(-1)"><x-menu-icon name="up" class="size-4" /></button>
                    <button class="icon-btn" type="button" title="Next (Enter)" aria-label="Next match" @click="find(1)"><x-menu-icon name="up" class="size-4 rotate-180" /></button>
                    <button class="icon-btn" type="button" title="Close (Esc)" aria-label="Close find" @click="closeFind()"><x-menu-icon name="close" class="size-4" /></button>
                </div>

                <div class="fm-code" :class="wrap && 'is-wrapped'" :style="`--fm-font: ${fontSize}px`">
                    <pre class="fm-gutter" x-ref="gutter" aria-hidden="true" x-show="!wrap" x-text="gutter()"></pre>
                    <div class="fm-code-area">
                    <pre class="fm-marks" x-ref="marks" aria-hidden="true"></pre>
                    <textarea
                        x-ref="code"
                        wire:model="openContent"
                        spellcheck="false"
                        autocapitalize="off"
                        autocomplete="off"
                        aria-label="Contents of {{ $openName }}"
                        @input="dirty = true; measure(); findOpen && countMatches()"
                        @click="caret()"
                        @keyup="caret()"
                        @scroll="$refs.gutter.scrollTop = $el.scrollTop; syncMarks()"
                        @keydown.tab.prevent="document.execCommand('insertText', false, '    ')"
                        @keydown.ctrl.s.prevent="$wire.saveFile()"
                        @keydown.meta.s.prevent="$wire.saveFile()"
                    ></textarea>
                    </div>
                </div>
                <div class="fm-editor-foot">
                    <span class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span class="tabular-nums">Ln <span x-text="line"></span>, Col <span x-text="col"></span></span>
                        <span class="tabular-nums"><span x-text="lines"></span> lines</span>
                        <span>{{ $language }}</span>
                        <span>UTF-8</span>
                        <span class="hidden sm:inline">{{ $openSize }} · saved {{ $openModified }}</span>
                    </span>
                    <span class="hidden items-center gap-3 md:flex">
                        @error('openContent') <span class="error">{{ $message }}</span> @enderror
                        <span><kbd>Ctrl</kbd>+<kbd>S</kbd> save</span>
                        <span><kbd>Ctrl</kbd>+<kbd>F</kbd> find</span>
                        <span><kbd>Esc</kbd> close</span>
                    </span>
                </div>
            @elseif ($openKind === 'image')
                <div class="fm-preview" :class="!fit && 'is-actual'">
                    <img src="{{ $downloadUrl($openPath, true) }}" alt="{{ $openName }}" @load="dimensions = $el.naturalWidth + ' × ' + $el.naturalHeight + ' px'">
                </div>
                <div class="fm-editor-foot">
                    <span class="flex gap-4"><span x-text="dimensions"></span><span>{{ $openSize }}</span><span>{{ $openModified }}</span></span>
                    <span class="hidden md:inline"><kbd>Esc</kbd> close</span>
                </div>
            @else
                <div class="flex flex-1 flex-col items-center justify-center gap-1.5 px-4 py-14 text-center">
                    <x-menu-icon name="eye" class="size-9 text-muted" />
                    <p class="text-base font-semibold">{{ $openKind === 'large' ? 'This file is too large to edit here' : 'This file cannot be shown as text' }}</p>
                    <p class="max-w-md text-muted">
                        {{ $openKind === 'large' ? 'Files up to '.(\App\Livewire\Websites\Files::EDITABLE_LIMIT / 1024).' KB open in the editor.' : 'It looks like a binary file.' }}
                        Download it to view it on your computer.
                    </p>
                    <a class="btn btn-primary mt-3" href="{{ $downloadUrl($openPath) }}" download><x-menu-icon name="download" /> Download {{ $openSize }}</a>
                </div>
            @endif
        </div>
    </div>
</template>
