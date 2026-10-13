@php
    $lineClass = function (string $line): string {
        return match (true) {
            preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY):|\[(error|crit|alert|emerg)\]|PHP Fatal|Uncaught|" 5\d\d /', $line) === 1 => 'is-error',
            preg_match('/\.WARNING:|\[warn\]|" 4\d\d /', $line) === 1 => 'is-warning',
            preg_match('/^\s*(#\d+ |\[stacktrace\]|Stack trace:)/', $line) === 1 => 'is-trace',
            default => '',
        };
    };
@endphp

<div class="flex flex-col gap-4" @if ($live) wire:poll.3s="load" @endif>
    @unless ($nginxReady)
        <div class="callout">
            <x-menu-icon name="danger" class="size-4 shrink-0 text-warning" />
            <p class="min-w-0 flex-1">This site writes to the shared nginx logs, so its access and error logs cannot be shown here. Add per-site log files to its nginx config.</p>
            <button class="btn" type="button" wire:click="enableNginxLogs" wire:loading.attr="disabled" wire:target="enableNginxLogs">Enable nginx logs</button>
        </div>
    @endunless

    <div class="grid items-start gap-4 lg:grid-cols-[15rem_minmax(0,1fr)]">
        {{-- Sources --}}
        <nav class="log-sources" aria-label="Log files">
            @foreach (collect($sources)->groupBy('group') as $group => $items)
                <p class="log-sources-group">{{ $group }}</p>
                @foreach ($items as $item)
                    <button
                        type="button"
                        wire:key="log-source-{{ $item['key'] }}"
                        @class(['log-source', 'is-active' => $item['key'] === $source])
                        wire:click="$set('source', @js($item['key']))"
                        title="{{ $item['path'] }}"
                    >
                        <span class="truncate">{{ $item['label'] }}</span>
                        <span class="log-source-meta">
                            @if ($item['size'] === 'missing')
                                none yet
                            @elseif ($item['modified'])
                                {{ $item['size'] }} · @ago($item['modified'], true)
                            @endif
                        </span>
                    </button>
                @endforeach
            @endforeach
        </nav>

        {{-- Viewer --}}
        <section
            class="logs-viewer"
            x-data="deployLogs({ live: true, name: @js(\Illuminate\Support\Str::slug($website->name.'-'.str_replace(':', '-', $source))) })"
            :class="{ 'is-fullscreen': fullscreen, 'no-wrap': !wrap }"
            @keydown.escape.window="fullscreen = false"
            @log-cleared.window="query = ''"
        >
            <div class="logs-viewer-toolbar">
                <div class="logs-viewer-primary">
                    <div class="logs-viewer-actions">
                        <button class="logs-viewer-btn" type="button" :class="follow && 'is-active'" @click="toggleFollow()" title="Follow the end of the log"><x-menu-icon name="arrow-down" /></button>
                        <button class="logs-viewer-btn" type="button" :class="wrap && 'is-active'" @click="toggleWrap()" title="Wrap long lines"><x-menu-icon name="wrap" /></button>
                        <button class="logs-viewer-btn" type="button" @click="fullscreen = !fullscreen" :title="fullscreen ? 'Exit fullscreen (Esc)' : 'Fullscreen'">
                            <x-menu-icon name="expand" x-show="!fullscreen" />
                            <x-menu-icon name="shrink" x-show="fullscreen" x-cloak />
                        </button>
                        <button class="logs-viewer-btn" type="button" @click="copy()" title="Copy visible lines"><x-menu-icon name="copy" /></button>
                        <button class="logs-viewer-btn" type="button" @click="download()" title="Download visible lines"><x-menu-icon name="download" /></button>
                        <button class="logs-viewer-btn" type="button" wire:click="load" title="Reload"><x-menu-icon name="refresh" wire:loading.class="animate-spin" wire:target="load" /></button>
                    </div>
                    <span class="logs-viewer-meta hidden xl:flex">
                        <code class="max-w-[22rem] truncate">{{ $current['path'] ?? $source }}</code>
                    </span>
                </div>
                <div class="logs-viewer-end">
                    <label class="flex items-center gap-1.5 whitespace-nowrap text-[12px] text-muted" title="Re-read the log every 3 seconds">
                        <input type="checkbox" wire:model.live="live"> Live
                    </label>
                    <select class="select h-8 w-auto py-0 text-[12.5px]" wire:model.live="lines" aria-label="Lines to show">
                        @foreach ([100, 500, 1000, 2000, 5000] as $count)
                            <option value="{{ $count }}">Last {{ number_format($count) }}</option>
                        @endforeach
                    </select>
                    @if ($canClear)
                        <x-confirm-delete
                            title="Clear this log file?"
                            name="clear"
                            action="clearLog"
                            button="Clear"
                            :actions="['Every line in '.($current['path'] ?? 'this log').' will be removed.']"
                        />
                    @endif
                    <span class="whitespace-nowrap text-[12px] text-muted" x-cloak x-show="query.trim() !== ''" x-text="matches + (matches === 1 ? ' match' : ' matches')"></span>
                    <label class="logs-viewer-search">
                        <x-menu-icon name="search" />
                        <input type="search" x-model.debounce.200ms="query" placeholder="Find in log" aria-label="Find in log" autocomplete="off" spellcheck="false">
                        <button class="clear" type="button" x-cloak x-show="query !== ''" @click="query = ''" aria-label="Clear search"><x-menu-icon name="close" class="size-3" /></button>
                    </label>
                </div>
            </div>
            <div class="logs-viewer-viewport" x-ref="viewport" @scroll="onScroll()" tabindex="0">
                <div class="logs-viewer-lines" x-ref="lines">
                    @if ($error !== '')
                        <p class="logs-viewer-empty text-danger">{{ $error }}</p>
                    @endif
                    @forelse ($logLines as $index => $line)
                        <div class="logs-viewer-line {{ $lineClass($line) }}" data-log-line wire:key="log-{{ $source }}-{{ $index }}">
                            <span class="logs-viewer-number">{{ $index + 1 }}</span>
                            <span class="logs-viewer-text" data-text="{{ $line }}">{{ $line }}</span>
                        </div>
                    @empty
                        @if ($error === '')
                            <p class="logs-viewer-empty">
                                @if (str_starts_with($source, 'nginx') && ! $nginxReady)
                                    Enable nginx logs above to see this log.
                                @else
                                    This log is empty.
                                @endif
                            </p>
                        @endif
                    @endforelse
                </div>
                <p class="logs-viewer-empty" x-cloak x-show="query.trim() !== '' && matches === 0">No lines match.</p>
            </div>
            <button class="logs-viewer-jump" type="button" x-cloak x-show="!follow && !atBottom" x-transition.opacity @click="toggleFollow()" style="position: absolute"><x-menu-icon name="arrow-down" class="size-3.5" /> Jump to latest</button>
        </section>
    </div>
</div>
