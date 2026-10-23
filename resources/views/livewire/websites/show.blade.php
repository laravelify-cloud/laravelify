@php
    $latestDeployment = $deployments->first();
    $publicUrl = $website->publicUrl();
    $repositoryUrl = preg_match('#^https?://#', (string) $website->repository) === 1 ? preg_replace('/\.git$/', '', (string) $website->repository) : null;
    $navGroups = [
        'settings' => ['Settings', ['general' => ['General', 'settings'], 'environment' => ['Environment', 'variables'], 'runtime' => ['Runtime', 'runtime'], 'nginx' => ['Nginx', 'nginx'], 'security' => ['Security', 'shield'], 'redirects' => ['Redirects', 'arrow-up-right']]],
        'observe' => ['Observe', ['monitoring' => ['Monitoring', 'chart'], 'deployments' => ['Deployments', 'deployments'], 'logs' => ['Logs', 'logs'], 'health' => ['Health', 'pulse'], 'processes' => ['Processes', 'processes'], 'terminal' => ['Terminal', 'terminal'], 'files' => ['Files', 'files']]],
        'operations' => ['Operations', ['danger' => ['Danger zone', 'danger']]],
    ];

    if (! $website->hasProcessFile()) {
        unset($navGroups['observe'][1]['processes']);
    }
@endphp

<div
    x-data="websitePage()"
    class="flex flex-col gap-6"
    @input="markDirty($event)"
    @change="markDirty($event)"
    @saved.window="clearDirty()"
    @if ($deploying) wire:poll.2s @endif
>
    {{-- Resource header --}}
    <div class="page-header">
        <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted">
                <a class="transition-colors hover:text-ink" href="{{ route('groups.index') }}" wire:navigate>Groups</a>
                <x-menu-icon name="chevron-right" class="size-3.5 opacity-60" />
                <a class="transition-colors hover:text-ink" href="{{ route('groups.show', $website->group) }}" wire:navigate>{{ $website->group->name }}</a>
            </p>
            <div class="mt-1 flex min-w-0 flex-wrap items-center gap-2.5">
                <h2 class="page-title truncate">{{ $website->name }}</h2>
                <span class="pill">{{ $website->typeLabel() }}</span>
                @unless ($website->enabled)
                    <span class="status-badge" style="--status-color: var(--color-muted)">Turned off</span>
                @endunless
                @if ($latestDeployment)
                    <x-status-badge :status="$latestDeployment->status" />
                @endif
            </div>
        </div>

        <div class="flex items-center gap-1.5">
            {{-- Links --}}
            <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                <button class="app-tab" type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="menu" title="Open website links">
                    <x-menu-icon name="external" class="size-3.5 opacity-70" />
                    Links
                    <x-menu-icon name="chevron-down" class="chevron" />
                </button>
                <div
                    class="dropdown dropdown-right"
                    role="menu"
                    x-cloak
                    x-show="open"
                    x-transition:enter="dropdown-in"
                    x-transition:enter-start="dropdown-from"
                    x-transition:enter-end="dropdown-to"
                    x-transition:leave="dropdown-out"
                    x-transition:leave-start="dropdown-to"
                    x-transition:leave-end="dropdown-from"
                    style="width: 22rem"
                >
                    @if ($publicUrl)
                        <a class="dropdown-option" role="menuitem" href="{{ $publicUrl }}" target="_blank" rel="noreferrer" @click="open = false">
                            <span class="tag-success">Live</span>
                            <span class="min-w-0 flex-1 truncate">{{ preg_replace('#^https?://#', '', $publicUrl) }}</span>
                            <x-menu-icon name="external" class="ml-auto size-3.5" />
                        </a>
                    @else
                        <span class="dropdown-option cursor-default text-muted">No public address yet. Set a domain or port.</span>
                    @endif
                    @if ($repositoryUrl)
                        <a class="dropdown-option" role="menuitem" href="{{ $repositoryUrl }}" target="_blank" rel="noreferrer" @click="open = false">
                            <x-menu-icon name="git" />
                            <span class="min-w-0 flex-1 truncate">{{ preg_replace('#^https?://(www\.)?#', '', $repositoryUrl) }}</span>
                            <span class="tag-neutral normal-case">{{ $website->branch }}</span>
                        </a>
                    @endif
                    <div class="dropdown-sep"></div>
                    <button class="dropdown-option" role="menuitem" type="button" @click="copyToClipboard(@js($webhookUrl), 'Webhook URL copied.'); open = false">
                        <x-menu-icon name="webhook" />
                        <span class="flex-1">Copy webhook URL</span>
                    </button>
                    <button class="dropdown-option" role="menuitem" type="button" @click="copyToClipboard(@js((string) $website->deploy_path), 'Path copied.'); open = false" title="{{ $website->deploy_path }}">
                        <x-menu-icon name="files" />
                        <span class="min-w-0 flex-1 truncate font-mono text-[12px] text-muted">{{ $website->deploy_path }}</span>
                        <x-menu-icon name="copy" class="ml-auto size-3.5" />
                    </button>
                </div>
            </div>

            @if (app(\App\Services\ProjectWorkspace::class)->supports($website))
                <a class="app-tab" href="{{ route('websites.vscode', $website) }}" target="_blank" rel="noopener" title="VS Code with the AI chat, terminal and Git. Commit and push to deploy; it stops by itself after 30 minutes idle.">
                    <x-menu-icon name="files" class="size-3.5 opacity-70" />
                    Code editor
                    <x-menu-icon name="external" class="size-3 opacity-60" />
                </a>
            @endif

            {{-- Preview: the site at a random path of the panel's own address --}}
            @php
                $previews = app(\App\Services\PanelPreview::class);
                $previewUrl = $previews->url($website);
                $previewMode = $previews->mode($website);
                $canSubdomain = $previews->canUseSubdomain($website);
            @endphp
            @if ($previewUrl || $website->domain || $website->http_port)
                <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <button class="app-tab" type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="menu" title="Preview through the panel's address">
                        <span class="preview-dot {{ $previewUrl ? 'is-on' : '' }}"></span>
                        Preview
                        <x-menu-icon name="chevron-down" class="chevron" />
                    </button>
                    <div
                        class="dropdown dropdown-right"
                        role="menu"
                        x-cloak
                        x-show="open"
                        x-transition:enter="dropdown-in"
                        x-transition:enter-start="dropdown-from"
                        x-transition:enter-end="dropdown-to"
                        x-transition:leave="dropdown-out"
                        x-transition:leave-start="dropdown-to"
                        x-transition:leave-end="dropdown-from"
                        style="width: 22rem"
                    >
                        @if ($previewUrl)
                            <a class="dropdown-option" role="menuitem" href="{{ $previewUrl }}" target="_blank" rel="noreferrer" @click="open = false">
                                <span class="tag-success">On</span>
                                <span class="min-w-0 flex-1 truncate font-mono text-[12px]">{{ preg_replace('#^https?://#', '', $previewUrl) }}</span>
                                <x-menu-icon name="external" class="ml-auto size-3.5" />
                            </a>
                            <button class="dropdown-option" role="menuitem" type="button" @click="copyToClipboard(@js($previewUrl), 'Preview link copied.'); open = false">
                                <x-menu-icon name="copy" />
                                <span class="flex-1">Copy preview link</span>
                            </button>
                            <p class="preview-kind">{{ $previewMode === 'subdomain' ? 'Own subdomain through the Cloudflare tunnel: the app runs exactly as on its domain.' : 'A path of the panel\'s address: apps that build their own links may leave it.' }}</p>
                            <div class="dropdown-sep"></div>
                            <button class="dropdown-option" role="menuitem" type="button" wire:click="enablePreview(true)" @click="open = false" title="The old link stops working">
                                <x-menu-icon name="refresh" />
                                <span class="flex-1">New random address</span>
                            </button>
                            @if ($previewMode === 'path' && $canSubdomain)
                                <button class="dropdown-option" role="menuitem" type="button" wire:click="enablePreview(true, 'subdomain')" @click="open = false">
                                    <x-menu-icon name="external" />
                                    <span class="flex-1">Switch to a random subdomain</span>
                                </button>
                            @elseif ($previewMode === 'subdomain')
                                <button class="dropdown-option" role="menuitem" type="button" wire:click="enablePreview(true, 'path')" @click="open = false">
                                    <x-menu-icon name="external" />
                                    <span class="flex-1">Switch to a panel path (/p/…)</span>
                                </button>
                            @endif
                            <button class="dropdown-option text-danger" role="menuitem" type="button" wire:click="disablePreview" @click="open = false">
                                <x-menu-icon name="pause" />
                                <span class="flex-1">Turn off preview</span>
                            </button>
                        @else
                            <div class="preview-intro">
                                <b>Preview is off</b>
                                <p>Opens this website at a random, hard-to-guess address, handy before its domain or DNS is ready. Anyone with the link can open it.</p>
                            </div>
                            @if ($canSubdomain)
                                <button class="dropdown-option is-rich" role="menuitem" type="button" wire:click="enablePreview(false, 'subdomain')" @click="open = false">
                                    <x-menu-icon name="play" class="size-4" />
                                    <span class="option-text">
                                        <span class="option-title">Random subdomain <span class="tag-success">Best</span></span>
                                        <span class="option-desc">Like calm-river-4f2a.yourdomain.com, through the Cloudflare tunnel.</span>
                                    </span>
                                </button>
                            @endif
                            <button class="dropdown-option is-rich" role="menuitem" type="button" wire:click="enablePreview(false, 'path')" @click="open = false">
                                <x-menu-icon name="play" class="size-4" />
                                <span class="option-text">
                                    <span class="option-title">Panel path</span>
                                    <span class="option-desc font-mono">{{ preg_replace('#^https?://#', '', rtrim((string) config('app.url'), '/')) }}/p/…</span>
                                </span>
                            </button>
                            @unless ($canSubdomain)
                                <p class="preview-kind">Save a Cloudflare API token (Settings → Cloudflare) to preview on a random subdomain instead: apps then work exactly as on their own domain.</p>
                            @endunless
                        @endif
                    </div>
                </div>
            @endif

            {{-- Deploy --}}
            <div class="split-action" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                <button class="btn btn-primary" type="button" wire:click="deploy" @disabled($deploying) title="{{ $deploying ? 'A deploy is already running' : 'Deploy the latest commit' }}">
                    @if ($deploying)
                        <span class="loader-spinner" style="width: 13px; height: 13px; border-width: 2px; border-color: rgb(255 255 255 / 0.3); border-top-color: #fff"></span>
                        Deploying
                    @else
                        <x-menu-icon name="rocket" /> Deploy
                    @endif
                </button>
                <button class="btn btn-primary split-caret" type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="menu" aria-label="More deploy actions">
                    <x-menu-icon name="chevron-down" class="size-3.5" />
                </button>
                <div
                    class="dropdown dropdown-right"
                    role="menu"
                    x-cloak
                    x-show="open"
                    x-transition:enter="dropdown-in"
                    x-transition:enter-start="dropdown-from"
                    x-transition:enter-end="dropdown-to"
                    x-transition:leave="dropdown-out"
                    x-transition:leave-start="dropdown-to"
                    x-transition:leave-end="dropdown-from"
                >
                    <button class="dropdown-option" role="menuitem" type="button" @click="setTab('deployments'); open = false">
                        <x-menu-icon name="deployments" /> View deployment logs
                    </button>
                    <button class="dropdown-option" role="menuitem" type="button" wire:click="deploy" @click="open = false" @disabled($deploying) @class(['opacity-40' => $deploying])>
                        <x-menu-icon name="rocket" /> Deploy now
                    </button>
                    <div class="dropdown-sep"></div>
                    <button class="dropdown-option is-danger" role="menuitem" type="button" wire:click="stopDeploy" @click="open = false" @disabled(! $deploying) @class(['opacity-40' => ! $deploying])>
                        <x-menu-icon name="stop" /> Stop deploy
                    </button>
                </div>
            </div>
        </div>
    </div>

    @error('website') <p class="error">{{ $message }}</p> @enderror
    @error('deploy') <p class="error">{{ $message }}</p> @enderror

    <livewire:websites.environments :website="$website" :key="'environments-'.$website->id" />

    <div class="grid items-start gap-8 xl:grid-cols-[210px_minmax(0,1fr)]">
        {{-- Grouped settings sidebar --}}
        <aside class="submenu xl:sticky xl:top-[4.5rem]">
            <nav class="flex flex-col gap-px" aria-label="Website settings">
                @foreach ($navGroups as $groupKey => [$groupLabel, $items])
                    @if (! $loop->first)
                        <div class="menu-divider"></div>
                    @endif
                    <button class="nav-section-toggle" type="button" @click="groups.{{ $groupKey }} = !groups.{{ $groupKey }}" :aria-expanded="groups.{{ $groupKey }}">
                        <span>{{ $groupLabel }}</span>
                        <x-menu-icon name="chevron-down" class="size-3 transition-transform" x-bind:class="!groups.{{ $groupKey }} && '-rotate-90'" />
                    </button>
                    <div class="flex flex-col gap-px" x-show="groups.{{ $groupKey }}" x-collapse>
                        @foreach ($items as $key => [$label, $icon])
                            <button class="menu-item" type="button" :class="tab === '{{ $key }}' && 'menu-item-active'" @click="setTab('{{ $key }}')">
                                <x-menu-icon name="{{ $icon }}" />
                                <span>{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>
                @endforeach
            </nav>
        </aside>

        <div class="flex min-w-0 flex-col gap-6">
            {{-- General --}}
            <x-layer-card title="General" helper="Name, address, and where the code comes from." x-show="tab === 'general'" x-cloak>
                <x-slot:actions>
                    <button class="btn btn-primary" type="submit" form="website-general">Save</button>
                </x-slot:actions>
                <form id="website-general" class="grid gap-4 md:grid-cols-2" wire:submit="saveGeneral">
                    <label class="field">
                        <span>Name</span>
                        <input class="input" wire:model="name" required>
                        @error('name') <small class="error">{{ $message }}</small> @enderror
                    </label>
                    <label class="field">
                        <span>Domain</span>
                        <input class="input" wire:model="domain" placeholder="app.example.com (optional)">
                        @error('domain') <small class="error">{{ $message }}</small> @enderror
                    </label>
                    <div class="field">
                        <span>Other domains</span>
                        <textarea class="input min-h-20 font-mono text-[13px]" wire:model="aliases_text" spellcheck="false" placeholder="www.example.com&#10;second-domain.com"></textarea>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <small class="text-muted">One per line. Every domain opens this same website, and nginx{{ $website->tunnel ? ', the tunnel' : '' }} answers on all of them.</small>
                            <button class="text-[12.5px] text-accent hover:underline" type="button"
                                x-data
                                x-show="isApexDomain($wire.domain) && !($wire.aliases_text || '').split(/\s+/).includes('www.' + ($wire.domain || '').trim().toLowerCase().replace(/^www\./, ''))"
                                @click="$wire.aliases_text = (($wire.aliases_text || '').trim() + '\nwww.' + ($wire.domain || '').trim().toLowerCase().replace(/^www\./, '')).trim()">
                                + Add www.{{ preg_replace('/^www\./', '', (string) $website->domain) ?: 'your-domain' }}
                            </button>
                        </div>
                        @error('aliases') <small class="error">{{ $message }}</small> @enderror
                    </div>
                    <div class="field">
                        <label class="label" for="website-http-port">HTTP port</label>
                        <div class="flex gap-2">
                            <input class="input min-w-0 flex-1" id="website-http-port" wire:model="http_port" inputmode="numeric" placeholder="8081">
                            @include('livewire.websites.partials.ports-modal', ['model' => 'http_port'])
                        </div>
                        <small class="text-muted">Used when the domain is empty.</small>
                        @error('http_port') <small class="error">{{ $message }}</small> @enderror
                    </div>
                    <label class="field">
                        <span>Branch</span>
                        <input class="input" wire:model="branch" required>
                        @error('branch') <small class="error">{{ $message }}</small> @enderror
                    </label>
                    <label class="field md:col-span-2">
                        <span>Git repository</span>
                        <input class="input font-mono" wire:model="repository" placeholder="https://github.com/org/repo.git">
                        @error('repository') <small class="error">{{ $message }}</small> @enderror
                    </label>
                    <label class="field">
                        <span>GitHub App</span>
                        <select class="select" wire:model="github_app_id">
                            <option value="">Personal access token or public repository</option>
                            @foreach ($sources as $source)
                                <option value="{{ $source->id }}">{{ $source->name }}</option>
                            @endforeach
                        </select>
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
                        @error('cloudflare_tunnel_id') <small class="error">{{ $message }}</small> @enderror
                        @if ($website->domain && ! $website->cloudflare_tunnel_id && $tunnels->isNotEmpty())
                            <small class="tunnel-warning">
                                <x-menu-icon name="danger" class="size-3.5 shrink-0" />
                                <span>No tunnel: {{ $website->domain }} gets no tunnel route and no DNS record, so it does not open. Choose {{ $tunnels->first()->name }} and save; the route and record are added for you.</span>
                            </small>
                        @endif
                    </label>
                </form>
            </x-layer-card>

            @if ($website->isPhp() || $website->isNode())
                <x-layer-card title="Database" helper="A MySQL database and user for this site." x-show="tab === 'general'" x-cloak>
                    <x-slot:actions>
                        <button class="btn" type="button" wire:click="provisionDatabase">{{ $website->db_name ? 'Re-provision' : 'Provision MySQL' }}</button>
                    </x-slot:actions>
                    @if ($website->db_name)
                        <dl class="grid gap-3 text-[13px] sm:grid-cols-2">
                            <div><dt class="text-muted">Database</dt><dd class="mt-0.5 font-mono">{{ $website->db_name }}</dd></div>
                            <div><dt class="text-muted">User</dt><dd class="mt-0.5 font-mono">{{ $website->db_username }}</dd></div>
                            <div class="sm:col-span-2" x-data="secretText('db_password')">
                                <dt class="text-muted">Password</dt>
                                <dd class="mt-0.5 flex items-center gap-1.5">
                                    <code class="min-w-0 truncate" x-text="shown ? value : '••••••••••••••••'"></code>
                                    <button class="icon-btn" type="button" @click="toggle()" :title="shown ? 'Hide' : 'Show (asks for your authenticator code)'">
                                        <x-menu-icon name="eye" class="size-4" x-show="!shown" />
                                        <x-menu-icon name="eye-off" class="size-4" x-cloak x-show="shown" />
                                    </button>
                                    <button class="icon-btn" type="button" title="Copy" @click="copy()"><x-menu-icon name="copy" class="size-4" /></button>
                                </dd>
                            </div>
                        </dl>
                    @else
                        <p class="text-[13px] text-muted">Not provisioned yet.</p>
                    @endif
                    @error('database') <p class="error mt-2">{{ $message }}</p> @enderror
                </x-layer-card>
            @endif

            {{-- Environment --}}
            <x-layer-card title="Environment" helper="{{ $website->isStatic() ? 'Written to the site\'s .env file. Build tools read it while the site builds.' : 'Written to the site\'s .env file on save.' }}" flush x-show="tab === 'environment'" x-cloak>
                <x-slot:actions>
                    <button class="btn btn-primary" type="submit" form="website-environment">Save</button>
                </x-slot:actions>
                <form id="website-environment" wire:submit="saveEnvironment">
                    <textarea class="textarea textarea-flush" wire:model="env_content" spellcheck="false" aria-label=".env contents"></textarea>
                    @error('env_content') <p class="error px-4 pb-3">{{ $message }}</p> @enderror
                </form>
            </x-layer-card>

            {{-- Runtime --}}
            <x-layer-card title="Runtime" helper="{{ $website->isPhp() ? 'PHP version, web root, and Reverb websockets.' : ($website->isNode() ? 'Node.js version, port, and the install, build, and start commands.' : ($website->usesNode() ? 'Node.js version and how the site is built and which folder is served.' : 'Which folder nginx serves.')) }}" x-show="tab === 'runtime'" x-cloak>
                <x-slot:actions>
                    <button class="btn" type="button" wire:click="regenerate" wire:confirm="Replace nginx{{ $website->hasProcessFile() ? ' and the process file' : '' }} with the template?">Regenerate files</button>
                    <button class="btn btn-primary" type="submit" form="website-runtime">Save</button>
                </x-slot:actions>
                <form id="website-runtime" class="flex flex-col gap-5" wire:submit="saveRuntime">
                    <p class="text-[12.5px] text-muted">
                        <span class="font-medium text-ink">{{ $website->typeLabel() }}</span>
                        · {{ $website->isPhp() ? 'nginx → PHP-FPM' : ($website->isNode() ? 'nginx → PM2' : 'nginx serves static files') }}
                    </p>

                    @if ($website->isPhp())
                        <div class="field">
                            <span>PHP version</span>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($phpVersions as $version)
                                    <button class="choice {{ $php_version === $version ? 'active' : '' }}" type="button" wire:click="$set('php_version', '{{ $version }}')">{{ $version }}</button>
                                @endforeach
                            </div>
                            @error('php_version') <small class="error">{{ $message }}</small> @enderror
                        </div>
                        @if ($website->isLaravel())
                            <label class="flex items-center gap-2.5 text-[13.5px]">
                                <input type="checkbox" wire:model="enable_reverb">
                                <span>Reverb on port {{ $website->reverb_port ?: 'assigned when enabled' }}</span>
                            </label>
                        @else
                            <label class="field max-w-md">
                                <span>Web root folder</span>
                                <input class="input font-mono" wire:model="public_dir" placeholder="public">
                                <small class="text-muted">Inside the repository. Use <code>.</code> for the repository root. Regenerate nginx to apply.</small>
                                @error('public_dir') <small class="error">{{ $message }}</small> @enderror
                            </label>
                        @endif
                    @endif

                    @if ($website->usesNode())
                        <div class="field">
                            <span>Node.js version</span>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($nodeVersions as $version)
                                    <button class="choice {{ $node_version === $version ? 'active' : '' }}" type="button" wire:click="$set('node_version', '{{ $version }}')">{{ $version }}</button>
                                @endforeach
                            </div>
                            @error('node_version') <small class="error">{{ $message }}</small> @enderror
                        </div>
                    @endif

                    @if ($website->isNode())
                        <div class="field max-w-md">
                            <label class="label" for="website-pm2-port">PM2 port</label>
                            <div class="flex gap-2">
                                <input class="input min-w-0 flex-1" id="website-pm2-port" type="number" wire:model="port">
                                @include('livewire.websites.partials.ports-modal', ['model' => 'port'])
                            </div>
                            <small class="text-muted">The app listens here (it gets it as <code>PORT</code>); nginx proxies the domain to it. Regenerate the files to apply a change.</small>
                            @error('port') <small class="error">{{ $message }}</small> @enderror
                        </div>
                    @endif

                    @if ($website->usesNode())
                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="field">
                                <span>Install command</span>
                                <input class="input font-mono" wire:model="install_command" placeholder="auto: from your lockfile">
                                @error('install_command') <small class="error">{{ $message }}</small> @enderror
                            </label>
                            <label class="field">
                                <span>Build command</span>
                                <input class="input font-mono" wire:model="build_command" placeholder="{{ $website->definition()['build'] ?? 'none' }}">
                                @error('build_command') <small class="error">{{ $message }}</small> @enderror
                            </label>
                            @if ($website->isNode() && ! $website->isNextjs())
                                <label class="field md:col-span-2">
                                    <span>Start command</span>
                                    <input class="input font-mono" wire:model="start_command" placeholder="{{ $website->definition()['start'] ?? 'npm start' }}">
                                    <small class="text-muted">PM2 runs this from the site folder. Regenerate the files to apply a change, then deploy.</small>
                                    @error('start_command') <small class="error">{{ $message }}</small> @enderror
                                </label>
                            @endif
                        </div>
                    @endif

                    @if ($website->isStatic())
                        <label class="field max-w-md">
                            <span>{{ $website->type === 'static' ? 'Folder to serve' : 'Output folder' }}</span>
                            <input class="input font-mono" wire:model="public_dir" placeholder="{{ $website->type === 'static' ? '. (the repository root)' : 'auto: '.implode(', ', $website->definition()['output'] ?? ['dist']) }}">
                            <small class="text-muted">{{ $website->type === 'static' ? 'A folder inside the repository. Regenerate nginx to apply a change.' : 'Where the build writes its files. Leave empty to detect it after each build.' }}</small>
                            @error('public_dir') <small class="error">{{ $message }}</small> @enderror
                        </label>
                    @endif
                </form>
            </x-layer-card>

            {{-- Nginx --}}
            <x-layer-card title="Nginx" helper="The site file. Saving tests and reloads nginx." flush x-show="tab === 'nginx'" x-cloak>
                <x-slot:actions>
                    <button class="btn btn-primary" type="submit" form="website-nginx">Save and apply</button>
                </x-slot:actions>
                <form id="website-nginx" wire:submit="saveNginx">
                    <textarea class="textarea textarea-flush" wire:model="nginx_config" spellcheck="false" aria-label="nginx site file"></textarea>
                    @error('nginx_config') <p class="error px-4 pb-3">{{ $message }}</p> @enderror
                </form>
            </x-layer-card>

            {{-- Redirects --}}
            <form x-show="tab === 'redirects'" x-cloak wire:submit="saveRedirects">
                <x-layer-card title="Redirects" helper="Send a domain, or a page, somewhere else. Saving rewrites nginx and adds redirecting domains to the tunnel and DNS.">
                    <x-slot:actions>
                        <button class="btn" type="button" wire:click="addRedirect('path')">Add page redirect</button>
                        <button class="btn" type="button" wire:click="addRedirect('domain')">Add domain redirect</button>
                        <button class="btn btn-primary" type="submit">Save and apply</button>
                    </x-slot:actions>
                    @error('redirects') <p class="error mb-3">{{ $message }}</p> @enderror
                    <div class="flex flex-col gap-3">
                        @forelse ($redirect_rows as $index => $row)
                            <div class="rounded-lg border border-line bg-recessed p-3" wire:key="redirect-{{ $index }}">
                                <div class="grid gap-2 sm:grid-cols-[1fr_auto_1fr]">
                                    <input class="input font-mono text-[13px]" type="text" wire:model="redirect_rows.{{ $index }}.from" spellcheck="false" placeholder="apply.coupons  or  /old-page" aria-label="Redirect from">
                                    <span class="hidden items-center text-muted sm:flex">→</span>
                                    <input class="input font-mono text-[13px]" type="text" wire:model="redirect_rows.{{ $index }}.to" spellcheck="false" placeholder="{{ str_starts_with((string) ($row['from'] ?? ''), '/') ? '/new-page  or  https://example.com/page' : ($website->domain ? 'blank = https://'.$website->domain : 'https://example.com') }}" aria-label="Redirect to">
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-3 text-[12.5px]">
                                    <select class="select h-8 w-auto" wire:model="redirect_rows.{{ $index }}.code" aria-label="Redirect type">
                                        @foreach (\App\Support\Redirects::CODES as $code => $label)
                                            <option value="{{ $code }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @if (str_starts_with((string) ($row['from'] ?? ''), '/'))
                                        <select class="select h-8 w-auto" wire:model="redirect_rows.{{ $index }}.match" aria-label="Match">
                                            <option value="exact">Only this exact page</option>
                                            <option value="prefix">This folder and everything under it</option>
                                        </select>
                                    @else
                                        <select class="select h-8 w-auto" wire:model="redirect_rows.{{ $index }}.via" aria-label="How the domain reaches this server">
                                            @foreach (\App\Support\Redirects::VIA as $via => $label)
                                                <option value="{{ $via }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="redirect_rows.{{ $index }}.keep_path"> Keep the path and query (example.com/a → target/a)</label>
                                    @endif
                                    <button class="btn btn-ghost ml-auto" type="button" wire:click="removeRedirect({{ $index }})"><x-menu-icon name="trash" class="size-4" /> Remove</button>
                                </div>
                                @error('redirects.'.$index) <p class="error mt-2">{{ $message }}</p> @enderror
                            </div>
                        @empty
                            <p class="text-[13px] text-muted">No redirects yet. A domain redirect sends another domain (like <code>apply.coupons</code> or <code>www.apply.coupons</code>) to this site or any URL; a page redirect sends <code>/old-page</code> to a new page.</p>
                        @endforelse
                    </div>
                </x-layer-card>
            </form>

            {{-- Security --}}
            <form class="flex flex-col gap-6" wire:submit="saveSecurity" x-show="tab === 'security'" x-cloak>
                @error('security') <div class="callout"><x-menu-icon name="danger" class="size-4 shrink-0 text-warning" /><p class="min-w-0 flex-1">{{ $message }}</p></div> @enderror

                <x-layer-card title="Search engines" helper="Keep this site out of Google and other search results, for staging sites, admin panels, and APIs.">
                    <x-slot:actions>
                        <button class="btn btn-primary" type="submit">Save and apply</button>
                    </x-slot:actions>
                    <div class="flex flex-col gap-3">
                        <label class="flex items-start justify-between gap-4 rounded-lg border border-line bg-recessed px-3 py-2.5">
                            <span>
                                <span class="flex items-center gap-2 text-[13.5px] font-medium"><x-menu-icon name="eye" class="size-4 text-muted" /> Hide from search engines</span>
                                <span class="mt-0.5 block text-[12px] text-muted">Sends <code>X-Robots-Tag: noindex, nofollow</code> on every response and serves a <code>robots.txt</code> that disallows everything (unless your nginx config already serves its own).</span>
                            </span>
                            <input class="mt-1" type="checkbox" wire:model="hide_from_search">
                        </label>
                        <label class="flex items-start justify-between gap-4 rounded-lg border border-line bg-recessed px-3 py-2.5">
                            <span>
                                <span class="flex items-center gap-2 text-[13.5px] font-medium"><x-menu-icon name="robot" class="size-4 text-muted" /> Refuse known crawlers</span>
                                <span class="mt-0.5 block text-[12px] text-muted">Answers 403 to Googlebot, Bingbot, Yandex, Baidu, SEO tools (Ahrefs, Semrush), and AI crawlers (GPTBot, ClaudeBot, CCBot, Bytespider…). Link previews in Telegram, WhatsApp, and Facebook keep working.</span>
                            </span>
                            <input class="mt-1" type="checkbox" wire:model="block_crawlers">
                        </label>
                    </div>
                </x-layer-card>

                <x-layer-card title="Basic auth" helper="Ask for a username and password before anyone can open the site.">
                    <x-slot:actions>
                        <label class="flex items-center gap-2 text-[13px] font-medium text-ink">
                            <input type="checkbox" wire:model.live="basic_auth_enabled"> Require a password
                        </label>
                    </x-slot:actions>
                    <div @class(['grid gap-4 md:grid-cols-2 transition-opacity', 'opacity-50' => ! $basic_auth_enabled])>
                        <label class="field">
                            <span>Username</span>
                            <input class="input font-mono" wire:model="basic_auth_user" autocomplete="off" placeholder="team" @disabled(! $basic_auth_enabled)>
                            @error('basic_auth_user') <small class="error">{{ $message }}</small> @enderror
                        </label>
                        <x-secret-input
                            label="Password"
                            model="basic_auth_password"
                            secret="basic_auth_password"
                            :saved="(bool) $website->basic_auth_password"
                            :placeholder="$website->basic_auth_hash ? null : 'At least 8 characters'"
                            :disabled="! $basic_auth_enabled"
                        />
                        <label class="field md:col-span-2">
                            <span>Paths without a password <span class="font-normal text-muted">(optional, one per line)</span></span>
                            <textarea class="textarea font-mono" style="min-height: 6.5rem" wire:model="basic_auth_except" placeholder="/api/&#10;/hooks/&#10;/socket.io/" spellcheck="false" @disabled(! $basic_auth_enabled)></textarea>
                            <small class="text-muted">Prefix match: <code>/api/</code> also opens <code>/api/v1/orders</code>. Add webhook, callback, and API paths that servers call without a browser. <code>/.well-known/acme-challenge/</code> is always open for SSL renewals.</small>
                            @error('basic_auth_except') <small class="error">{{ $message }}</small> @enderror
                        </label>
                    </div>
                    @if ($website->basic_auth_enabled && $website->basic_auth_hash)
                        <p class="mt-4 flex items-center gap-2 text-[12.5px] text-muted">
                            <span class="status-badge" style="--status-color: var(--color-success)">Active</span>
                            Visitors are asked to sign in as <code>{{ $website->basic_auth_user }}</code>.
                        </p>
                    @endif
                </x-layer-card>

                <x-layer-card title="Blocked IPs" helper="Visitors from these addresses get 403. Works behind a Cloudflare tunnel too. Block attackers in one click from Monitoring → Security.">
                    <label class="field">
                        <span>IPs and ranges <span class="font-normal text-muted">(one per line)</span></span>
                        <textarea class="textarea font-mono" style="min-height: 6.5rem" wire:model="blocked_ips" placeholder="203.0.113.7&#10;198.51.100.0/24&#10;2001:db8::/32" spellcheck="false"></textarea>
                        <small class="text-muted">IPv4 or IPv6, optionally with a CIDR range from /8.</small>
                        @error('blocked_ips') <small class="error">{{ $message }}</small> @enderror
                    </label>
                </x-layer-card>
            </form>

            {{-- Processes --}}
            <x-layer-card title="Processes" helper="{{ $website->isLaravel() ? 'Supervisor programs for queues and schedulers.' : 'The PM2 ecosystem file.' }}" flush x-show="tab === 'processes'" x-cloak>
                <x-slot:actions>
                    <button class="btn btn-primary" type="submit" form="website-processes">Save and apply</button>
                </x-slot:actions>
                <form id="website-processes" wire:submit="saveProcess">
                    <textarea class="textarea textarea-flush" wire:model="process_config" spellcheck="false" aria-label="{{ $website->isLaravel() ? 'Supervisor programs' : 'PM2 ecosystem file' }}"></textarea>
                    @error('process_config') <p class="error px-4 pb-3">{{ $message }}</p> @enderror
                </form>
            </x-layer-card>

            {{-- Deployments --}}
            <div class="flex flex-col gap-6" x-show="tab === 'deployments'" x-cloak>
                <x-layer-card title="Deployments" helper="The last 20 builds. Click a row to read its log." flush>
                    <x-slot:actions>
                        @if (count($selectedDeployments) > 0)
                            <x-confirm-delete
                                title="Delete selected deployments?"
                                name="delete"
                                action="deleteDeployments"
                                :button="'Delete '.count($selectedDeployments)"
                                :actions="['The selected deployment records will be permanently deleted.']"
                            />
                        @endif
                        <button class="btn btn-primary" type="button" wire:click="deploy" @disabled($deploying)><x-menu-icon name="rocket" /> Deploy now</button>
                    </x-slot:actions>
                    @error('deployments') <p class="error px-4 pt-3">{{ $message }}</p> @enderror
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th class="w-10">
                                        <input type="checkbox" aria-label="Select all deployments" @checked($deployments->isNotEmpty() && count($selectedDeployments) === $deployments->count()) wire:click="toggleDeploymentSelection">
                                    </th>
                                    <th>Status</th>
                                    <th>Commit</th>
                                    <th>Trigger</th>
                                    <th>Duration</th>
                                    <th>Started</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($deployments as $deployment)
                                    <tr
                                        wire:key="deployment-{{ $deployment->id }}"
                                        @class(['deploy-row', 'is-current' => $selectedDeployment?->id === $deployment->id])
                                        wire:click="$set('deploymentId', {{ $deployment->id }})"
                                    >
                                        <td @click.stop><input type="checkbox" aria-label="Select deployment {{ $deployment->id }}" wire:model.live="selectedDeployments" value="{{ $deployment->id }}"></td>
                                        <td><x-status-badge :status="$deployment->status" /></td>
                                        <td class="max-w-[26rem]">
                                            <div class="flex min-w-0 items-center gap-2">
                                                @if ($deployment->commit_sha)
                                                    <code class="shrink-0">{{ substr($deployment->commit_sha, 0, 7) }}</code>
                                                @endif
                                                <span class="truncate" title="{{ $deployment->commit_message }}">{{ $deployment->commit_message ? strtok($deployment->commit_message, "\n") : ($deployment->commit_sha ? '' : '-') }}</span>
                                            </div>
                                            @if ($deployment->commit_author || $deployment->branch)
                                                <p class="truncate text-[12px] text-muted">{{ collect([$deployment->commit_author, $deployment->branch ? 'on '.$deployment->branch : null])->filter()->implode(' ') }}</p>
                                            @endif
                                        </td>
                                        <td class="capitalize text-muted">{{ $deployment->trigger }}</td>
                                        <td class="tabular-nums text-muted">{{ $deployment->durationLabel() ?? '-' }}</td>
                                        <td class="whitespace-nowrap text-muted" title="@datetime($deployment->created_at)">@ago($deployment->created_at)</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="table-empty">No deployments yet. Deploy the site to see its build log here.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-layer-card>

                @if ($selectedDeployment)
                    @php
                        $isLive = in_array($selectedDeployment->status, ['queued', 'running'], true);
                    @endphp
                    <section
                        class="logs-viewer"
                        wire:key="deployment-log-{{ $selectedDeployment->id }}"
                        x-data="{ fullscreen: false, consoleRunning: @js($isLive) }"
                        :class="{ 'is-fullscreen': fullscreen }"
                        @keydown.escape.window="fullscreen = false"
                        @console-state.window="consoleRunning = $event.detail.running; $nextTick(() => window.dispatchEvent(new Event('resize')))"
                    >
                        <div class="logs-viewer-toolbar">
                            <div class="logs-viewer-primary">
                                <div class="logs-viewer-actions">
                                    <button class="logs-viewer-btn" type="button" @click="fullscreen = !fullscreen; $nextTick(() => window.dispatchEvent(new Event('resize')))" :title="fullscreen ? 'Exit fullscreen (Esc)' : 'Fullscreen'">
                                        <x-menu-icon name="expand" x-show="!fullscreen" />
                                        <x-menu-icon name="shrink" x-show="fullscreen" x-cloak />
                                    </button>
                                    <button class="logs-viewer-btn" type="button" @click="$dispatch('console-copy')" title="Copy logs"><x-menu-icon name="copy" /></button>
                                    <button class="logs-viewer-btn" type="button" @click="$dispatch('console-download')" title="Download logs"><x-menu-icon name="download" /></button>
                                </div>
                                <x-status-badge :status="$selectedDeployment->status" />
                                <span class="logs-viewer-meta hidden md:flex">
                                    <span class="capitalize">{{ $selectedDeployment->trigger }}</span>
                                    @if ($selectedDeployment->commit_sha)
                                        <code>{{ substr($selectedDeployment->commit_sha, 0, 7) }}</code>
                                    @endif
                                    <span title="@datetime($selectedDeployment->created_at)">@ago($selectedDeployment->created_at)</span>
                                    @if ($selectedDeployment->durationLabel())
                                        <span class="tabular-nums">{{ $isLive ? 'running ' : 'took ' }}{{ $selectedDeployment->durationLabel() }}</span>
                                    @endif
                                </span>
                            </div>
                            <div class="logs-viewer-end">
                                <span class="flex items-center gap-1.5 text-[12px] text-muted" x-cloak x-show="consoleRunning">
                                    <span class="loader-spinner" style="width: 11px; height: 11px; border-width: 2px"></span> Live
                                </span>
                            </div>
                        </div>
                        <div
                            class="live-terminal live-terminal-tall min-h-0 flex-1"
                            wire:ignore
                            x-data="liveTerminal({ mode: 'log', url: @js(route('deployments.log', $selectedDeployment)), name: @js(\Illuminate\Support\Str::slug($website->name).'-deployment-'.$selectedDeployment->id) })"
                            @console-copy.window="copy()"
                            @console-download.window="download()"
                        >
                            <div class="live-terminal-screen" x-ref="screen"></div>
                        </div>
                    </section>
                @endif

                <x-layer-card title="Continuous deployment" helper="Every push to {{ $website->branch }} on GitHub deploys this site.">
                    <x-slot:actions>
                        <label class="flex items-center gap-2 text-[13px] font-medium text-ink">
                            <input type="checkbox" @checked($website->auto_deploy) wire:click="toggleAutoDeploy">
                            Auto deploy on push
                        </label>
                    </x-slot:actions>

                    @php
                        $viaApp = $deployApp !== null && $deployApp->isConnected();
                    @endphp

                    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-line bg-recessed px-3 py-2.5 text-[13px]">
                        <span class="status-badge" style="--status-color: {{ ! $website->auto_deploy ? 'var(--color-muted)' : ($website->last_webhook_at || $viaApp ? 'var(--color-success)' : 'var(--color-warning)') }}">
                            {{ ! $website->auto_deploy ? 'Paused' : ($viaApp ? 'Automatic' : ($website->last_webhook_at ? 'Connected' : 'Waiting for GitHub')) }}
                        </span>
                        @if ($website->last_webhook_at)
                            <span class="min-w-0 flex-1">{{ $website->last_webhook_result }}</span>
                            <span class="text-[12px] text-muted" title="@datetime($website->last_webhook_at)">@ago($website->last_webhook_at)</span>
                        @elseif ($viaApp)
                            <span class="min-w-0 flex-1 text-muted">Ready. Push to <code>{{ $website->branch }}</code> and it deploys; the first push shows up here.</span>
                        @else
                            <span class="min-w-0 flex-1 text-muted">No delivery yet. Add the webhook below in GitHub; its first ping shows up here.</span>
                        @endif
                    </div>

                    @if ($viaApp)
                        <x-callout type="success" title="Set up by the GitHub App {{ $deployApp->name }}">
                            GitHub sends every push to the App, and the App's webhook deploys this site. Nothing to add in the repository. If pushes do not arrive, check that the App is installed on this repository (GitHub → Settings → Applications → {{ $deployApp->name }} → Repository access).
                        </x-callout>
                    @endif

                    <div @if ($viaApp) x-data="{ manual: false }" @endif>
                    @if ($viaApp)
                        <button class="mt-3 text-[12.5px] text-muted hover:text-ink" type="button" @click="manual = ! manual">
                            <x-menu-icon name="chevron-right" class="inline size-3.5 transition-transform" ::class="manual && 'rotate-90'" /> Use a repository webhook instead
                        </button>
                    @endif
                    <div @if ($viaApp) class="mt-3" x-show="manual" x-cloak @endif>
                    <dl class="flex flex-col gap-3 text-[13px]">
                        <div class="flex flex-col gap-1.5 sm:flex-row sm:items-center">
                            <dt class="w-28 shrink-0 text-muted">Payload URL</dt>
                            <dd class="flex min-w-0 flex-1 items-center gap-2">
                                <code class="min-w-0 flex-1 truncate">{{ $webhookUrl }}</code>
                                <button class="icon-btn" type="button" title="Copy" @click="copyToClipboard(@js($webhookUrl), 'Webhook URL copied.')"><x-menu-icon name="copy" class="size-4" /></button>
                            </dd>
                        </div>
                        <div class="flex flex-col gap-1.5 sm:flex-row sm:items-center">
                            <dt class="w-28 shrink-0 text-muted">Secret</dt>
                            <dd class="flex min-w-0 flex-1 items-center gap-2" x-data="secretText('webhook_secret')">
                                <code class="min-w-0 flex-1 truncate" x-text="shown ? value : '••••••••••••••••••••'"></code>
                                <button class="icon-btn" type="button" :title="shown ? 'Hide' : 'Show (asks for your authenticator code)'" @click="toggle()">
                                    <x-menu-icon name="eye" class="size-4" x-show="!shown" />
                                    <x-menu-icon name="eye-off" class="size-4" x-cloak x-show="shown" />
                                </button>
                                <button class="icon-btn" type="button" title="Copy" @click="copy()"><x-menu-icon name="copy" class="size-4" /></button>
                            </dd>
                        </div>
                    </dl>
                    <ol class="mt-4 flex list-decimal flex-col gap-1.5 border-t border-line pt-4 pl-4 text-[12.5px] text-muted">
                        <li>In GitHub open the repository → <b class="text-ink-dim">Settings → Webhooks → Add webhook</b>.</li>
                        <li>Paste the payload URL, set content type to <code>application/json</code>, and paste the secret.</li>
                        <li>Choose <b class="text-ink-dim">Just the push event</b> and save. GitHub sends a ping, which appears above.</li>
                        <li>Push to <code>{{ $website->branch }}</code>: the site pulls, installs, migrates, and caches, and Telegram tells you how it went.</li>
                    </ol>
                    @unless ($viaApp)
                        <p class="mt-3 text-[12px] text-muted">Sites connected through a GitHub App deploy from the App's webhook instead and need no setup here.</p>
                    @endunless
                    </div>
                    </div>
                </x-layer-card>
            </div>

            <section x-show="tab === 'monitoring'" x-cloak>
                <livewire:websites.monitoring :website="$website" :key="'site-monitoring-'.$website->id" />
            </section>

            <section x-show="tab === 'logs'" x-cloak>
                <livewire:websites.logs :website="$website" :key="'site-logs-'.$website->id" />
            </section>

            <section x-show="tab === 'health'" x-cloak>
                <livewire:websites.health :website="$website" :key="'site-health-'.$website->id" />
            </section>

            <section x-show="tab === 'terminal'" x-cloak>
                <livewire:websites.terminal :website="$website" :key="'site-terminal-'.$website->id" />
            </section>

            <section x-show="tab === 'files'" x-cloak>
                <livewire:websites.files :website="$website" :key="'site-files-'.$website->id" />
            </section>

            {{-- Danger zone --}}
            <x-layer-card title="Danger zone" helper="Turning off can be undone; deleting cannot." x-show="tab === 'danger'" x-cloak>
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-line pb-4 mb-4">
                    <div class="min-w-0">
                        <p class="font-medium">{{ $website->enabled ? 'Turn off this website' : 'Turn on this website' }}</p>
                        <p class="text-[13px] text-muted">
                            @if ($website->enabled)
                                Its address shows a turned-off page, its workers stop, and deploys and pushes wait. Files, database, and settings stay as they are.
                            @else
                                It is turned off. Turning it on serves it again and starts its workers, with the code it had.
                            @endif
                        </p>
                    </div>
                    @if ($website->enabled)
                        <button class="btn" type="button" wire:click="setEnabled(false)" wire:confirm="Turn off {{ $website->name }}?" wire:loading.attr="disabled" wire:target="setEnabled"><x-menu-icon name="pause" /> Turn off</button>
                    @else
                        <button class="btn btn-primary" type="button" wire:click="setEnabled(true)" wire:loading.attr="disabled" wire:target="setEnabled"><x-menu-icon name="play" /> Turn on</button>
                    @endif
                </div>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="min-w-0">
                        <p class="font-medium">Delete this website</p>
                        <p class="text-[13px] text-muted">Removes {{ $website->name }}, its nginx site, and its process file. The Git repository is left in place.</p>
                    </div>
                    <x-confirm-delete
                        title="Confirm website deletion?"
                        :name="$website->name"
                        action="destroy"
                        :actions="['This website, its nginx site, and its process file will be permanently deleted.']"
                    />
                </div>
            </x-layer-card>
        </div>
    </div>
</div>
