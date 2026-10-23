<div>
    @if (! $githubApp->isConnected() && ! $manual)
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-2xl font-semibold">{{ $githubApp->name }}</h2>
                <p class="text-muted">Finish registering this GitHub App before using it as a source.</p>
            </div>
            <x-confirm-delete
                title="Confirm GitHub App deletion?"
                :name="$githubApp->name"
                action="delete"
                :actions="['This GitHub App source will be permanently deleted. The app on GitHub is not uninstalled.']"
            />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="card flex flex-col gap-4 p-4">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-medium">Automated installation</h3>
                    <span class="rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] text-emerald-300">Recommended</span>
                </div>
                <label class="field">
                    <span>Instance endpoint</span>
                    <input class="input" wire:model.live="webhook_endpoint" placeholder="https://panel.example.com">
                    <small class="text-muted">Public https address of this panel, with no path. GitHub rejects 127.0.0.1.</small>
                    <small class="error" x-data x-show="$wire.webhook_endpoint.includes('127.0.0.1') || $wire.webhook_endpoint.includes('localhost')">This address is only on your computer. Add a Cloudflare published hostname first, then paste that https URL here.</small>
                </label>
                <div class="rounded-lg border border-line p-3 text-[12px]">
                    <p class="font-medium">Permissions the App asks for</p>
                    <p class="text-muted">Contents: read · Metadata: read · Email: read, and push events, so every push deploys the sites that use this App.</p>
                </div>
                <button class="btn btn-primary mt-auto w-full" type="button" x-data x-on:click="registerGithubApp($wire)">Register with GitHub</button>
            </section>

            <section class="card flex flex-col gap-4 p-4">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-medium">Manual installation</h3>
                    <span class="rounded-full bg-white/10 px-2 py-0.5 text-[11px]">Advanced</span>
                </div>
                <p class="text-muted">Use this when the GitHub manifest flow is unavailable, or when you already created the App and want to paste the App ID, installation ID, and private key.</p>
                <button class="btn mt-auto w-fit" type="button" wire:click="createGithubAppManually">Continue manually</button>
            </section>
        </div>

        <script>
            function registerGithubApp(wire) {
                const endpoint = (wire.get('webhook_endpoint') || '').replace(/\/+$/, '');
                if (!endpoint) {
                    return;
                }

                const organization = @js($githubApp->organization);
                const htmlUrl = @js($githubApp->html_url);
                const name = @js($githubApp->name);
                const slug = @js($githubApp->slug);
                const path = organization
                    ? 'organizations/' + encodeURIComponent(organization) + '/settings/apps/new'
                    : 'settings/apps/new';
                const permissions = { contents: 'write', administration: 'write', metadata: 'read', emails: 'read' };
                const events = ['push'];

                const form = document.createElement('form');
                form.method = 'post';
                form.action = htmlUrl + '/' + path + '?state=' + encodeURIComponent(slug);
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'manifest';
                input.value = JSON.stringify({
                    name,
                    url: endpoint,
                    hook_attributes: { url: endpoint + '/hooks/github-app/' + slug, active: true },
                    redirect_url: endpoint + '/hooks/github-app/' + slug + '/callback',
                    setup_url: endpoint + '/hooks/github-app/' + slug + '/install',
                    setup_on_update: true,
                    public: false,
                    request_oauth_on_install: false,
                    default_permissions: permissions,
                    default_events: events,
                });
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        </script>
    @else
        <div class="grid gap-4 xl:grid-cols-[180px_minmax(0,1fr)]">
            <aside class="submenu flex flex-col gap-1">
                <p class="nav-section">Settings</p>
                <span class="menu-item menu-item-active"><x-menu-icon name="settings" /> General</span>
            </aside>

            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-muted">{{ $githubApp->isConnected() ? 'Connected' : 'Manual setup' }}{{ $githubApp->organization ? ' · '.$githubApp->organization : '' }}</p>
                    @if ($githubApp->isConnected())
                        <button class="btn" type="button" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                            <span wire:loading.remove wire:target="testConnection">Test connection</span>
                            <span wire:loading wire:target="testConnection">Testing…</span>
                        </button>
                    @endif
                </div>
                @if ($notice !== '')
                    <p class="{{ $noticeOk ? 'status' : 'error' }}">{{ $notice }}</p>
                @endif

                <form class="card grid gap-4 p-4 lg:grid-cols-2" wire:submit="save">
                    @include('livewire.sources.partials.github-fields')
                    <div class="lg:col-span-2">
                        <button class="btn btn-primary" type="submit">Save</button>
                    </div>
                </form>

                <section class="card flex flex-col gap-2 p-4">
                    <h2 class="font-medium">Webhook</h2>
                    <p class="text-muted">GitHub sends push events here when this App is the source.</p>
                    <code>{{ $webhookUrl }}</code>
                </section>

                <section class="card flex items-center justify-between gap-3 p-4">
                    <div>
                        <h2 class="font-medium text-danger">Danger zone</h2>
                        <p class="text-muted">Remove this source from Laravelify. The GitHub App stays installed until you remove it on GitHub.</p>
                    </div>
                    <x-confirm-delete
                        title="Confirm GitHub App deletion?"
                        :name="$githubApp->name"
                        action="delete"
                        :actions="['This GitHub App source will be permanently deleted. The app on GitHub is not uninstalled.']"
                    />
                </section>
            </div>
        </div>
    @endif
</div>
