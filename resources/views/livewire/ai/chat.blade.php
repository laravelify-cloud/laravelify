@php
    $hour = (int) now()->format('G');
    $greeting = $hour < 5 ? 'Up late' : ($hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening'));
    // Chats grouped like Claude: today, yesterday, the last week, older.
    $groups = $chats->groupBy(fn ($item) => match (true) {
        $item->updated_at->isToday() => 'Today',
        $item->updated_at->isYesterday() => 'Yesterday',
        $item->updated_at->gt(now()->subDays(7)) => 'Previous 7 days',
        default => 'Older',
    });
@endphp
<div class="ai-page" wire:init="loadStatuses"
    x-data="{ skills: false, slash: false, plus: false, pick: false, history: false, q: '', lightbox: null }"
    @keydown.escape.window="lightbox = null; history = false; skills = false; slash = false; plus = false; pick = false">
    @include('livewire.partials.followed-tasks')

    {{-- Phone and tablet: the chats and the skills open as drawers over this --}}
    <div class="ai-scrim" x-show="history || skills" x-transition.opacity x-cloak @click="history = false; skills = false"></div>

    {{-- Chats --}}
    <aside class="ai-chats" :class="{ 'is-open': history }" aria-label="Chats">
        <div class="ai-chats-head">
            <b>Chats</b>
            <button class="ai-icon ai-only-small" type="button" @click="history = false" aria-label="Close chats"><x-menu-icon name="close" class="size-5" /></button>
        </div>
        <button class="ai-panel-link ai-only-small" type="button" @click="history = false; nav = true"><x-menu-icon name="menu" class="size-4" /> Laravelify panel</button>
        <button class="ai-new" type="button" wire:click="newChat" @click="history = false"><x-menu-icon name="plus" class="size-4" /> New chat</button>
        <label class="ai-search">
            <x-menu-icon name="search" class="size-4" />
            <input type="search" x-model="q" placeholder="Search chats" aria-label="Search chats">
        </label>
        <nav class="ai-chat-list">
            @forelse ($groups as $label => $items)
                <p class="ai-chat-group" wire:key="group-{{ $label }}">{{ $label }}</p>
                @foreach ($items as $item)
                    <div @class(['ai-chat-item', 'is-active' => $chat?->id === $item->id]) wire:key="chat-{{ $item->id }}"
                        x-show="q === '' || @js(mb_strtolower($item->title)).includes(q.toLowerCase())">
                        <span class="ai-mark"><x-agent-icon :agent="$item->agent" /></span>
                        <button type="button" class="ai-chat-open" wire:click="openChat({{ $item->id }})" @click="history = false" title="{{ $item->title }}">{{ $item->title }}</button>
                        <button type="button" class="ai-chat-delete" wire:click="deleteChat({{ $item->id }})" wire:confirm="Delete this chat?" aria-label="Delete chat"><x-menu-icon name="trash" class="size-4" /></button>
                    </div>
                @endforeach
            @empty
                <p class="ai-chat-empty">No chats yet. Your conversations show here.</p>
            @endforelse
        </nav>
    </aside>

    {{-- Conversation --}}
    <section class="ai-main">
        <header class="ai-head">
            <button class="ai-icon ai-only-small" type="button" @click="history = true" aria-label="Open chats"><x-menu-icon name="menu" class="size-5" /></button>
            <h2 class="ai-title">{{ $chat?->title ?? 'New chat' }}</h2>
            <button class="ai-icon" type="button" wire:click="newChat" title="New chat" aria-label="New chat"><x-menu-icon name="plus" class="size-5" /></button>
            <button class="ai-icon" type="button" @click="skills = ! skills" :class="{ 'is-on': skills }" title="Skills" aria-label="Skills"><x-menu-icon name="book" class="size-5" /></button>
        </header>

        <div class="ai-body">
            <div class="ai-messages" @if ($busy) wire:poll.1s @endif
                x-data="{ stick: true }" @scroll="stick = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 120"
                x-init="$el.scrollTop = $el.scrollHeight; Livewire.hook('commit', ({ succeed }) => succeed(() => $nextTick(() => { if (stick) $el.scrollTop = $el.scrollHeight })))">
                <div class="ai-column">
                    @if (! $chat)
                        <div class="ai-welcome">
                            <span class="ai-hero"><x-agent-icon agent="claude" /></span>
                            <h3>{{ $greeting }}, what should I do on this server?</h3>
                            <p>Deploy a website, read its logs, change domains, environment or nginx, run a backup. Paste a screenshot, import a GitHub repository, or attach a website's error log.</p>
                            <div class="ai-agents">
                                @foreach ($agents as $id => $definition)
                                    @php $status = $statuses[$id] ?? null; @endphp
                                    <div class="ai-agent" wire:key="agent-{{ $id }}">
                                        <span class="ai-mark is-big"><x-agent-icon :agent="$id" /></span>
                                        <span class="min-w-0 flex-1">
                                            <b>{{ $definition['product'] }}</b>
                                            <small>
                                                @if ($status === null) Checking…
                                                @elseif (! $status['installed']) Not installed
                                                @elseif (! $status['signedIn']) Not signed in
                                                @else {{ $status['who'] ?: 'Signed in' }}
                                                @endif
                                            </small>
                                        </span>
                                        @if ($status !== null && ! $status['installed'])
                                            <button class="btn btn-sm" type="button" wire:click="install('{{ $id }}')"><x-menu-icon name="download" /> Install</button>
                                        @elseif ($status !== null)
                                            <span class="ai-dot" style="--dot: var({{ $status['signedIn'] ? '--color-success' : '--color-warning' }})"></span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            <p class="ai-note">Sign in once in any website's VS Code (AI Agents): the sign-in is shared with every editor and with this chat.</p>
                        </div>
                    @else
                        @foreach ($chat->messages as $item)
                            <article @class(['ai-message', 'is-user' => $item->role === 'user', 'is-error' => $item->status === 'error']) wire:key="message-{{ $item->id }}">
                                @if ($item->role === 'user')
                                    <div class="ai-bubble">
                                        @if ($item->attachments)
                                            <div class="ai-attachments is-sent">
                                                @foreach ($item->attachments as $index => $attachment)
                                                    @if (($attachment['kind'] ?? null) === 'image')
                                                        <button type="button" class="ai-thumb" @click="lightbox = @js(route('ai.attachment', [$item, $index]))" title="{{ $attachment['label'] }}"><img src="{{ route('ai.attachment', [$item, $index]) }}" alt="{{ $attachment['label'] }}" loading="lazy"></button>
                                                    @else
                                                        <span class="ai-chip" title="{{ $attachment['kind'] === 'log' ? \Illuminate\Support\Str::limit($attachment['text'] ?? '', 600) : $attachment['label'] }}">
                                                            <x-menu-icon :name="$attachment['kind'] === 'repo' ? 'git' : 'logs'" class="size-3.5" /> {{ $attachment['label'] }}
                                                        </span>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                        @if ($item->text !== '')<div class="whitespace-pre-wrap">{{ $item->text }}</div>@endif
                                    </div>
                                @else
                                    <div class="ai-answer">
                                        <p class="ai-who">
                                            <span class="ai-mark"><x-agent-icon :agent="$item->agent" /></span>
                                            {{ $agents[$item->agent]['product'] ?? 'Assistant' }}
                                            @if ($item->status === 'stopped') <span class="text-muted">· stopped</span> @endif
                                        </p>
                                        @if ($item->tools)
                                            <div class="ai-tools">
                                                @foreach ($item->tools as $tool)
                                                    <span class="ai-tool" title="{{ json_encode($tool['input'] ?? [], JSON_UNESCAPED_SLASHES) }}"><x-menu-icon name="settings" class="size-3" /> {{ $tool['name'] }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if ($item->text !== '')
                                            <div class="ai-markdown">{!! \Illuminate\Support\Str::markdown($item->text, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                                        @elseif ($item->status === 'running')
                                            <p class="ai-thinking"><span class="ai-dots"><i></i><i></i><i></i></span> Working…</p>
                                        @endif
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    @endif
                </div>
            </div>

            {{-- Skills: the panel's MCP tools --}}
            <aside class="ai-skills" :class="{ 'is-open': skills }" aria-label="Skills">
                <div class="ai-chats-head">
                    <b>Skills</b>
                    <button class="ai-icon" type="button" @click="skills = false" aria-label="Close skills"><x-menu-icon name="close" class="size-5" /></button>
                </div>
                <p class="ai-note">What the assistant can do here. Tap one to start a message with it.</p>
                <ul>
                    @foreach ($skills as $skill)
                        <li>
                            <button type="button" wire:click="$set('message', @js('Use '.$skill['name'].': '))" @click="skills = false; $nextTick(() => $refs.box.focus())">
                                <span class="flex items-center gap-1.5"><b>{{ $skill['name'] }}</b>
                                    <span class="status-badge" style="--status-color: var({{ $skill['readOnly'] ? '--color-success' : '--color-warning' }})">{{ $skill['readOnly'] ? 'read' : 'changes' }}</span>
                                </span>
                                <small>{{ $skill['description'] }}</small>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </aside>
        </div>

        {{-- Composer --}}
        <form class="ai-composer" wire:submit="send">
            <div class="ai-column">
                {{-- Pickers: the "/" shortcuts, GitHub repositories, website logs --}}
                <div class="ai-pop" x-show="slash" x-cloak @click.outside="slash = false">
                    <p class="ai-pop-head">Panel tools <button type="button" class="ai-icon is-small" @click="slash = false" aria-label="Close"><x-menu-icon name="close" class="size-4" /></button></p>
                    @foreach ($skills as $skill)
                        <button type="button" wire:click="$set('message', @js('Use '.$skill['name'].': '))" @click="slash = false; $nextTick(() => $refs.box.focus())">
                            <b>/{{ $skill['name'] }}</b> <span>{{ \Illuminate\Support\Str::limit($skill['description'], 80) }}</span>
                        </button>
                    @endforeach
                </div>
                @if ($picker === 'repo')
                    <div class="ai-pop" @click.outside="$wire.closePicker()">
                        <p class="ai-pop-head">Import a GitHub repository <button type="button" class="ai-icon is-small" wire:click="closePicker" aria-label="Close"><x-menu-icon name="close" class="size-4" /></button></p>
                        <input class="input" type="search" wire:model.live.debounce.250ms="pickerSearch" placeholder="Search repositories">
                        @forelse ($repositories as $repository)
                            <button type="button" wire:click="addRepository({{ $repository['app'] }}, @js($repository['name']), @js($repository['branch']))" wire:key="repo-{{ $repository['app'] }}-{{ $repository['name'] }}">
                                <x-menu-icon name="git" class="size-4 shrink-0" /> <b>{{ $repository['name'] }}</b> <span>{{ $repository['branch'] }} · {{ $repository['appName'] }}</span>
                            </button>
                        @empty
                            <p class="ai-note px-2 py-3">No repositories. Connect a GitHub App in Sources first.</p>
                        @endforelse
                    </div>
                @elseif ($picker === 'log')
                    <div class="ai-pop" @click.outside="$wire.closePicker()">
                        <p class="ai-pop-head">{{ $logWebsite ? 'Which log?' : 'Error logs of which website?' }} <button type="button" class="ai-icon is-small" wire:click="closePicker" aria-label="Close"><x-menu-icon name="close" class="size-4" /></button></p>
                        @if (! $logWebsite)
                            @foreach ($logWebsites as $website)
                                <button type="button" wire:click="$set('logWebsite', {{ $website->id }})" wire:key="logsite-{{ $website->id }}"><x-menu-icon name="globe" class="size-4 shrink-0" /> <b>{{ $website->name }}</b></button>
                            @endforeach
                        @else
                            @forelse ($logSources as $source)
                                <button type="button" wire:click="addLog(@js($source['key']))" wire:key="logsrc-{{ $source['key'] }}"><x-menu-icon name="logs" class="size-4 shrink-0" /> <b>{{ $source['label'] }}</b> <span>{{ $source['group'] }} · {{ $source['size'] }}</span></button>
                            @empty
                                <p class="ai-note px-2 py-3">This website has no logs yet.</p>
                            @endforelse
                        @endif
                    </div>
                @endif

                <div class="ai-box" @dragover.prevent @drop.prevent="for (const file of $event.dataTransfer.files) { if (file.type.startsWith('image/')) $wire.upload('pasted', file) }">
                    @if ($attached)
                        <div class="ai-attachments">
                            @foreach ($attached as $index => $attachment)
                                @if ($attachment['kind'] === 'image')
                                    @php $preview = route('ai.pending', basename($attachment['path'])); @endphp
                                    <span class="ai-att-thumb" wire:key="att-{{ $index }}-{{ $attachment['path'] }}">
                                        <button type="button" @click="lightbox = @js($preview)" title="{{ $attachment['label'] }}"><img src="{{ $preview }}" alt="{{ $attachment['label'] }}"></button>
                                        <button type="button" class="ai-att-remove" wire:click="removeAttachment({{ $index }})" aria-label="Remove {{ $attachment['label'] }}"><x-menu-icon name="close" class="size-3.5" /></button>
                                    </span>
                                @else
                                    <span class="ai-chip" wire:key="att-{{ $index }}-{{ $attachment['label'] }}">
                                        <x-menu-icon :name="$attachment['kind'] === 'repo' ? 'github' : 'article'" class="size-4" />
                                        {{ $attachment['label'] }}
                                        <button type="button" wire:click="removeAttachment({{ $index }})" aria-label="Remove"><x-menu-icon name="close" class="size-3.5" /></button>
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                    <div wire:loading.flex wire:target="pasted" class="ai-uploading"><span class="ai-dots"><i></i><i></i><i></i></span> Adding the image…</div>
                    @error('pasted') <p class="px-1 pb-1 text-[12px]" style="color: var(--color-danger)">{{ $message }}</p> @enderror
                    <textarea x-ref="box" wire:model="message" rows="1" placeholder="Ask, or describe what to do…" aria-label="Message"
                        @keydown.enter="if (! $event.shiftKey && ! window.matchMedia('(pointer: coarse)').matches) { $event.preventDefault(); $wire.send() }"
                        @input="slash = $event.target.value === '/'"
                        @paste="for (const item of $event.clipboardData.items) { if (item.type.startsWith('image/')) { $event.preventDefault(); $wire.upload('pasted', item.getAsFile()) } }"></textarea>
                    <div class="ai-composer-bar">
                        <div class="relative">
                            <button type="button" class="ai-round" @click="plus = ! plus" title="Attach" aria-label="Attach"><x-menu-icon name="plus" class="size-5" /></button>
                            <div class="ai-menu" x-show="plus" x-cloak x-transition.origin.bottom.left @click.outside="plus = false">
                                <label class="cursor-pointer"><x-menu-icon name="image" class="size-4" /> Add an image <small class="ml-auto text-muted">or paste</small> <input type="file" accept="image/*" class="hidden" wire:model="pasted" @change="plus = false"></label>
                                <button type="button" wire:click="openPicker('repo')" @click="plus = false"><x-menu-icon name="github" class="size-4" /> Import a GitHub repository</button>
                                <button type="button" wire:click="openPicker('log')" @click="plus = false"><x-menu-icon name="article" class="size-4" /> Add a website's error logs</button>
                                <button type="button" @click="plus = false; slash = true"><x-menu-icon name="build" class="size-4" /> Use a panel tool <small class="ml-auto text-muted">/</small></button>
                            </div>
                        </div>
                        @php $efforts = $agents[$agent]['efforts'] ?? []; @endphp
                        <div class="relative min-w-0" x-data="{ view: @js($agent), levels: @js(array_merge([''], array_keys($agents['claude']['efforts']))), names: @js(array_merge(['Auto'], array_values($agents['claude']['efforts']))) }">
                            <button type="button" class="ai-pill" @click="view = $wire.agent; pick = ! pick" :aria-expanded="pick">
                                <span class="ai-mark"><x-agent-icon :agent="$agent" /></span>
                                <span class="truncate">{{ $agents[$agent]['models'][$model] ?? $model }}@if ($efforts && isset($efforts[$effort]))<span class="text-muted"> · {{ $efforts[$effort] }}</span>@endif</span>
                                <x-menu-icon name="chevron-down" class="size-4 shrink-0 opacity-60" />
                            </button>
                            <div class="ai-picker" x-show="pick" x-cloak x-transition.origin.bottom.left @click.outside="pick = false">
                                <div class="ai-picker-agents" role="tablist">
                                    @foreach ($agents as $id => $definition)
                                        @php $status = $statuses[$id] ?? null; @endphp
                                        <button type="button" role="tab" :aria-selected="view === @js($id)" :class="view === @js($id) && 'is-active'" @click="view = @js($id)" wire:key="tab-{{ $id }}">
                                            <x-agent-icon :agent="$id" class="size-4" />
                                            <span>{{ $definition['label'] }}</span>
                                            @if ($status && (! $status['installed'] || ! $status['signedIn']))
                                                <i class="ai-picker-warn" title="{{ ! $status['installed'] ? 'Not installed' : 'Not signed in' }}"></i>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                                @foreach ($agents as $id => $definition)
                                    <div class="ai-picker-models" x-show="view === @js($id)" wire:key="models-{{ $id }}">
                                        @foreach ($definition['models'] as $modelId => $name)
                                            <button type="button" @class(['is-active' => $agent === $id && $model === $modelId]) wire:click="$set('agent', @js($id)); $set('model', @js($modelId))" @click="if (@js($id) !== 'claude') pick = false" wire:key="pick-{{ $id }}-{{ $modelId }}">
                                                <span class="min-w-0 flex-1">
                                                    <b>{{ $name }}</b>
                                                    @isset($definition['hints'][$modelId])<small>{{ $definition['hints'][$modelId] }}</small>@endisset
                                                </span>
                                                @if ($agent === $id && $model === $modelId) <x-menu-icon name="check" class="size-5 shrink-0 text-accent" /> @endif
                                            </button>
                                        @endforeach
                                    </div>
                                @endforeach
                                {{-- Claude's effort: how long it thinks before it answers --}}
                                <div class="ai-effort" x-show="view === 'claude'">
                                    <div class="flex items-center justify-between">
                                        <span class="flex items-center gap-2"><x-menu-icon name="tune" class="size-4" /> Effort</span>
                                        <b x-text="names[Math.max(0, levels.indexOf($wire.effort))]"></b>
                                    </div>
                                    <input type="range" min="0" :max="levels.length - 1" step="1" aria-label="Effort"
                                        :value="Math.max(0, levels.indexOf($wire.effort))"
                                        :style="'--fill: ' + (Math.max(0, levels.indexOf($wire.effort)) / (levels.length - 1) * 100) + '%'"
                                        @input="$wire.effort = levels[$event.target.value]"
                                        @change="$wire.$refresh()">
                                    <div class="ai-effort-scale"><span>Faster</span><span>Smarter</span></div>
                                </div>
                            </div>
                        </div>
                        <span class="flex-1"></span>
                        @if ($busy)
                            <button class="ai-send is-stop" type="button" wire:click="stop" title="Stop" aria-label="Stop"><x-menu-icon name="stop" class="size-4" /></button>
                        @else
                            <button class="ai-send" type="submit" wire:loading.attr="disabled" title="Send" aria-label="Send"><x-menu-icon name="up" class="size-5" /></button>
                        @endif
                    </div>
                </div>
                <p class="ai-hint">Enter to send · Shift+Enter for a new line · paste or drop images · / for the panel's tools</p>
            </div>
        </form>
    </section>
    {{-- An image, big --}}
    <div class="ai-lightbox" x-show="lightbox" x-cloak x-transition.opacity @click="lightbox = null">
        <img :src="lightbox" alt="">
        <button type="button" class="ai-icon" aria-label="Close"><x-menu-icon name="close" class="size-6" /></button>
    </div>
</div>
