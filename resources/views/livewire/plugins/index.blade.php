<div class="flex flex-col gap-6">
    <x-page-header title="Plugins" summary="Services for your apps in one click, run with Docker on this server: databases, cache, storage, secrets, error tracking, and an editor. Ports answer on 127.0.0.1 only; link a plugin to a website to give it the variables." />

    <x-task-tray :tasks="$tasks" :active="$tasksActive" />

    <div class="plugin-grid">
        @foreach ($definitions as $key => $definition)
            @php
                $plugin = $installed->get($key);
                $isBusy = in_array($key, $busy, true);
                $status = $isBusy ? 'installing' : ($plugin?->status ?? 'not installed');
                $color = match ($status) {
                    'running' => 'var(--color-success)',
                    'error' => 'var(--color-danger)',
                    'installing' => 'var(--color-warning)',
                    default => 'var(--color-muted)',
                };
            @endphp

            @php
                $variables = $definition->linkable() ? array_keys($definition->websiteEnv($definition->ports(), $definition->credentials())) : [];
                $backupLabel = match ($definition->backupMethod()) { 'pg_dump' => 'pg_dumpall', 'mysqldump' => 'mysqldump', 'mongodump' => 'mongodump --archive', default => 'a copy of its data volume' };
                $webUrl = $plugin && $definition->webPort() ? ($plugin->domain ? 'https://'.$plugin->domain : 'http://127.0.0.1:'.($plugin->ports[$definition->webPort()] ?? '')) : null;
                $running = $plugin?->status === 'running';
                $menu = [];
                if ($plugin) {
                    if ($plugin->credentials) {
                        $menu[] = ['label' => 'Show credentials', 'icon' => 'keys', 'gate' => "showCredentials('{$key}')"];
                    }
                    $menu[] = ['label' => 'Restart', 'icon' => 'refresh', 'click' => "control('{$key}', 'restart')"];
                    if ($running) {
                        $menu[] = ['label' => 'Stop', 'icon' => 'stop', 'click' => "control('{$key}', 'stop')"];
                    }
                    if ($definition->webPort()) {
                        $menu[] = ['label' => $plugin->domain ? 'Change '.($definition->apiPort() ? 'console ' : '').'domain' : 'Open '.($definition->apiPort() ? 'the console ' : '').'on a domain', 'icon' => 'globe', 'click' => "editDomain('{$key}')"];
                    }
                    if ($definition->apiPort()) {
                        $menu[] = ['label' => $plugin->api_domain ? 'Change S3 API domain' : 'S3 API on a domain', 'icon' => 's3', 'click' => "editDomain('{$key}', 'api')"];
                    }
                    if ($definition->sealable()) {
                        $menu[] = ['label' => $plugin->auto_unseal ? 'Turn off auto-unseal' : 'Turn on auto-unseal', 'icon' => 'lock', 'click' => "setAutoUnseal('{$key}', ".($plugin->auto_unseal ? 'false' : 'true').')', 'confirm' => $plugin->auto_unseal ? null : 'The unseal keys are then on this same server (encrypted with the panel\'s key), so anyone who gets the server and the panel gets the secrets too. Turn it on?'];
                    }
                    $menu[] = ['label' => 'Uninstall', 'icon' => 'trash', 'event' => 'uninstall-plugin-'.$key, 'danger' => true];
                }
            @endphp

            <section @class(['card plugin-card', 'is-installed' => $plugin]) wire:key="plugin-{{ $key }}">
                <header class="plugin-head">
                    <span class="plugin-logo" style="--plugin-color: {{ $definition->color() }}">{{ $definition->logo() }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5">
                            <h2 class="truncate font-semibold">{{ $definition->label() }}</h2>
                            <div class="plugin-info" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                                <button class="icon-btn plugin-info-btn" type="button" @click="open = ! open" @keydown.escape="open = false" :aria-expanded="open" aria-label="About {{ $definition->label() }}"><x-menu-icon name="info" class="size-4" /></button>
                                <div class="plugin-info-pop" x-cloak x-show="open" x-transition.opacity.duration.120ms @click.outside="open = false" role="tooltip">
                                    <p>{{ $definition->description() }}</p>
                                    <dl>
                                        <div><dt>Ports</dt><dd>{{ collect($definition->ports())->map(fn ($port, $name) => ucfirst($name).' '.$port)->join(', ') }} (127.0.0.1 only)</dd></div>
                                        <div><dt>Backup</dt><dd>{{ ucfirst($backupLabel) }}</dd></div>
                                        <div><dt>Websites get</dt><dd>{{ $variables === [] ? 'No variables: open its page' : implode(', ', $variables) }}</dd></div>
                                        <div><dt>Folder</dt><dd class="font-mono">/opt/laravelify-plugins/{{ $key }}</dd></div>
                                    </dl>
                                </div>
                            </div>
                        </div>
                        <p class="plugin-desc">{{ $definition->description() }}</p>
                    </div>
                    <span @class(['status-badge', 'is-live' => $status === 'installing']) style="--status-color: {{ $color }}">{{ ucfirst($status) }}</span>
                </header>

                <div class="plugin-body">
                    @if ($plugin)
                        <dl class="plugin-facts">
                            @foreach ($plugin->ports as $name => $port)
                                @php
                                    // A port on a domain shows the domain (its link), with the local port under it.
                                    $portDomain = $name === $definition->webPort() ? $plugin->domain : ($name === $definition->apiPort() ? $plugin->api_domain : null);
                                @endphp
                                <div>
                                    <dt>{{ ucfirst($name) }}</dt>
                                    @if ($portDomain)
                                        <dd class="plugin-port-domain">
                                            <span class="copy-inline">
                                                <a class="truncate text-accent hover:underline" href="https://{{ $portDomain }}" target="_blank" rel="noopener" title="{{ $name === $definition->apiPort() ? 'Public: its access keys decide who can write' : 'Behind basic auth ('.$plugin->basic_auth_user.')' }}">{{ $portDomain }}</a>
                                                <x-copy-button :text="\Illuminate\Support\Js::from('https://'.$portDomain)->toHtml()" />
                                            </span>
                                            <small class="font-mono text-muted">127.0.0.1:{{ $port }}</small>
                                        </dd>
                                    @else
                                        <dd class="copy-inline font-mono">127.0.0.1:{{ $port }} <x-copy-button :text="\Illuminate\Support\Js::from('127.0.0.1:'.$port)->toHtml()" /></dd>
                                    @endif
                                </div>
                            @endforeach
                            @if ($definition->sealable())
                                <div>
                                    <dt>Seal</dt>
                                    <dd>
                                        @if ($plugin->sealed === true)
                                            <span class="text-danger">Sealed</span>
                                        @elseif ($plugin->sealed === false)
                                            <span class="text-success">Unsealed</span>
                                        @else
                                            <span class="text-muted">Unknown</span>
                                        @endif
                                        <span class="text-muted">· auto-unseal {{ $plugin->auto_unseal ? 'on' : 'off' }}</span>
                                    </dd>
                                </div>
                            @endif
                        </dl>

                        @if ($plugin->status === 'error' && $plugin->last_error)
                            <p class="task-note is-danger">{{ \Illuminate\Support\Str::limit($plugin->last_error, 300) }}</p>
                        @endif

                        @if ($definition->linkable())
                            <div class="plugin-links">
                                <p class="plugin-label">Linked websites</p>
                                @foreach ($plugin->websites as $website)
                                    <span class="plugin-link" wire:key="link-{{ $key }}-{{ $website->id }}">
                                        {{ $website->name }}
                                        <button class="icon-btn" type="button" wire:click="unlink('{{ $key }}', {{ $website->id }})" title="Unlink" aria-label="Unlink {{ $website->name }}"><x-menu-icon name="close" class="size-3.5" /></button>
                                    </span>
                                @endforeach
                                <div class="plugin-link-form">
                                    <select class="select flex-1" wire:model="linkWebsite.{{ $key }}" aria-label="Website to link">
                                        <option value="">{{ $plugin->websites->isEmpty() ? 'Link a website…' : 'Link another website…' }}</option>
                                        @foreach ($websites->whereNotIn('id', $plugin->websites->modelKeys()) as $website)
                                            <option value="{{ $website->id }}">{{ $website->name }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-sm" type="button" wire:click="link('{{ $key }}')">Link</button>
                                </div>
                            </div>
                        @else
                            <p class="plugin-note">Websites don't link to {{ $definition->label() }}. Open its page{{ $plugin->domain ? '' : ', or put it on a domain behind a password from the menu' }}.</p>
                        @endif
                    @else
                        <ul class="plugin-preview">
                            <li><x-menu-icon name="lock" class="size-4" /> Ports on 127.0.0.1 only, passwords made and stored encrypted</li>
                            <li><x-menu-icon name="backup" class="size-4" /> Backs up with {{ $backupLabel }}</li>
                            <li><x-menu-icon name="link" class="size-4" /> {{ $variables === [] ? 'Opens on its own page or a domain' : 'Link a website to give it '.implode(', ', array_slice($variables, 0, 3)).(count($variables) > 3 ? '…' : '') }}</li>
                        </ul>
                    @endif
                </div>

                <footer class="plugin-actions">
                    @if (! $plugin || ($plugin->status === 'error' && ! $isBusy))
                        <button class="btn btn-primary btn-sm" type="button" wire:click="install('{{ $key }}')" @disabled($isBusy)>
                            <x-menu-icon name="plus" /> {{ $plugin ? 'Install again' : 'Install' }}
                        </button>
                    @endif
                    @if ($plugin)
                        @if ($plugin->status === 'stopped')
                            <button class="btn btn-primary btn-sm" type="button" wire:click="control('{{ $key }}', 'start')" @disabled($isBusy)><x-menu-icon name="play" /> Start</button>
                        @elseif ($definition->sealable() && $plugin->sealed !== false && $running)
                            <button class="btn btn-primary btn-sm" type="button" wire:click="unseal('{{ $key }}')"><x-menu-icon name="lock" /> Unseal</button>
                        @elseif ($webUrl && $running)
                            <a class="btn btn-primary btn-sm" href="{{ $webUrl }}" target="_blank" rel="noopener"><x-menu-icon name="external" /> Open</a>
                        @endif
                        <button class="btn btn-sm" type="button" wire:click="showLogs('{{ $key }}')" @disabled($isBusy)><x-menu-icon name="logs" /> Logs</button>
                        <button class="btn btn-sm" type="button" wire:click="backup('{{ $key }}')" @disabled($isBusy) title="Saves {{ $backupLabel }} to your backup storage"><x-menu-icon name="backup" /> Back up</button>

                        <div class="ml-auto">
                            @include('livewire.plugins.partials.menu', ['items' => $menu, 'disabled' => $isBusy])
                        </div>

                        <x-confirm-delete
                            event="uninstall-plugin-{{ $key }}"
                            button="Uninstall"
                            :title="'Uninstall '.$definition->label().'?'"
                            :name="$key"
                            :action="'uninstall(\''.$key.'\', true)'"
                            :actions="['Its containers stop and are removed.', 'Its data (volumes) is deleted.', 'Linked websites lose its variables.']"
                        />
                    @endif
                </footer>
            </section>
        @endforeach

        {{-- GlitchTip is the panel's built-in error tracking, not a Docker plugin: one per server, joined to every website. --}}
        @php
            $gtInstalled = $glitchtip->installed();
            $gtStatus = $glitchtipBusy ? 'installing' : ($gtInstalled ? 'running' : 'not installed');
        @endphp
        <section @class(['card plugin-card', 'is-installed' => $gtInstalled]) wire:key="plugin-glitchtip">
            <header class="plugin-head">
                <span class="plugin-logo" style="--plugin-color: #7b38a5">GT</span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1.5">
                        <h2 class="truncate font-semibold">GlitchTip</h2>
                        <div class="plugin-info" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                            <button class="icon-btn plugin-info-btn" type="button" @click="open = ! open" :aria-expanded="open" aria-label="About GlitchTip"><x-menu-icon name="info" class="size-4" /></button>
                            <div class="plugin-info-pop" x-cloak x-show="open" x-transition.opacity.duration.120ms @click.outside="open = false" role="tooltip">
                                <p>Error tracking that works with any Sentry SDK. It is the panel's own GlitchTip: every website gets a project and its key (DSN) in .env, and errors show in Monitoring and each website's GlitchTip tab.</p>
                                <dl>
                                    <div><dt>Address</dt><dd>{{ $glitchtip->url() }}</dd></div>
                                    <div><dt>Sign-in</dt><dd>Through the panel, no second password</dd></div>
                                    <div><dt>Memory</dt><dd>About 250 MB of RAM</dd></div>
                                </dl>
                            </div>
                        </div>
                    </div>
                    <p class="plugin-desc">Error tracking for every website, built into the panel. Works with any Sentry SDK.</p>
                </div>
                <span @class(['status-badge', 'is-live' => $glitchtipBusy]) style="--status-color: {{ $gtInstalled ? 'var(--color-success)' : ($glitchtipBusy ? 'var(--color-warning)' : 'var(--color-muted)') }}">{{ ucfirst($gtStatus) }}</span>
            </header>

            <div class="plugin-body">
                @if ($gtInstalled)
                    <dl class="plugin-facts">
                        <div><dt>Address</dt><dd class="truncate font-mono">{{ $glitchtip->url() }}</dd></div>
                    </dl>
                    <p class="plugin-note">Every website is connected on its own: its DSN is in .env (SENTRY_LARAVEL_DSN, SENTRY_DSN…) and the next deploy uses it.</p>
                @else
                    <ul class="plugin-preview">
                        <li><x-menu-icon name="bug" class="size-4" /> Errors from every website, grouped, with the stack trace</li>
                        <li><x-menu-icon name="link" class="size-4" /> Each website gets a project and its DSN in .env by itself</li>
                        <li><x-menu-icon name="lock" class="size-4" /> On this server only, signed in through the panel</li>
                    </ul>
                @endif
            </div>

            <footer class="plugin-actions">
                @if ($gtInstalled)
                    <a class="btn btn-primary btn-sm" href="{{ $glitchtip->open() }}" target="_blank" rel="noopener"><x-menu-icon name="external" /> Open</a>
                    <a class="btn btn-sm" href="{{ route('monitoring.index') }}" wire:navigate><x-menu-icon name="bug" /> Errors</a>
                @else
                    <button class="btn btn-primary btn-sm" type="button" wire:click="installGlitchtip" @disabled($glitchtipBusy)><x-menu-icon name="plus" /> {{ $glitchtipBusy ? 'Installing…' : 'Install' }}</button>
                @endif
            </footer>
        </section>
    </div>

    <p class="text-[12px] text-muted">Docker is installed the first time you install a plugin. Each plugin lives in <code>/opt/laravelify-plugins/&lt;name&gt;</code> and starts again on its own after a reboot.</p>

    @if ($credentialsFor && $shownCredentials !== [])
        @php
            $credentialPlugin = $definitions[$credentialsFor];
            $unsealKeys = array_filter($shownCredentials, fn (string $label): bool => str_starts_with($label, 'Unseal key'), ARRAY_FILTER_USE_KEY);
            $mainCredentials = array_diff_key($shownCredentials, $unsealKeys);
        @endphp
        {{-- New credentials: only "I saved them" closes it (not Escape or a click outside), and only after a copy or
             download. Shown again (after an authenticator code): Close, Escape, or a click outside. --}}
        <x-modal-frame class="modal-root-top" open="true" :close="$reviewing ? '$wire.closeCredentials()' : 'null'">
            <div class="flex flex-col gap-4" x-data="{ saved: @js($reviewing), revealAll: false }" wire:key="credentials-{{ $credentialsFor }}-{{ $reviewing ? 'again' : 'new' }}"
                @if ($reviewing) x-init="setTimeout(() => $wire.closeCredentials(), Math.max(0, {{ (int) session('secrets_unlocked_until', 0) * 1000 }} - Date.now()))" @endif>
                <div class="modal-head">
                    <h2 class="flex items-center gap-2">
                        <span class="plugin-logo plugin-logo-sm" style="--plugin-color: {{ $credentialPlugin->color() }}">{{ $credentialPlugin->logo() }}</span>
                        {{ $credentialPlugin->label() }} credentials
                    </h2>
                    <button class="btn btn-sm" type="button" @click="revealAll = ! revealAll">
                        <x-menu-icon name="eye" class="size-4" x-show="! revealAll" />
                        <x-menu-icon name="eye-off" class="size-4" x-show="revealAll" x-cloak />
                        <span x-text="revealAll ? 'Hide all' : 'Show all'">Show all</span>
                    </button>
                </div>

                @if ($reviewing)
                    <x-callout type="info" title="Unlocked with your authenticator code">
                        Shown for up to {{ \App\Livewire\PanelComponent::SECRET_UNLOCK_MINUTES }} minutes after the code; then this page asks for a code again.
                    </x-callout>
                @else
                    <x-callout type="warning" title="Save them now">
                        Save them in your password manager. You can see them again later from the plugin's More menu, after your authenticator code.
                    </x-callout>
                @endif

                <dl class="credential-list">
                    @foreach ($mainCredentials as $label => $value)
                        @include('livewire.plugins.partials.credential', ['label' => $label, 'value' => $value, 'secret' => $label !== 'Address' && ! str_starts_with($label, 'Host')])
                    @endforeach
                </dl>

                @if ($unsealKeys !== [])
                    <section class="credential-group">
                        <header>
                            <h3 class="flex items-center gap-2"><x-menu-icon name="keys" class="size-4 text-muted" /> Unseal keys</h3>
                            <p class="text-[12px] text-muted">Vault seals itself on every restart. Any {{ \App\Plugins\VaultPlugin::KEY_THRESHOLD }} of these {{ count($unsealKeys) }} keys open it again. Keep them apart from the root token.</p>
                        </header>
                        <dl class="credential-list">
                            @foreach ($unsealKeys as $label => $value)
                                @include('livewire.plugins.partials.credential', ['label' => str_replace('Unseal key ', 'Key ', $label), 'value' => $value, 'secret' => true])
                            @endforeach
                        </dl>
                    </section>
                @endif

                <div class="credential-actions">
                    <button class="btn" type="button" x-data="{ copied: false }"
                        @click="navigator.clipboard ? navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($credentialsText) }}).then(() => { copied = true; saved = true; setTimeout(() => copied = false, 1500) }) : window.toast('Copying needs a secure (https) connection. Download the file instead.', 'error')">
                        <x-menu-icon name="copy" class="size-4" x-show="! copied" />
                        <x-menu-icon name="check" class="size-4" style="color: var(--color-success)" x-show="copied" x-cloak />
                        <span x-text="copied ? 'Copied' : 'Copy all'">Copy all</span>
                    </button>
                    <button class="btn" type="button" wire:click="downloadCredentials" @click="saved = true">
                        <x-menu-icon name="download" class="size-4" /> Download .txt
                    </button>
                </div>

                <div class="modal-foot">
                    @if ($reviewing)
                        <button class="btn btn-primary" type="button" wire:click="closeCredentials">Close</button>
                    @else
                        <p class="mr-auto text-[12px] text-muted" x-show="! saved">Copy or download them first.</p>
                        <button class="btn btn-primary" type="button" wire:click="savedCredentials" :disabled="! saved">I saved them</button>
                    @endif
                </div>
            </div>
        </x-modal-frame>
    @endif

    @if ($domainFor)
        <x-modal-frame class="modal-root-top" open="true" close="$wire.closeDomain()">
            <form class="flex flex-col gap-4" wire:submit="saveDomain">
                <div class="modal-head">
                    <h2>{{ $definitions[$domainFor]->label() }}{{ $domainKind === 'api' ? ' S3 API' : '' }} on a domain</h2>
                    <button class="icon-btn" type="button" wire:click="closeDomain" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>
                @if ($domainKind === 'api')
                    <p class="text-[12.5px] text-muted">Opens its S3 API through nginx, <b>without</b> a password: image links and your apps' S3 clients use it, and its own access keys decide who can write. Pick a Cloudflare tunnel for HTTPS.</p>
                @else
                    <p class="text-[12.5px] text-muted">Opens its web page through nginx, behind basic auth. Pick a Cloudflare tunnel for HTTPS; Laravelify adds the route and DNS when an API token is saved.</p>
                @endif
                <label class="field">
                    <span>Domain</span>
                    <input class="input" wire:model="domain" placeholder="{{ $domainKind === 'api' ? 's3' : $domainFor }}.example.com" required>
                    @error('domain') <small class="error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>Cloudflare tunnel</span>
                    <select class="select" wire:model="domainTunnel">
                        <option value="">None (point DNS at this server yourself)</option>
                        @foreach ($tunnels as $tunnel)
                            <option value="{{ $tunnel->id }}">{{ $tunnel->name }}</option>
                        @endforeach
                    </select>
                </label>
                <div @class(['grid gap-3 sm:grid-cols-2', 'hidden' => $domainKind === 'api'])>
                    <label class="field"><span>Basic auth user</span><input class="input" wire:model="domainUser" autocomplete="off" @required($domainKind !== 'api')></label>
                    <label class="field"><span>Password (12+ characters)</span><input class="input" type="password" wire:model="domainPassword" autocomplete="new-password" @required($domainKind !== 'api')></label>
                </div>
                @if ($domainReport !== [])
                    @if ($domainReport['note'] ?? null)
                        <x-callout type="warning">{{ $domainReport['note'] }}</x-callout>
                    @endif
                    @foreach ($domainReport['lines'] ?? [] as $line)
                        <p @class(['text-[12.5px]', 'text-danger' => ! $line['ok']])>{{ $line['host'] }}: route {{ $line['route'] }}, DNS {{ $line['dns'] }}</p>
                    @endforeach
                @endif
                <div class="modal-foot">
                    @if ($domainKind === 'api' ? $installed->get($domainFor)?->api_domain : $installed->get($domainFor)?->domain)
                        <button class="btn btn-danger" type="button" wire:click="removeDomain('{{ $domainFor }}')" wire:confirm="Remove the domain?">Remove domain</button>
                    @endif
                    <button class="btn btn-primary" type="submit">Save</button>
                </div>
            </form>
        </x-modal-frame>
    @endif

    @if ($logsFor)
        <x-modal-frame class="modal-root-top" open="true" close="$wire.closeLogs()">
            <div class="flex flex-col gap-4">
                <div class="modal-head">
                    <h2>{{ $definitions[$logsFor]->label() }} logs</h2>
                    <div class="flex gap-2">
                        <button class="btn btn-sm" type="button" wire:click="showLogs('{{ $logsFor }}')">Refresh</button>
                        <button class="icon-btn" type="button" wire:click="closeLogs" aria-label="Close"><x-menu-icon name="close" /></button>
                    </div>
                </div>
                <pre class="plugin-log">{{ $logs }}</pre>
            </div>
        </x-modal-frame>
    @endif
</div>
