<div
    class="logs-viewer"
    style="height: auto; min-height: 26rem"
    x-data="terminalPrompt(@js($website ? 'site-'.$website->id : 'global'), @js($suggestions))"
    @terminal-ran.window="remember($event.detail.command)"
    @terminal-state.window="termRunning = $event.detail.running; termExit = $event.detail.exit; if (! termRunning) $nextTick(() => $refs.command.focus())"
    @terminal-folder-changed.window="live = []; liveState = 'idle'"
>
    <div class="logs-viewer-toolbar">
        <div class="logs-viewer-primary min-w-0 flex-1">
            <x-menu-icon name="terminal" class="size-4 shrink-0 text-muted" />
            @if ($website)
                <span class="text-[13px] font-medium">Terminal</span>
                <span class="logs-viewer-meta hidden md:flex"><code class="truncate">{{ $workingDirectory }}</code></span>
            @else
                <label class="flex min-w-0 flex-1 items-center gap-2">
                    <span class="shrink-0 text-[12px] text-muted">Folder</span>
                    <span class="flex min-w-0 max-w-xl flex-1 items-center rounded-lg border border-line bg-card font-mono text-[12.5px] dark:bg-white/5">
                        <span class="truncate pl-2.5 text-muted">{{ rtrim((string) config('laravelify.sites_root'), '/') }}/</span>
                        <input
                            class="h-8 min-w-0 flex-1 bg-transparent pr-2.5 outline-none"
                            wire:model.blur="directory"
                            @keydown.enter.prevent="$event.target.blur()"
                            list="terminal-site-folders"
                            placeholder="(all sites)"
                            spellcheck="false"
                            autocomplete="off"
                            aria-label="Folder to run in"
                        >
                    </span>
                    <datalist id="terminal-site-folders">
                        @foreach ($siteFolders as $folder)
                            <option value="{{ $folder['relative'] }}">{{ $folder['name'] }}</option>
                        @endforeach
                    </datalist>
                </label>
            @endif
        </div>
        <div class="logs-viewer-end">
            <span class="hidden text-[12px] text-muted lg:inline">{{ $runsAs }} · 15 min limit</span>
            <span class="status-badge" x-cloak x-show="termRunning" style="--status-color: var(--color-warning)">Running</span>
            <span class="status-badge" x-cloak x-show="!termRunning && termExit !== null" :style="'--status-color: ' + (termExit === 0 ? 'var(--color-success)' : 'var(--color-danger)')" x-text="'Exit ' + termExit"></span>
            <button class="btn btn-sm btn-danger" type="button" x-cloak x-show="termRunning" @click="$dispatch('terminal-stop')" title="Stop the command (Ctrl+C also works inside the window)"><x-menu-icon name="stop" class="size-3.5" /> Stop</button>
            <button class="logs-viewer-btn" type="button" title="Copy output" @click="$dispatch('terminal-copy')"><x-menu-icon name="copy" /></button>
            <button class="logs-viewer-btn" type="button" title="Clear" @click="$dispatch('terminal-clear')"><x-menu-icon name="trash" /></button>
        </div>
    </div>

    @unless ($website)
        @if (count($siteFolders) > 0)
            <div class="flex flex-wrap items-center gap-1.5 border-b border-line px-3 py-2">
                <span class="mr-1 text-[12px] text-muted">Jump to</span>
                <button type="button" @class(['choice h-7 px-2.5 text-[12px]', 'active' => $directory === '']) wire:click="$set('directory', '')">All sites</button>
                @foreach ($siteFolders as $folder)
                    <button type="button" wire:key="terminal-site-{{ $folder['relative'] }}" @class(['choice h-7 px-2.5 text-[12px]', 'active' => $directory === $folder['relative']]) wire:click="$set('directory', @js($folder['relative']))">{{ $folder['name'] }}</button>
                @endforeach
            </div>
        @endif
    @endunless

    <div
        class="live-terminal"
        wire:ignore
        x-data="liveTerminal({ mode: 'terminal', name: 'terminal', urls: { output: @js(route('terminal.sessions.output', ['session' => '__ID__'])), input: @js(route('terminal.sessions.input', ['session' => '__ID__'])), stop: @js(route('terminal.sessions.stop', ['session' => '__ID__'])) } })"
        @terminal-session.window="startSession($event.detail)"
        @terminal-text.window="showText($event.detail)"
        @terminal-stop.window="stop()"
        @terminal-copy.window="copy()"
        @terminal-clear.window="clear()"
    >
        <div class="live-terminal-screen" x-ref="screen"></div>
    </div>

    <form class="relative flex items-center gap-2 border-t border-line p-2" wire:submit="run" @click.outside="menuOpen && closeMenu()">
        {{-- "/" command menu --}}
        <div
            class="term-menu"
            x-cloak
            x-show="menuOpen"
            x-ref="menu"
            x-transition:enter="dropdown-in"
            x-transition:enter-start="dropdown-from"
            x-transition:enter-end="dropdown-to"
            x-transition:leave="dropdown-out"
            x-transition:leave-start="dropdown-to"
            x-transition:leave-end="dropdown-from"
            role="listbox"
            aria-label="Commands"
        >
            <div class="term-menu-head">
                <span>Commands</span>
                <span class="flex items-center gap-2">
                    <span class="flex items-center gap-1.5" x-show="liveState === 'loading'">
                        <span class="loader-spinner" style="width: 11px; height: 11px; border-width: 2px"></span>
                        Reading <code>artisan list</code>
                    </span>
                    <span><kbd>↑</kbd><kbd>↓</kbd> move · <kbd>Enter</kbd> insert · <kbd>Esc</kbd> close</span>
                </span>
            </div>
            <div class="term-menu-list">
                <template x-for="(item, index) in results" :key="item.group + item.command">
                    <div>
                        <p class="term-menu-group" x-show="isGroupStart(index)" x-text="item.group"></p>
                        <button
                            type="button"
                            class="term-menu-item"
                            role="option"
                            :data-index="index"
                            :aria-selected="index === active"
                            :class="index === active && 'is-active'"
                            @mouseenter="active = index"
                            @mousedown.prevent="pick(index)"
                        >
                            <code x-text="item.command"></code>
                            <span class="term-menu-desc" x-text="item.description"></span>
                            <span class="tag-neutral term-menu-danger" x-show="item.danger">Careful</span>
                        </button>
                    </div>
                </template>
                <p class="px-3 py-6 text-center text-[13px] text-muted" x-show="results.length === 0">No commands match “<span x-text="menuQuery"></span>”.</p>
            </div>
        </div>

        <button class="term-slash" type="button" @click="toggleMenu()" :aria-expanded="menuOpen" title="Commands (type /)">/</button>
        <span class="font-mono text-[13px] text-accent">$</span>
        <input
            class="input flex-1 border-0 bg-transparent font-mono shadow-none focus:shadow-none"
            wire:model="command"
            x-ref="command"
            @input="onInput($event)"
            @keydown="onKey($event)"
            placeholder="{{ $website && $website->isPhp() && ! $website->isLaravel() ? 'Type / for PHP and Composer commands' : ($website && ! $website->isLaravel() ? 'Type / for npm commands' : 'Type / for Laravel commands, or any shell command') }}"
            autocomplete="off"
            spellcheck="false"
            aria-label="Command"
            aria-autocomplete="list"
        >
        <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="run">
            <span wire:loading.remove wire:target="run">Run</span>
            <span wire:loading wire:target="run">Running…</span>
        </button>
    </form>
    @error('command') <small class="error px-4 pb-2">{{ $message }}</small> @enderror
</div>
