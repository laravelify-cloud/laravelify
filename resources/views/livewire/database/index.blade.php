<div class="flex flex-col gap-6">
    <x-page-header title="Database" summary="Browse every database on this server: MySQL, PostgreSQL, SQLite and MongoDB. Search, edit rows, and undo a MySQL change later.">
        <x-slot:actions>
            <a class="btn" href="{{ route('backups.databases') }}" wire:navigate><x-menu-icon name="backup" /> Backups</a>
            <button class="btn" type="button" wire:click="openNewDatabase"><x-menu-icon name="database" /> New database</button>
            <button class="btn btn-primary" type="button" wire:click="openConnection"><x-menu-icon name="plus" /> Connect a database</button>
        </x-slot:actions>
    </x-page-header>

    <x-modal-frame open="$wire.newDatabaseOpen" close="$wire.set('newDatabaseOpen', false)">
        @if ($newDatabaseOpen)
            @php $servers = app(\App\Services\EngineDatabases::class)->databaseServers(); @endphp
            <form class="flex flex-col gap-4" wire:submit="createDatabase">
                <div class="modal-head">
                    <h2 class="flex items-center gap-2"><x-menu-icon name="database" class="size-5 text-muted" /> New database</h2>
                    <button class="icon-btn" type="button" wire:click="$set('newDatabaseOpen', false)" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>
                @if ($servers === [])
                    <p class="text-[13px] text-muted">There is no server to make it on. Install MySQL or PostgreSQL on this server, the MySQL or PostgreSQL plugin, or connect a MySQL or PostgreSQL database first.</p>
                @else
                    <label class="field">
                        <span>Server</span>
                        <select class="select" wire:model="newDatabaseServer">
                            @foreach ($servers as $value => $serverLabel)
                                <option value="{{ $value }}">{{ $serverLabel }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">On a connection's server, it uses the same login and shows here as its own connection.</small>
                    </label>
                    <label class="field">
                        <span>Name</span>
                        <input class="input font-mono" wire:model="newDatabaseName" placeholder="shop" autocomplete="off" spellcheck="false" autofocus maxlength="63">
                        @error('newDatabaseName') <small class="error">{{ $message }}</small> @else <small class="text-muted">Letters, digits and underscores. UTF-8 (utf8mb4 on MySQL).</small> @enderror
                    </label>
                @endif
                <div class="modal-foot flex justify-end gap-2">
                    <button class="btn" type="button" wire:click="$set('newDatabaseOpen', false)">Cancel</button>
                    @if ($servers !== [])
                        <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="createDatabase"><x-menu-icon name="plus" /> Create database</button>
                    @endif
                </div>
            </form>
        @endif
    </x-modal-frame>

    @include('livewire.database.partials.export')

    <div class="segmented self-start" role="tablist">
        <button type="button" wire:click="$set('tab', 'databases')" aria-pressed="{{ $tab === 'databases' ? 'true' : 'false' }}">Databases</button>
        <button type="button" wire:click="$set('tab', 'sql')" aria-pressed="{{ $tab === 'sql' ? 'true' : 'false' }}">SQL</button>
        <button type="button" wire:click="$set('tab', 'history')" aria-pressed="{{ $tab === 'history' ? 'true' : 'false' }}">SQL history</button>
        <button type="button" wire:click="$set('tab', 'search')" aria-pressed="{{ $tab === 'search' ? 'true' : 'false' }}">Search everywhere</button>
        <button type="button" wire:click="$set('tab', 'changes')" aria-pressed="{{ $tab === 'changes' ? 'true' : 'false' }}">Changes</button>
        <button type="button" wire:click="$set('tab', 'encryption')" aria-pressed="{{ $tab === 'encryption' ? 'true' : 'false' }}">Encryption</button>
    </div>

    @if ($error)
        <div class="callout"><x-menu-icon name="danger" class="size-4 shrink-0 text-warning" /><p class="min-w-0 flex-1">Could not reach MySQL: {{ $error }}</p></div>
    @endif
    @foreach ($engineErrors as $failedEngine => $engineError)
        <div class="callout"><x-menu-icon name="danger" class="size-4 shrink-0 text-warning" /><p class="min-w-0 flex-1">Could not read {{ \App\Support\DatabaseEngines::label($failedEngine) }}: {{ $engineError }}</p></div>
    @endforeach

    @if ($tab === 'databases')
        <x-layer-card title="Databases" helper="{{ $databaseCount }} databases · {{ \Illuminate\Support\Number::fileSize($totalSize, precision: 1) }} in all. Sizes are each engine's own figures and can be a little behind." flush>
            @php
                $databaseRows = collect($databases)->map(function (array $database): array {
                    $engine = $database['engine'];
                    $name = $database['name'];
                    $open = $engine === 'mysql' ? route('database.show', $name) : route('database.engine', [$engine, $name]);
                    $actions = [['label' => 'Open', 'href' => $open]];

                    if ($engine === 'remote') {
                        $actions[] = ['label' => 'Settings', 'call' => ['openConnection', $name]];
                    } elseif ($engine === 'mysql') {
                        $actions[] = ['label' => 'Export SQL', 'call' => ['openExport', $name]];
                        $actions[] = ['label' => 'Back up', 'call' => ['backup', $name], 'confirm' => 'Back up '.$name.' now to your backup storage?'];
                    } elseif (array_key_exists($engine, \App\Services\EngineDatabases::DUMP_EXTENSIONS)) {
                        $actions[] = ['label' => 'Export', 'call' => ['exportEngine', $engine, $name]];
                        $actions[] = ['label' => 'Back up', 'call' => ['backup', \App\Services\EngineDatabases::backupTarget($engine, $name)], 'confirm' => 'Back up '.$name.' now to your backup storage?'];
                    }

                    return [
                        'Database' => $database['title'] ?? $name,
                        'Engine' => $database['label'] ?? \App\Support\DatabaseEngines::label($engine),
                        'Tables' => $database['tables'] ?? null,
                        'Size' => ($database['size'] ?? 0) > 0 ? $database['size'] : null,
                        '__href' => $open,
                        '__engine' => $engine,
                        '__actions' => $actions,
                    ];
                })->values()->all();
            @endphp
            @include('livewire.database.partials.local-grid', ['spec' => [
                'key' => 'databases',
                'search' => 'Search databases',
                'empty' => 'No databases.',
                'actions' => true,
                'actionsWidth' => 290,
                'columns' => [
                    ['name' => 'Database', 'link' => true, 'width' => 300],
                    ['name' => 'Engine', 'badge' => true, 'width' => 170],
                    ['name' => 'Tables', 'numeric' => true, 'width' => 120],
                    ['name' => 'Size', 'numeric' => true, 'format' => 'bytes', 'width' => 130],
                ],
                'rows' => $databaseRows,
            ]])
        </x-layer-card>
    @elseif ($tab === 'encryption')
        @include('livewire.database.partials.crypto')
    @elseif ($tab === 'sql')
        @include('livewire.database.partials.sql', ['sqlTarget' => $sqlIn, 'sqlChoices' => $allDatabases])
    @elseif ($tab === 'history')
        @include('livewire.database.partials.sql-history', ['historyChoices' => $allDatabases])
    @elseif ($tab === 'search')
        @include('livewire.database.partials.search', ['scopeChoices' => $allDatabases])
    @else
        <x-layer-card title="Changes" helper="Rows added, edited, and deleted here, and triggers, events, procedures, and functions changed here, newest first. Undo puts back only that row or object; when someone changed the row since, you are asked first." flush>
            @include('livewire.database.partials.changes', ['changes' => $changes])
        </x-layer-card>
    @endif
    <x-modal-frame class="modal-wide" open="$wire.connectionOpen" close="$wire.set('connectionOpen', false)">
        @if ($connectionOpen)
            @php
                $drivers = \App\Models\DatabaseConnection::DRIVERS;
                $driver = $connection['driver'];
                $isSqlsrv = $driver === 'sqlsrv';
                $isFile = $driver === 'sqlite';
                $extension = $drivers[$driver]['extension'];
                $panelPhp = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
                $glyphs = ['sqlsrv' => 'MS', 'mysql' => 'My', 'pgsql' => 'Pg', 'sqlite' => 'Lite'];
                $tabs = $isFile ? ['general' => 'General', 'options' => 'Options'] : ['general' => 'General', 'options' => 'Options', 'ssl' => $isSqlsrv ? 'Encryption' : 'SSL'];
                $activeTab = isset($tabs[$connectionTab]) ? $connectionTab : 'general';
            @endphp
            <form class="conn" wire:submit="saveConnection">
                <div class="modal-head">
                    <div class="min-w-0">
                        <p class="text-[12px] text-muted">{{ $connectionId ? 'Connection' : 'New connection' }}</p>
                        <h2 class="mt-1 flex items-center gap-2 truncate">
                            @if ($connection['color'])
                                <span class="conn-dot" style="background: {{ \App\Models\DatabaseConnection::COLORS[$connection['color']] ?? 'transparent' }}"></span>
                            @endif
                            {{ $connection['name'] !== '' ? $connection['name'] : 'Connect a database' }}
                        </h2>
                    </div>
                    <button class="icon-btn" type="button" wire:click="$set('connectionOpen', false)" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>

                @if ($pickerOpen)
                    @php
                        $listing = $this->pickerListing();
                    @endphp
                    <div class="conn-picker">
                        <div class="conn-picker-head">
                            <button class="btn btn-sm" type="button" wire:click="$set('pickerOpen', false)"><x-menu-icon name="up" class="size-4" /> Back</button>
                            <nav class="conn-crumbs" aria-label="Folder">
                                <button type="button" wire:click="pickerGo('')">Files</button>
                                @foreach ($listing['crumbs'] as $crumb)
                                    <span>/</span><button type="button" wire:click="pickerGo(@js($crumb['path']))">{{ $crumb['name'] }}</button>
                                @endforeach
                            </nav>
                        </div>
                        <div class="conn-picker-list">
                            @if ($listing['error'])
                                <p class="error p-3">{{ $listing['error'] }}</p>
                            @endif
                            @if ($listing['path'] !== '')
                                <button type="button" class="conn-pick" wire:click="pickerGo(@js(dirname('/'.$listing['path']) === '/' ? '' : ltrim(dirname('/'.$listing['path']), '/')))"><x-menu-icon name="files" class="size-4 text-muted" /> ..</button>
                            @endif
                            @foreach ($listing['folders'] as $folder)
                                <button type="button" class="conn-pick" wire:key="pf-{{ $folder }}" wire:click="pickerGo(@js(ltrim($listing['path'].'/'.$folder, '/')))"><x-menu-icon name="files" class="size-4 text-warning" /> {{ $folder }}</button>
                            @endforeach
                            @foreach ($listing['files'] as $file)
                                <button type="button" class="conn-pick is-file" wire:key="pfile-{{ $file['name'] }}" wire:click="pickFile(@js(ltrim($listing['path'].'/'.$file['name'], '/')))">
                                    <x-menu-icon name="database" class="size-4 text-accent" /> <span class="truncate">{{ $file['name'] }}</span>
                                    <small>{{ \Illuminate\Support\Number::fileSize($file['size'], precision: 1) }}</small>
                                </button>
                            @endforeach
                            @if (! $listing['error'] && $listing['folders'] === [] && $listing['files'] === [])
                                <p class="p-4 text-[13px] text-muted">No folders or SQLite files here.</p>
                            @endif
                        </div>
                        <p class="conn-hint">Shows folders and .sqlite, .sqlite3, .db and .db3 files of the file manager. Click a file to use it.</p>
                    </div>
                @else
                    <div class="conn-drivers" role="radiogroup" aria-label="Database type">
                        @foreach ($drivers as $key => $info)
                            <button type="button" role="radio" aria-checked="{{ $driver === $key ? 'true' : 'false' }}" class="conn-driver" data-driver="{{ $key }}" wire:click="pickDriver('{{ $key }}')">
                                <span class="conn-glyph">{{ $glyphs[$key] }}</span>
                                <span class="min-w-0">
                                    <b>{{ $info['short'] }}</b>
                                    <small>{{ $key === 'sqlite' ? 'a file' : ($key === 'mysql' ? 'and MariaDB' : 'port '.$info['port']) }}</small>
                                </span>
                            </button>
                        @endforeach
                    </div>

                    <div class="conn-tabs" role="tablist">
                        @foreach ($tabs as $key => $tabLabel)
                            <button type="button" role="tab" aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}" wire:click="$set('connectionTab', '{{ $key }}')">{{ $tabLabel }}</button>
                        @endforeach
                    </div>

                    <div class="conn-body">
                        @if ($activeTab === 'general')
                            @unless (extension_loaded($extension))
                                <div class="callout">
                                    <x-menu-icon name="danger" class="size-4 shrink-0 text-warning" />
                                    <div class="grid min-w-0 flex-1 gap-2">
                                        <p>The panel's PHP {{ $panelPhp }} has no <code>{{ $extension }}</code>, so it cannot open {{ $drivers[$driver]['short'] }} yet.</p>
                                        <div><button class="btn btn-sm btn-primary" type="button" wire:click="installDriver"><x-menu-icon name="download" class="size-4" /> Install {{ $isSqlsrv ? 'sqlsrv and pdo_sqlsrv' : $extension }}</button>
                                            @if ($isSqlsrv)<small class="ml-2 text-muted">With Microsoft ODBC Driver 18 (accepts Microsoft's license). A few minutes, in the background.</small>@endif
                                        </div>
                                    </div>
                                </div>
                            @endunless

                            <div class="conn-form">
                                <label for="conn-name">Name</label>
                                <input id="conn-name" class="input" wire:model.live.debounce.500ms="connection.name" placeholder="{{ $isFile ? 'Shop (SQLite)' : 'ERP database' }}">

                                @if ($isFile)
                                    <label for="conn-file">File</label>
                                    <div class="conn-row">
                                        <input id="conn-file" class="input font-mono" wire:model.live.debounce.500ms="connection.database" placeholder="/var/www/shop/database/database.sqlite" spellcheck="false">
                                        <button class="btn" type="button" wire:click="openFilePicker"><x-menu-icon name="files" class="size-4" /> Browse…</button>
                                    </div>
                                    <span></span>
                                    <small class="conn-hint -mt-2">A file in the file manager. It is opened as the user that owns it, so a website's own database stays its own.</small>
                                @else
                                    <span>Connection</span>
                                    <div class="conn-segment" role="radiogroup">
                                        <button type="button" aria-pressed="{{ $connectionMode === 'default' ? 'true' : 'false' }}" wire:click="$set('connectionMode', 'default')">Fields</button>
                                        <button type="button" aria-pressed="{{ $connectionMode === 'string' ? 'true' : 'false' }}" wire:click="$set('connectionMode', 'string')">Paste a connection string</button>
                                    </div>

                                    @if ($connectionMode === 'string')
                                        <label for="conn-string">String</label>
                                        <div class="grid gap-2">
                                            <textarea id="conn-string" class="textarea min-h-28 font-mono text-[12.5px]" wire:model="connectionString" spellcheck="false" placeholder="{{ $isSqlsrv ? 'Data Source=.\SQLEXPRESS;Initial Catalog=erp;User ID=sa;Password=…;Encrypt=True;TrustServerCertificate=True' : ($driver === 'mysql' ? 'mysql://user:password@db.example.com:3306/shop' : 'postgresql://user:password@db.example.com:5432/app?sslmode=require') }}"></textarea>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <button class="btn btn-sm btn-primary" type="button" wire:click="applyConnectionString">Fill the fields</button>
                                                <small class="text-muted">ADO.NET (SQL Server Management Studio, appsettings.json), JDBC (DataGrip) or a URL.</small>
                                            </div>
                                            @error('connectionString') <small class="error">{{ $message }}</small> @enderror
                                        </div>
                                    @else
                                        <label for="conn-host">Host</label>
                                        <div class="conn-row">
                                            <input id="conn-host" class="input font-mono" wire:model.live.debounce.400ms="connection.host" placeholder="192.168.1.20 or sql.example.com" spellcheck="false">
                                            <label class="conn-inline" for="conn-port">Port</label>
                                            <input id="conn-port" class="input conn-port font-mono" wire:model.live.debounce.400ms="connection.port" inputmode="numeric" placeholder="{{ $isSqlsrv && $connection['instance'] !== '' ? 'auto' : $drivers[$driver]['port'] }}">
                                        </div>

                                        @if ($isSqlsrv)
                                            <label for="conn-instance">Instance</label>
                                            <div>
                                                <input id="conn-instance" class="input font-mono" wire:model.live.debounce.400ms="connection.instance" placeholder="Empty for the default instance, or SQLEXPRESS" spellcheck="false">
                                                <small class="conn-hint">A named instance with no port is found by SQL Server Browser (UDP 1434).</small>
                                            </div>
                                        @endif

                                        <label for="conn-user">User</label>
                                        <div class="conn-row">
                                            <input id="conn-user" class="input font-mono" wire:model.live.debounce.400ms="connection.username" autocomplete="off" spellcheck="false" placeholder="{{ $isSqlsrv ? 'sa' : ($driver === 'mysql' ? 'root' : 'postgres') }}">
                                            <label class="conn-inline" for="conn-password">Password</label>
                                            <div class="conn-secret" x-data="{ shown: false }">
                                                <input id="conn-password" class="input font-mono" :type="shown ? 'text' : 'password'" wire:model="connection.password" autocomplete="new-password" placeholder="{{ $connectionId ? 'unchanged' : '' }}">
                                                <button type="button" class="conn-eye" @click="shown = ! shown" :aria-label="shown ? 'Hide password' : 'Show password'"><x-menu-icon name="eye" class="size-4" /></button>
                                            </div>
                                        </div>
                                        @if ($connectionIntegrated)
                                            <span></span>
                                            <small class="conn-hint -mt-2 text-warning">The pasted string signs in with Windows. A Linux server cannot: use a SQL Server login (the server needs SQL Server and Windows Authentication mode).</small>
                                        @endif

                                        <label for="conn-database">Database</label>
                                        <input id="conn-database" class="input font-mono" wire:model.live.debounce.400ms="connection.database" spellcheck="false" placeholder="{{ $isSqlsrv ? 'master' : 'app' }}">
                                    @endif
                                @endif

                                @if ($isFile || $connectionMode === 'default')
                                    <span>URL</span>
                                    <div class="conn-row" x-data="{ copied: false }">
                                        <input class="input font-mono text-muted" value="{{ $this->connectionUrl() }}" readonly aria-label="URL" x-ref="url">
                                        <button class="btn" type="button" @click="navigator.clipboard.writeText($refs.url.value); copied = true; setTimeout(() => copied = false, 1500)"><span x-text="copied ? 'Copied' : 'Copy'">Copy</span></button>
                                    </div>
                                @endif
                            </div>
                        @elseif ($activeTab === 'options')
                            <div class="conn-options">
                                <label class="conn-option">
                                    <input type="checkbox" wire:model="connection.read_only">
                                    <span><b>Read-only</b><small>Rows cannot be changed or deleted here, and the SQL tab runs only SELECT, WITH, SHOW and EXPLAIN. Good for a production database.</small></span>
                                </label>
                                @unless ($isFile)
                                    <div class="conn-option">
                                        <span class="conn-option-icon"><x-menu-icon name="clock" class="size-4" /></span>
                                        <span><b>Timeout</b><small>Seconds to wait for the server before giving up.</small></span>
                                        <input class="input conn-port font-mono" wire:model="connection.timeout" inputmode="numeric" aria-label="Timeout in seconds">
                                    </div>
                                @endunless
                                <div class="conn-option">
                                    <span class="conn-option-icon"><x-menu-icon name="tune" class="size-4" /></span>
                                    <span><b>Color</b><small>A dot next to the name in the list, to tell connections apart.</small></span>
                                    <div class="conn-colors" role="radiogroup" aria-label="Color">
                                        <button type="button" class="conn-color is-none {{ $connection['color'] === '' ? 'is-picked' : '' }}" wire:click="$set('connection.color', '')" title="No color" aria-label="No color"></button>
                                        @foreach (\App\Models\DatabaseConnection::COLORS as $colorName => $hex)
                                            <button type="button" class="conn-color {{ $connection['color'] === $colorName ? 'is-picked' : '' }}" style="background: {{ $hex }}" wire:click="$set('connection.color', '{{ $colorName }}')" title="{{ ucfirst($colorName) }}" aria-label="{{ ucfirst($colorName) }}"></button>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="conn-options">
                                @if ($isSqlsrv)
                                    <label class="conn-option">
                                        <input type="checkbox" wire:model="connection.encrypt">
                                        <span><b>Encrypt the connection</b><small>TLS between this server and SQL Server. Azure SQL needs it.</small></span>
                                    </label>
                                    <label class="conn-option">
                                        <input type="checkbox" wire:model="connection.trust_certificate">
                                        <span><b>Trust the server's certificate</b><small>TrustServerCertificate: for a SQL Server with its own self-signed certificate (most local ones).</small></span>
                                    </label>
                                @else
                                    <div class="conn-option">
                                        <span class="conn-option-icon"><x-menu-icon name="lock" class="size-4" /></span>
                                        <span><b>SSL</b><small>Encrypt the connection to the database server.</small></span>
                                        <select class="input w-64" wire:model="connection.ssl_mode" aria-label="SSL">
                                            @foreach (\App\Models\DatabaseConnection::SSL_MODES as $mode => $modeLabel)
                                                <option value="{{ $mode }}">{{ $modeLabel }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                            </div>
                        @endif

                        @foreach (['name', 'driver', 'host', 'port', 'instance', 'database', 'username', 'password', 'ssl_mode', 'timeout', 'color'] as $field)
                            @error('connection.'.$field) <p class="error">{{ $message }}</p> @enderror
                        @endforeach
                    </div>

                    @if ($connectionTest)
                        <div class="conn-result {{ $connectionTest['ok'] ? 'is-ok' : 'is-bad' }}" role="status">
                            <x-menu-icon :name="$connectionTest['ok'] ? 'check' : 'danger'" class="size-4 shrink-0" />
                            <span>{{ $connectionTest['message'] }}</span>
                        </div>
                    @endif

                    <div class="conn-foot">
                        <button class="btn" type="button" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                            <x-menu-icon name="bolt" class="size-4" />
                            <span wire:loading.remove wire:target="testConnection">Test connection</span>
                            <span wire:loading wire:target="testConnection">Connecting…</span>
                        </button>
                        @if ($connectionId)
                            <button class="btn btn-danger" type="button" wire:click="deleteConnection" wire:confirm="Remove this connection from the panel? The database itself is not touched."><x-menu-icon name="trash" class="size-4" /> Remove</button>
                        @endif
                        <span class="flex-1"></span>
                        <button class="btn" type="button" wire:click="$set('connectionOpen', false)">Cancel</button>
                        <button class="btn" type="button" wire:click="saveConnection(false)">Apply</button>
                        <button class="btn btn-primary" type="submit">OK</button>
                    </div>
                @endif
            </form>
        @endif
    </x-modal-frame>
</div>
