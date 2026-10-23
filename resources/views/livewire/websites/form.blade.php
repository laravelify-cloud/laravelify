<form class="card flex flex-col gap-4 p-4" wire:submit="save" x-data="formDraft(@js('laravelify.website-draft.'.$group->id), @js(\App\Livewire\Websites\Form::DRAFT_FIELDS))">
    {{-- A draft of this form is kept in the browser as you type; after a reload it can be restored. --}}
    <div class="draft-banner" x-show="offer" x-cloak role="status">
        <x-menu-icon name="refresh" class="size-4 shrink-0" />
        <p class="min-w-0 flex-1">
            <b>You have an unsaved draft</b>
            <span class="text-muted" x-text="summary()"></span>
        </p>
        <button type="button" class="btn btn-sm" @click="discard()">Start new</button>
        <button type="button" class="btn btn-sm btn-primary" @click="restore()">Restore draft</button>
    </div>
    <p class="draft-saved text-muted" x-show="savedAt && ! offer" x-cloak x-text="'Draft saved ' + savedAt"></p>

    <p class="text-muted">Adding to {{ $group->name }}. PHP sites run on PHP-FPM (Laravel also gets Supervisor workers), Node servers run under PM2, and front-end builds are served by nginx.</p>

    <section class="card relative flex flex-col gap-3 p-4">
        <div class="panel-wait" wire:loading.flex wire:target="selectGithubApp">
            <span class="loader-spinner"></span>
            <p>Fetching repositories from GitHub…</p>
        </div>
        @if ($repository !== '')
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-medium">{{ $repository }}</p>
                    <p class="text-muted">Branch {{ $branch }}@if ($github_app_id !== '') · GitHub App @endif</p>
                </div>
                <button class="btn" type="button" wire:click="clearRepository">Change</button>
            </div>
        @elseif (! $picking)
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-line">
                    <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-3.16 19.49c.5.09.68-.22.68-.48v-1.7c-2.78.6-3.37-1.34-3.37-1.34-.45-1.16-1.1-1.47-1.1-1.47-.9-.62.07-.6.07-.6 1 .07 1.53 1.03 1.53 1.03.89 1.52 2.34 1.08 2.91.83.09-.65.35-1.08.63-1.33-2.22-.25-4.56-1.11-4.56-4.95 0-1.09.39-1.99 1.03-2.69-.1-.25-.45-1.27.1-2.65 0 0 .84-.27 2.75 1.02A9.56 9.56 0 0 1 12 6.8a9.56 9.56 0 0 1 2.5.34c1.9-1.29 2.74-1.02 2.74-1.02.55 1.38.2 2.4.1 2.65.64.7 1.03 1.6 1.03 2.69 0 3.85-2.34 4.7-4.57 4.95.36.31.68.92.68 1.86v2.76c0 .26.18.58.69.48A10 10 0 0 0 12 2z"/></svg>
                </span>
                <div>
                    <h2 class="font-medium">Git Repository (with GitHub App)</h2>
                    <p class="text-muted">Git source</p>
                </div>
            </div>
            <p class="text-muted">Deploy public or private GitHub repositories through a GitHub App, with automatic webhooks and pull request previews.</p>
            <div class="flex items-center justify-between gap-2 border-t border-line pt-3">
                <a class="btn" href="{{ route('sources.index') }}" wire:navigate>Sources</a>
                <button class="btn btn-primary" type="button" wire:click="$set('picking', true)" @disabled($sources->isEmpty())>Deploy</button>
            </div>
            @if ($sources->isEmpty())
                <p class="text-muted">Connect a GitHub App under Sources before deploying a private repository.</p>
            @endif
        @elseif ($github_app_id === '')
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="font-medium">Choose GitHub App</h2>
                    <p class="text-muted">Select the installation that can access the repository you want to deploy.</p>
                </div>
                <a class="btn" href="{{ route('sources.github.create') }}" wire:navigate>+ Add GitHub App</a>
            </div>
            <div class="flex flex-col gap-2">
                @forelse ($sources as $source)
                    <button class="source-option" type="button" wire:click="selectGithubApp({{ $source->id }})">
                        <span class="min-w-0">
                            <span class="block font-medium">{{ $source->name }}</span>
                            <span class="block text-muted">{{ $source->html_url }}</span>
                        </span>
                    </button>
                @empty
                    <p class="text-muted">No connected GitHub App yet.</p>
                @endforelse
            </div>
            @error('github_app_id') <small class="error">{{ $message }}</small> @enderror
        @else
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-medium">Choose repository</h2>
                    <p class="text-muted">Search repositories available through this GitHub App.</p>
                </div>
                <a class="btn" href="{{ route('sources.index') }}" wire:navigate>+ Add GitHub App</a>
            </div>
            <div class="repo-picker" x-data="repoPicker(@js($repositories))">
                <div class="repo-picker-search">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5" /><path d="M20 20l-4.3-4.3" /></svg>
                    <input x-ref="search" x-model="q" @input="index = 0" @keydown="keys($event)" placeholder="Search {{ count($repositories) }} repositories by name, owner or language…" autocomplete="off" spellcheck="false" x-init="$nextTick(() => $el.focus())">
                    <span class="repo-picker-count" x-text="results().length + ' / ' + repos.length"></span>
                </div>
                <div class="repo-picker-list" x-ref="list" role="listbox">
                    <template x-for="(repo, i) in results()" :key="repo.clone_url">
                        <button type="button" class="repo-row" role="option" :class="{ 'is-active': i === index, 'is-selected': repo.clone_url === $wire.selectedRepository }" @mouseenter="index = i" @click="pick(repo)">
                            <span class="repo-row-icon" :title="repo.private ? 'Private' : 'Public'">
                                <svg x-show="repo.private" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
                                <svg x-show="! repo.private" viewBox="0 0 24 24"><path d="M4 5a2 2 0 0 1 2-2h12v16H6a2 2 0 0 0-2 2z" /><path d="M4 19a2 2 0 0 1 2-2h12" /></svg>
                            </span>
                            <span class="repo-row-main">
                                <span class="repo-row-name"><span class="text-muted" x-text="repo.name.split('/')[0] + ' / '"></span><b x-text="repo.name.split('/').slice(1).join('/')"></b></span>
                                <span class="repo-row-desc" x-show="repo.description" x-text="repo.description"></span>
                            </span>
                            <span class="repo-row-meta">
                                <span x-show="repo.language" class="repo-row-lang"><i :style="'background:' + color(repo.language)"></i><span x-text="repo.language"></span></span>
                                <span class="repo-row-branch"><svg viewBox="0 0 24 24"><circle cx="6" cy="5" r="2" /><circle cx="6" cy="19" r="2" /><circle cx="18" cy="9" r="2" /><path d="M6 7v10M18 11c0 4-6 3-12 6" /></svg><span x-text="repo.branch"></span></span>
                                <span x-show="repo.pushed_at" x-text="ago(repo.pushed_at)"></span>
                                <span class="repo-row-badge" x-text="repo.private ? 'Private' : 'Public'"></span>
                            </span>
                        </button>
                    </template>
                    <p class="repo-picker-empty" x-show="results().length === 0">No repository matches “<span x-text="q"></span>”. Is the GitHub App installed on it?</p>
                </div>
                <div class="repo-picker-foot">
                    <span class="text-muted">↑↓ to move · Enter to load · click a repository to load it</span>
                    @error('repository') <small class="error">{{ $message }}</small> @enderror
                    <span class="loader-spinner" wire:loading wire:target="loadRepository" style="width: 14px; height: 14px; border-width: 2px"></span>
                    <span class="text-muted" wire:loading wire:target="loadRepository">Reading the repository…</span>
                </div>
            </div>
        @endif
    </section>

    <label class="field">
        <span>Name</span>
        <input class="input" wire:model="name" required>
        @error('name') <small class="error">{{ $message }}</small> @enderror
    </label>

    <div class="field">
        <span>What are you deploying?</span>
        @include('livewire.websites.partials.type-picker')
        @error('type') <small class="error">{{ $message }}</small> @enderror
    </div>

    <div class="field" x-data="{ access: @js($domain === '' && $http_port !== '' ? 'port' : 'domain') }">
        <span>Address</span>
        <div class="segmented self-start" role="group" aria-label="How to open the site">
            <button type="button" :aria-pressed="access === 'domain'" x-on:click="access = 'domain'; $wire.http_port = ''">Domain</button>
            <button type="button" :aria-pressed="access === 'port'" x-on:click="access = 'port'; $wire.domain = ''">HTTP port</button>
        </div>

        <div class="flex flex-col gap-2" x-show="access === 'domain'" x-data="domainPicker()" x-init="load()">
            <div class="domain-input" @click.outside="suggest = false">
                <input class="input" wire:model.live.debounce.300ms="domain" x-model="value" @focus="suggest = true" @keydown.escape="suggest = false" placeholder="app.example.com" autocomplete="off" spellcheck="false">
                <div class="domain-suggest card" x-show="suggest && matches().length" x-cloak>
                    <p class="domain-suggest-head">Already in Cloudflare</p>
                    <template x-for="record in matches()" :key="record.name">
                        <button type="button" class="domain-suggest-row" @click="value = record.name; $wire.set('domain', record.name); suggest = false">
                            <span class="font-mono" x-text="record.name"></span>
                            <span class="domain-tag" :class="record.website ? 'is-site' : ''" x-text="record.website ? 'website: ' + record.website : record.type + ' → ' + record.content"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- What the typed name is now: free, in DNS already, or a website's --}}
            <p class="domain-state" x-show="value.trim() !== ''" x-cloak :class="'is-' + state().kind">
                <span x-text="state().icon"></span>
                <span x-text="state().text"></span>
            </p>

            <div class="domain-zones" x-show="zones.length" x-cloak>
                <span class="text-[12px] text-muted">Random free subdomain on</span>
                <template x-for="zone in zones" :key="zone.name">
                    <button type="button" class="chip" @click="$wire.randomDomain(zone.name).then(() => value = $wire.domain)" :title="'Make a random subdomain of ' + zone.name">
                        <x-menu-icon name="refresh" class="size-3.5" /> <span x-text="zone.name"></span>
                    </button>
                </template>
                <button type="button" class="text-[12px] text-muted hover:text-ink" @click="taken = ! taken" x-text="(taken ? 'Hide' : 'Show') + ' used subdomains (' + total() + ')'"></button>
                <button type="button" class="text-[12px] text-muted hover:text-ink" @click="load(true)" title="Read Cloudflare again">Refresh</button>
            </div>
            <div class="domain-taken card" x-show="taken" x-cloak>
                <template x-for="zone in zones" :key="'t' + zone.name">
                    <div>
                        <p class="domain-suggest-head" x-text="zone.name + ' · ' + zone.records.length + ' names'"></p>
                        <template x-for="record in zone.records" :key="record.name">
                            <div class="domain-suggest-row">
                                <span class="font-mono" x-text="record.name"></span>
                                <span class="domain-tag" :class="record.website ? 'is-site' : ''" x-text="record.website ? 'website: ' + record.website : record.type + ' → ' + record.content"></span>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            <small class="text-muted" x-show="loading" x-cloak>Reading your domains from Cloudflare…</small>
            <small class="error" x-show="error" x-cloak x-text="error"></small>
            <small class="text-muted">Needed for a Cloudflare tunnel.</small>
            @error('domain') <small class="error">{{ $message }}</small> @enderror

            <label class="flex items-start gap-2 rounded-lg border border-line bg-recessed px-3 py-2.5" x-show="isApexDomain($wire.domain) && !($wire.domain || '').trim().toLowerCase().startsWith('www.')" x-cloak>
                <input class="mt-0.5" type="checkbox" wire:model="include_www">
                <span>
                    <span class="block text-[13.5px] font-medium">Also open on <b x-text="'www.' + ($wire.domain || '').trim().toLowerCase()"></b></span>
                    <span class="block text-[12px] text-muted">nginx answers on both names{{ $cloudflareApi ? ', and the tunnel route and DNS record are added in Cloudflare' : '' }}.</span>
                </span>
            </label>

            <div x-data="{ more: @js($extra_domains !== '') }">
                <button class="text-[12.5px] text-muted hover:text-ink" type="button" @click="more = ! more">
                    <x-menu-icon name="chevron-right" class="inline size-3.5 transition-transform" ::class="more && 'rotate-90'" /> Other domains for the same website
                </button>
                <div class="mt-2" x-show="more" x-cloak>
                    <textarea class="input min-h-20 font-mono text-[13px]" wire:model="extra_domains" spellcheck="false" placeholder="second-domain.com&#10;www.second-domain.com"></textarea>
                    <small class="text-muted">One domain per line. They all open this one website; no second website needed.</small>
                    @error('aliases') <small class="error block">{{ $message }}</small> @enderror
                </div>
            </div>

            @if (! $cloudflareApi)
                <small class="text-muted">With a Cloudflare API token (Settings → Cloudflare), Laravelify also adds each domain's tunnel route and DNS record for you.</small>
            @endif
        </div>

        <div x-show="access === 'port'" x-cloak>
            <div class="flex gap-2">
                <input class="input min-w-0 flex-1" wire:model="http_port" inputmode="numeric" placeholder="8081">
                <button class="btn" type="button" wire:click="randomPort" title="Pick a random port no website or app uses"><x-menu-icon name="refresh" class="size-4" /> Random free port</button>
                @include('livewire.websites.partials.ports-modal', ['model' => 'http_port'])
            </div>
            <small class="text-muted">Leave empty for the next free port from 8081, or pick a random free one. The site opens on http://{{ \App\Support\ServerAddress::publicIp() ?? 'SERVER_IP' }}:port, and the port is opened in the firewall (ufw).</small>
            @error('http_port') <small class="error">{{ $message }}</small> @enderror
        </div>
    </div>

    @if ($hasExample)
        <div class="field">
            <span>Source</span>
            <div class="segmented self-start" role="group" aria-label="Where the code comes from">
                <button type="button" aria-pressed="{{ $use_example || $create_repository ? 'false' : 'true' }}" wire:click="chooseSource('git')">Git repository</button>
                <button type="button" aria-pressed="{{ $use_example ? 'true' : 'false' }}" wire:click="chooseSource('example')">Example project</button>
                @if ($sources->isNotEmpty())
                    <button type="button" aria-pressed="{{ $create_repository ? 'true' : 'false' }}" wire:click="chooseSource('new')">New GitHub repository</button>
                @endif
            </div>
            @if ($use_example)
                <small class="text-muted">A small working {{ $definition['label'] }} app, ready to deploy. No repository needed.</small>
            @elseif ($create_repository)
                <small class="text-muted">Laravelify creates a new repository with the {{ $definition['label'] }} example, pushes it and deploys it. Edit it later in the code editor: every push deploys again.</small>
            @endif
        </div>

        @if ($create_repository)
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="field">
                    <span>GitHub App</span>
                    <select class="input" wire:model="github_app_id">
                        <option value="">Choose a GitHub App</option>
                        @foreach ($sources as $source)
                            <option value="{{ $source->id }}">{{ $source->name }}{{ $source->organization ? ' ('.$source->organization.')' : '' }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">The repository is made in the organization the app is installed on.</small>
                    @error('github_app_id') <small class="error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>Repository name</span>
                    <input class="input" wire:model="new_repository_name" placeholder="{{ \Illuminate\Support\Str::slug($name) ?: 'my-project' }}">
                    <label class="flex items-center gap-2 text-[12.5px] text-muted"><input type="checkbox" wire:model="new_repository_private"> Private repository</label>
                    @error('new_repository_name') <small class="error">{{ $message }}</small> @enderror
                </label>
            </div>
        @endif
    @endif

    @unless (($use_example || $create_repository) && $hasExample)
    <label class="field">
        <span>Git repository</span>
        <input class="input" wire:model="repository" placeholder="https://github.com/org/repo.git">
        @error('repository') <small class="error">{{ $message }}</small> @enderror
    </label>

    <label class="field">
        <span>Branch</span>
        <input class="input" wire:model="branch" required>
        <small class="text-muted">https://github.com/laravel/laravel.git uses branch 13.x.</small>
        @error('branch') <small class="error">{{ $message }}</small> @enderror
    </label>
    @endunless

    <div class="callout" role="note">
        <x-menu-icon name="{{ $definition['icon'] }}" class="size-4 shrink-0 text-muted" />
        <p class="min-w-0 flex-1 text-[12.5px] text-muted">
            <span class="font-medium text-ink">{{ $definition['label'] }}</span>
            @if ($runtime === 'php')
                runs on nginx and PHP-FPM{{ $type === 'laravel' ? ', with Supervisor workers for the queue and scheduler' : '' }}.
            @elseif ($runtime === 'node')
                runs as a Node server under PM2, and nginx forwards the domain to its port.
            @else
                is built on the server, then nginx serves the files{{ ($definition['spa'] ?? false) ? ' and falls back to index.html for client-side routes' : '' }}.
            @endif
        </p>
    </div>

    @if ($runtime === 'php')
        <div class="field">
            <span>PHP version</span>
            <div class="flex flex-wrap gap-2">
                @foreach ($phpVersions as $version)
                    <button class="choice {{ $php_version === $version ? 'active' : '' }}" type="button" wire:click="$set('php_version', '{{ $version }}')">{{ $version }}</button>
                @endforeach
            </div>
            @error('php_version') <small class="error">{{ $message }}</small> @enderror
        </div>
        @if ($type === 'php')
            <label class="field">
                <span>Web root folder</span>
                <input class="input font-mono" wire:model="public_dir" placeholder="public">
                <small class="text-muted">The folder inside the repository that nginx serves. Use <code>.</code> for the repository root.</small>
                @error('public_dir') <small class="error">{{ $message }}</small> @enderror
            </label>
        @else
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model="enable_reverb">
                <span>Run Laravel Reverb (websocket) under Supervisor</span>
            </label>
        @endif
        <label class="flex items-center gap-2">
            <input type="checkbox" wire:model="provision_database">
            <span>Create a MySQL database and user for this site</span>
        </label>
    @endif

    @if ($runtime === 'node' || ($runtime === 'static' && $type !== 'static'))
        <div class="field">
            <span>Node.js version</span>
            <div class="flex flex-wrap gap-2">
                @foreach ($nodeVersions as $version)
                    <button class="choice {{ $node_version === $version ? 'active' : '' }}" type="button" wire:click="$set('node_version', '{{ $version }}')">{{ $version }}</button>
                @endforeach
            </div>
            @error('node_version') <small class="error">{{ $message }}</small> @enderror
        </div>
        @if ($runtime === 'node')
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model="provision_database">
                <span>Create a MySQL database and user for this site</span>
            </label>
        @endif

        <details class="card p-3">
            <summary class="cursor-pointer text-[13.5px] font-medium">Build settings <span class="font-normal text-muted">(optional, the defaults work for most projects)</span></summary>
            <div class="mt-3 flex flex-col gap-3">
                <label class="field">
                    <span>Install command</span>
                    <input class="input font-mono" wire:model="install_command" placeholder="auto: npm ci, pnpm install, or yarn install, from your lockfile">
                    @error('install_command') <small class="error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>Build command</span>
                    <input class="input font-mono" wire:model="build_command" placeholder="{{ $definition['build'] ?? 'none' }}">
                    @error('build_command') <small class="error">{{ $message }}</small> @enderror
                </label>
                @if ($runtime === 'node' && $type !== 'nextjs')
                    <label class="field">
                        <span>Start command</span>
                        <input class="input font-mono" wire:model="start_command" placeholder="{{ $definition['start'] ?? 'npm start' }}">
                        <small class="text-muted">PM2 runs this from the site folder. The app must listen on the <code>PORT</code> environment variable.</small>
                        @error('start_command') <small class="error">{{ $message }}</small> @enderror
                    </label>
                @endif
                @if ($runtime === 'static')
                    <label class="field">
                        <span>Output folder</span>
                        <input class="input font-mono" wire:model="public_dir" placeholder="auto: {{ implode(', ', $definition['output'] ?? ['dist']) }}">
                        <small class="text-muted">The folder the build writes to, which nginx serves. Leave empty to detect it.</small>
                        @error('public_dir') <small class="error">{{ $message }}</small> @enderror
                    </label>
                @endif
            </div>
        </details>
    @endif

    @if ($type === 'static')
        <label class="field">
            <span>Folder to serve</span>
            <input class="input font-mono" wire:model="public_dir" placeholder=". (the repository root)">
            <small class="text-muted">A folder inside the repository, like <code>docs</code> or <code>public</code>. Empty serves the repository root.</small>
            @error('public_dir') <small class="error">{{ $message }}</small> @enderror
        </label>
    @endif

    <label class="field">
        <span>GitHub App</span>
        <select class="select" wire:model="github_app_id">
            <option value="">Personal access token or public repository</option>
            @foreach ($sources as $source)
                <option value="{{ $source->id }}">{{ $source->name }}</option>
            @endforeach
        </select>
        <small class="text-muted">Private repositories use the selected GitHub App installation token.</small>
        @error('github_app_id') <small class="error">{{ $message }}</small> @enderror
    </label>

    <label class="field">
        <span>Cloudflare tunnel</span>
        <select class="select" wire:model="cloudflare_tunnel_id">
            <option value="">None</option>
            @foreach ($tunnels as $tunnel)
                <option value="{{ $tunnel->id }}">{{ $tunnel->name }}</option>
            @endforeach
        </select>
        <small class="text-muted">One website uses one tunnel. One tunnel can serve many websites.</small>
        @error('cloudflare_tunnel_id') <small class="error">{{ $message }}</small> @enderror
    </label>

    <div class="flex gap-2">
        <button class="btn btn-primary" type="submit">Create website</button>
        <a class="btn" href="{{ route('groups.show', $group) }}" wire:navigate>Cancel</a>
    </div>
</form>
