<div class="env-strip">
    <span class="env-strip-label"><x-menu-icon name="git" class="size-3.5" /> Environments</span>
    <div class="env-tabs" role="tablist">
        @foreach ($family as $site)
            @php
                $latest = $site->deployments->first();
            @endphp
            <a
                href="{{ route('websites.show', $site) }}"
                wire:navigate
                role="tab"
                @class(['env-tab', 'is-active' => $site->is($website)])
                title="{{ $site->name }} · branch {{ $site->branch }}{{ $site->domain ? ' · '.$site->domain : ($site->http_port ? ' · port '.$site->http_port : '') }}"
            >
                <span class="env-dot" style="--status-color: {{ match ($latest?->status) { 'success' => 'var(--color-success)', 'failed', 'cancelled' => 'var(--color-danger)', 'running', 'queued' => 'var(--color-warning)', default => 'var(--color-muted)' } }}"></span>
                <b>{{ $site->environmentName() }}</b>
                @if ($site->branch !== $site->environmentName())
                    <span class="env-branch">{{ $site->branch }}</span>
                @endif
            </a>
        @endforeach
        <button type="button" class="env-tab env-add" wire:click="$set('dialog', true)" title="Add environment"><span>+</span> Add</button>
    </div>

    @if ($dialog)
        {{-- Teleported to <body>, so the backdrop covers the whole window (top bar and menu too). --}}
        <template x-teleport="body">
            <div
                class="env-layer"
                x-data="{ branches: [], branchError: null, loading: true, async init() { const result = await $wire.branches(); this.branches = result.branches; this.branchError = result.error; this.loading = false; this.$nextTick(() => this.$refs.name?.focus()); } }"
                @keydown.escape.window="$wire.set('dialog', false)"
            >
                <div class="env-layer-backdrop" wire:click="$set('dialog', false)"></div>
                <div class="env-modal" role="dialog" aria-modal="true" aria-labelledby="env-modal-title">
                    <header class="env-modal-head">
                        <div class="min-w-0">
                            <h3 id="env-modal-title">Add an environment</h3>
                            <p>A website of its own (folder, address, .env{{ $main->provision_database ? ', database' : '' }}) for another branch of <b>{{ \Illuminate\Support\Str::of((string) $main->repository)->after('github.com/')->replaceEnd('.git', '') }}</b>, listed next to {{ $main->environmentName() }}. It starts with the settings and .env of {{ $main->name }}.</p>
                        </div>
                        <button type="button" class="env-modal-close" wire:click="$set('dialog', false)" aria-label="Close"><x-menu-icon name="close" class="size-4" /></button>
                    </header>

                    <section class="env-modal-body">
                        <div class="env-field">
                            <label for="env-name">Name</label>
                            <div class="env-presets">
                                @foreach (['development', 'staging', 'preview', 'testing'] as $suggestion)
                                    <button type="button" @class(['env-preset', 'is-active' => $environment === $suggestion]) wire:click="$set('environment', '{{ $suggestion }}')">{{ $suggestion }}</button>
                                @endforeach
                            </div>
                            <input id="env-name" x-ref="name" class="input" wire:model.live.debounce.300ms="environment" placeholder="development">
                            @error('environment') <small class="error">{{ $message }}</small> @enderror
                        </div>

                        <div class="env-field">
                            <label>Branch</label>
                            <div class="segmented self-start" role="group" aria-label="Branch">
                                <button type="button" aria-pressed="{{ $branchMode === 'new' ? 'true' : 'false' }}" wire:click="$set('branchMode', 'new')">New branch</button>
                                <button type="button" aria-pressed="{{ $branchMode === 'existing' ? 'true' : 'false' }}" wire:click="$set('branchMode', 'existing')">Existing branch</button>
                            </div>

                            @if ($branchMode === 'new')
                                <div class="env-row">
                                    <input class="input min-w-0 flex-1" wire:model="branch" placeholder="{{ $environment ?: 'development' }}">
                                    <span class="env-from">from</span>
                                    <select class="input env-select" wire:model="from">
                                        <option value="">{{ $main->branch }} (production)</option>
                                        <template x-for="name in branches.filter((name) => name !== @js($main->branch))" :key="name">
                                            <option :value="name" x-text="name"></option>
                                        </template>
                                    </select>
                                </div>
                                <small class="env-hint">Made on GitHub from the latest commit of the branch you pick. Empty name: <b>{{ $environment ?: 'development' }}</b>.</small>
                            @else
                                <select class="input" wire:model="branch" x-show="! branchError">
                                    <option value="">Choose a branch</option>
                                    <template x-for="name in branches" :key="name">
                                        <option :value="name" x-text="name"></option>
                                    </template>
                                </select>
                                <input class="input" wire:model="branch" x-show="branchError" x-cloak placeholder="feature/new-header">
                            @endif
                            <small class="env-hint" x-show="loading">Reading branches from GitHub…</small>
                            <small class="env-hint" x-show="branchError" x-cloak x-text="branchError"></small>
                            @error('branch') <small class="error">{{ $message }}</small> @enderror
                        </div>

                        <div class="env-field">
                            <label>Address</label>
                            <div class="segmented self-start" role="group" aria-label="Address">
                                <button type="button" aria-pressed="{{ $access === 'port' ? 'true' : 'false' }}" wire:click="$set('access', 'port')">HTTP port</button>
                                <button type="button" aria-pressed="{{ $access === 'domain' ? 'true' : 'false' }}" wire:click="$set('access', 'domain')">Domain</button>
                            </div>

                            @if ($access === 'port')
                                <div class="env-row">
                                    <input class="input min-w-0 flex-1" wire:model="httpPort" inputmode="numeric" placeholder="Empty: the next free port">
                                    <button class="btn" type="button" wire:click="randomPort"><x-menu-icon name="refresh" class="size-4" /> Random free port</button>
                                </div>
                            @else
                                <input class="input" wire:model="domain" placeholder="{{ ($environment ?: 'dev').'.'.($main->domain ?: 'example.com') }}">
                                @if ($zones !== [])
                                    <div class="env-zones">
                                        <span class="env-hint">Random free subdomain on</span>
                                        @foreach ($zones as $zone)
                                            <button type="button" class="env-zone" wire:click="randomDomain(@js($zone))" wire:loading.attr="disabled" wire:target="randomDomain">
                                                <x-menu-icon name="refresh" class="size-3.5" /> {{ $zone }}
                                            </button>
                                        @endforeach
                                    </div>
                                    <small class="env-hint">The tunnel route and DNS record are added for you{{ $main->cloudflare_tunnel_id ? '' : ' when a Cloudflare tunnel is chosen in General' }}.</small>
                                @else
                                    <small class="env-hint">Save a Cloudflare API token (Settings → Cloudflare) to get random free subdomains here.</small>
                                @endif
                            @endif
                            @error('domain') <small class="error">{{ $message }}</small> @enderror
                            @error('http_port') <small class="error">{{ $message }}</small> @enderror
                        </div>

                        @unless ($main->provision_database)
                            <p class="env-warning"><x-menu-icon name="danger" class="size-4 shrink-0" /> {{ $main->name }} has no database made by the panel, so the copied .env may point at the production database. Check DB_* in the new environment's .env before it runs migrations.</p>
                        @endunless
                    </section>

                    <footer class="env-modal-foot">
                        <button type="button" class="btn" wire:click="$set('dialog', false)">Cancel</button>
                        <button type="button" class="btn btn-primary" wire:click="create" wire:loading.attr="disabled" wire:target="create">
                            <span wire:loading.remove wire:target="create">Create and deploy</span>
                            <span wire:loading wire:target="create">Creating…</span>
                        </button>
                    </footer>
                </div>
            </div>
        </template>
    @endif
</div>
