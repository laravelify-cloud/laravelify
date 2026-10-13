<form class="card flex flex-col gap-4 p-4" wire:submit="save">
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
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="field relative min-w-0 flex-1" x-data="{ open: false, q: '', repos: @js($repositories) }" @click.outside="open = false">
                    <span>Repository</span>
                    <button class="input text-left" type="button" @click="open = !open">
                        <span x-text="(repos.find(repo => repo.clone_url === $wire.selectedRepository) || {}).name || 'Select a repository'"></span>
                    </button>
                    <div class="card repo-menu" x-cloak x-show="open" x-transition.opacity>
                        <input class="input" x-model="q" placeholder="Search repositories..." @click.stop>
                        <template x-for="repo in repos.filter(repo => repo.name.toLowerCase().includes(q.toLowerCase()))" :key="repo.clone_url">
                            <button class="repo-option" type="button" @click="$wire.selectedRepository = repo.clone_url; open = false" x-text="repo.name"></button>
                        </template>
                    </div>
                    @error('repository') <small class="error">{{ $message }}</small> @enderror
                </div>
                <button class="btn btn-primary" type="button" wire:click="loadRepository">Load repository</button>
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

        <div x-show="access === 'domain'">
            <input class="input" wire:model="domain" placeholder="api.example.com">
            <small class="text-muted">Needed for a Cloudflare tunnel.</small>
            @error('domain') <small class="error">{{ $message }}</small> @enderror
        </div>

        <div x-show="access === 'port'" x-cloak>
            <input class="input" wire:model="http_port" inputmode="numeric" placeholder="8081">
            <small class="text-muted">Leave empty for the next free port from 8081, then open http://127.0.0.1:port.</small>
            @error('http_port') <small class="error">{{ $message }}</small> @enderror
        </div>
    </div>

    @if ($hasExample)
        <div class="field">
            <span>Source</span>
            <div class="segmented self-start" role="group" aria-label="Where the code comes from">
                <button type="button" aria-pressed="{{ $use_example ? 'false' : 'true' }}" wire:click="$set('use_example', false)">Git repository</button>
                <button type="button" aria-pressed="{{ $use_example ? 'true' : 'false' }}" wire:click="$set('use_example', true)">Example project</button>
            </div>
            @if ($use_example)
                <small class="text-muted">A small working {{ $definition['label'] }} app, ready to deploy. No repository needed.</small>
            @endif
        </div>
    @endif

    @unless ($use_example && $hasExample)
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
