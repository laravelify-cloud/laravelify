<?php

namespace App\Livewire\Database;

use App\Livewire\Concerns\ManagesCryptoMethods;
use App\Livewire\Concerns\ManagesDatabaseChanges;
use App\Livewire\Concerns\RunsSql;
use App\Livewire\PanelComponent;
use App\Models\CryptoMethod;
use App\Models\DatabaseChange;
use App\Models\DatabaseConnection;
use App\Services\BackupManager;
use App\Services\CryptoMethods;
use App\Services\DatabaseBrowser;
use App\Services\EngineDatabases;
use App\Services\PanelTaskRunner;
use App\Services\SitePaths;
use App\Support\DatabaseEngines;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Tools → Database: every database with its size, a search through all of them (data, table
 * names, column names), and the log of changes made here.
 */
class Index extends PanelComponent
{
    use ManagesCryptoMethods;
    use ManagesDatabaseChanges;
    use RunsSql;

    /** The database the SQL tab runs in. */
    #[Url(as: 'db')]
    public string $sqlIn = '';

    #[Url(history: true)]
    public string $tab = 'databases';

    #[Url]
    public string $sort = 'name';

    #[Url(as: 'dir')]
    public string $direction = 'asc';

    public string $filter = '';

    /** Show only one engine's databases ('' for all). */
    #[Url(as: 'engine')]
    public string $only = '';

    public bool $exportOpen = false;

    public bool $exportSingle = false;

    public string $exportDatabase = '';

    /** @var list<string> */
    public array $exportTables = [];

    public string $query = '';

    public string $mode = 'data';

    public string $match = 'contains';

    public string $column = '';

    /** @var list<string> Databases to search; empty means all. */
    public array $scope = [];

    /** @var array{results: list<array<string, mixed>>, searched: int, skipped: list<string>, truncated: bool}|null */
    public ?array $found = null;

    public ?string $searchError = null;

    /** An encryption method to run on the search text first (search for the encrypted value). */
    public string $searchCrypto = '';

    public ?string $searchedFor = null;

    public bool $connectionOpen = false;

    public ?int $connectionId = null;

    /** The dialog's tab: general, options, ssl. */
    public string $connectionTab = 'general';

    /** default (the fields) or string (paste a connection string or URL). */
    public string $connectionMode = 'default';

    public string $connectionString = '';

    /** @var array<string, mixed> The dialog's fields. */
    public array $connection = [];

    /** @var array{ok: bool, message: string}|null */
    public ?array $connectionTest = null;

    /** The pasted string asked for Windows sign-in, which this server cannot do. */
    public bool $connectionIntegrated = false;

    /** The SQLite file picker: open, and the folder it shows (relative to the file manager's root). */
    public bool $pickerOpen = false;

    public string $pickerPath = '';

    /** Open the file picker where the chosen file is, or at the file manager's root. */
    public function openFilePicker(SitePaths $paths): void
    {
        $this->pickerPath = '';

        try {
            $root = $paths->globalRoot();
            $current = (string) ($this->connection['database'] ?? '');

            if ($current !== '' && $paths->inside($root, dirname($current))) {
                $this->pickerPath = $paths->relative($root, dirname($current));
            }
        } catch (Throwable) {
            $this->pickerPath = '';
        }

        $this->pickerOpen = true;
    }

    public function pickerGo(string $relative): void
    {
        $this->pickerPath = trim(str_replace('\\', '/', $relative), '/');
    }

    /** Choose a file from the picker. */
    public function pickFile(SitePaths $paths, string $relative): void
    {
        try {
            $path = $paths->resolveIn($paths->globalRoot(), $relative);
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->connection['database'] = $path;

        if (($this->connection['name'] ?? '') === '') {
            $this->connection['name'] = pathinfo($path, PATHINFO_FILENAME);
        }

        $this->pickerOpen = false;
        $this->connectionTest = null;
    }

    /**
     * The picker's folder: its folders and the SQLite-looking files in it.
     *
     * @return array{path: string, crumbs: list<array{name: string, path: string}>, folders: list<string>, files: list<array{name: string, size: int}>, error: ?string}
     */
    public function pickerListing(): array
    {
        $paths = app(SitePaths::class);
        $listing = ['path' => $this->pickerPath, 'crumbs' => [], 'folders' => [], 'files' => [], 'error' => null];

        try {
            $directory = $paths->resolveIn($paths->globalRoot(), $this->pickerPath);
            $names = @scandir($directory);

            if ($names === false) {
                throw new \RuntimeException('This folder cannot be read by the panel.');
            }

            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $full = $directory.'/'.$name;

                if (is_dir($full)) {
                    $listing['folders'][] = $name;
                } elseif (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), DatabaseConnection::SQLITE_EXTENSIONS, true)) {
                    $listing['files'][] = ['name' => $name, 'size' => (int) @filesize($full)];
                }
            }

            natcasesort($listing['folders']);
            $listing['folders'] = array_values($listing['folders']);
        } catch (Throwable $exception) {
            $listing['error'] = $exception->getMessage();
        }

        $walked = '';

        foreach (array_filter(explode('/', $this->pickerPath)) as $part) {
            $walked = ltrim($walked.'/'.$part, '/');
            $listing['crumbs'][] = ['name' => $part, 'path' => $walked];
        }

        return $listing;
    }

    /**
     * Turn on the panel's missing database driver (pdo_sqlsrv with sqlsrv, pdo_pgsql…) for the
     * panel's own PHP version, in the background.
     */
    public function installDriver(PanelTaskRunner $tasks): void
    {
        $driver = DatabaseConnection::DRIVERS[$this->connection['driver'] ?? ''] ?? null;
        $version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        if ($driver === null || extension_loaded($driver['extension']) || ! in_array($version, config('laravelify.php_versions'), true)) {
            return;
        }

        $changes = $driver['extension'] === 'pdo_sqlsrv' ? ['sqlsrv' => true, 'pdo_sqlsrv' => true] : [$driver['extension'] => true];
        $tasks->start('php.extensions', 'php:'.$version, 'Turning on '.implode(' and ', array_keys($changes)).' for PHP '.$version, ['version' => $version, 'changes' => $changes]);
        $this->toast('Installing '.implode(' and ', array_keys($changes)).' for the panel (PHP '.$version.') in the background'.($driver['extension'] === 'pdo_sqlsrv' ? ': Microsoft\'s ODBC driver is installed and the extensions are built, a few minutes' : '').'. Test the connection again when it is done.', 'info');
    }

    /** Open the dialog for a new connection, or the one with this slug. */
    public function openConnection(?string $slug = null): void
    {
        $this->resetErrorBag();
        $this->connectionTest = null;
        $this->connectionIntegrated = false;
        $this->connectionTab = 'general';
        $this->connectionMode = 'default';
        $this->connectionString = '';
        $existing = $slug !== null ? DatabaseConnection::query()->where('slug', $slug)->first() : null;
        $this->connectionId = $existing?->id;
        $this->connection = [
            'name' => $existing->name ?? '',
            'driver' => $existing->driver ?? 'sqlsrv',
            'host' => $existing->host ?? 'localhost',
            'port' => (string) ($existing ? ($existing->port ?: '') : 1433),
            'instance' => $existing->instance ?? '',
            'database' => $existing->database ?? '',
            'username' => $existing->username ?? '',
            'password' => '',
            'encrypt' => (bool) ($existing->encrypt ?? false),
            'trust_certificate' => (bool) ($existing->trust_certificate ?? true),
            'ssl_mode' => $existing->ssl_mode ?? 'prefer',
            'read_only' => (bool) ($existing->read_only ?? false),
            'timeout' => (string) ($existing->timeout ?? 8),
            'color' => $existing->color ?? '',
        ];
        $this->connectionOpen = true;
    }

    public function pickDriver(string $driver): void
    {
        if (! isset(DatabaseConnection::DRIVERS[$driver])) {
            return;
        }

        $defaults = array_column(DatabaseConnection::DRIVERS, 'port');

        if ($this->connection['port'] === '' || in_array((int) $this->connection['port'], $defaults, true)) {
            $this->connection['port'] = (string) DatabaseConnection::DRIVERS[$driver]['port'];
        }

        if ($driver === 'sqlite') {
            $this->connection['port'] = '';
            $this->connection['database'] = str_starts_with((string) $this->connection['database'], '/') ? $this->connection['database'] : '';
            $this->connectionTab = 'general';
        } elseif (($this->connection['driver'] ?? '') === 'sqlite') {
            $this->connection['database'] = '';
            $this->connection['host'] = ($this->connection['host'] ?? '') ?: 'localhost';
        }

        $this->connection['driver'] = $driver;
        $this->connectionTest = null;
    }

    /** Read the pasted connection string or URL into the fields. */
    public function applyConnectionString(): void
    {
        $values = DatabaseConnection::parse($this->connectionString);

        if ($values === []) {
            $this->addError('connectionString', 'This is not a connection string the panel knows: paste an ADO.NET string (Data Source=…;Initial Catalog=…), a JDBC URL or a URL like mysql://user@host:3306/db.');

            return;
        }

        $this->connectionIntegrated = (bool) ($values['integrated'] ?? false);
        unset($values['integrated']);

        foreach ($values as $key => $value) {
            $this->connection[$key] = is_bool($value) ? $value : (string) $value;
        }

        if (isset($values['port']) && (int) $values['port'] === 0) {
            $this->connection['port'] = '';
        }

        if (($this->connection['name'] ?? '') === '') {
            $this->connection['name'] = trim(($this->connection['database'] ?? '') ?: ($this->connection['host'] ?? ''));
        }

        $this->resetErrorBag('connectionString');
        $this->connectionMode = 'default';
        $this->connectionTest = null;
    }

    public function testConnection(EngineDatabases $engines): void
    {
        $this->connectionTest = null;
        $connection = $this->connectionModel();

        if ($connection === null) {
            return;
        }

        try {
            $this->connectionTest = ['ok' => true, 'message' => 'Connected: '.Str::limit(trim(preg_replace('/\s+/', ' ', $engines->test($connection)) ?? ''), 160)];
        } catch (Throwable $exception) {
            $this->connectionTest = ['ok' => false, 'message' => 'Could not connect: '.$exception->getMessage()];
        }
    }

    /** OK saves and closes; Apply saves and keeps the dialog open. */
    public function saveConnection(bool $close = true): void
    {
        $connection = $this->connectionModel();

        if ($connection === null) {
            return;
        }

        $connection->slug ??= $this->uniqueSlug($connection->name);
        $connection->save();
        $this->connectionId = $connection->id;
        $this->connection['password'] = '';
        $this->connectionOpen = ! $close;
        $this->toast('Saved. '.$connection->name.' is in the list.');
    }

    public function deleteConnection(): void
    {
        DatabaseConnection::query()->whereKey($this->connectionId)->delete();
        $this->connectionOpen = false;
        $this->toast('Connection removed.');
    }

    /** The URL under the fields, as the dialog's fields make it (no password). */
    public function connectionUrl(): string
    {
        return (new DatabaseConnection)->forceFill(array_intersect_key($this->connection, array_flip(['driver', 'host', 'port', 'instance', 'database', 'username'])) + ['port' => 0])->url();
    }

    /** The dialog as a connection (not saved), or null when a field is wrong. */
    private function connectionModel(): ?DatabaseConnection
    {
        $sqlsrv = ($this->connection['driver'] ?? '') === 'sqlsrv';

        if (($this->connection['driver'] ?? '') === 'sqlite') {
            return $this->sqliteModel();
        }

        $this->validate([
            'connection.name' => ['required', 'string', 'max:80'],
            'connection.driver' => ['required', Rule::in(array_keys(DatabaseConnection::DRIVERS))],
            'connection.host' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9.\-_:\[\]]+$/'],
            'connection.port' => [$sqlsrv && ($this->connection['instance'] ?? '') !== '' ? 'nullable' : 'required', 'integer', 'between:1,65535'],
            'connection.instance' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_$-]+$/'],
            'connection.database' => ['required', 'string', 'max:128'],
            'connection.username' => ['required', 'string', 'max:128'],
            'connection.password' => [$this->connectionId ? 'nullable' : 'required', 'string', 'max:512'],
            'connection.ssl_mode' => ['required', Rule::in(array_keys(DatabaseConnection::SSL_MODES))],
            'connection.timeout' => ['required', 'integer', 'between:1,120'],
            'connection.color' => ['nullable', Rule::in(['', ...array_keys(DatabaseConnection::COLORS)])],
        ], [], ['connection.host' => 'host', 'connection.database' => 'database', 'connection.username' => 'user', 'connection.password' => 'password', 'connection.name' => 'name', 'connection.port' => 'port', 'connection.instance' => 'instance', 'connection.timeout' => 'timeout']);

        $model = $this->connectionId ? DatabaseConnection::query()->findOrFail($this->connectionId) : new DatabaseConnection;
        $model->fill([
            'name' => trim($this->connection['name']),
            'driver' => $this->connection['driver'],
            'host' => trim($this->connection['host']),
            'port' => (int) $this->connection['port'],
            'instance' => $sqlsrv && trim((string) $this->connection['instance']) !== '' ? trim((string) $this->connection['instance']) : null,
            'database' => trim($this->connection['database']),
            'username' => trim($this->connection['username']),
            'encrypt' => (bool) $this->connection['encrypt'],
            'trust_certificate' => (bool) $this->connection['trust_certificate'],
            'ssl_mode' => $this->connection['ssl_mode'],
            'read_only' => (bool) $this->connection['read_only'],
            'timeout' => (int) $this->connection['timeout'],
            'color' => $this->connection['color'] !== '' ? $this->connection['color'] : null,
        ]);

        if ($this->connection['password'] !== '') {
            $model->password = $this->connection['password'];
        }

        return $model;
    }

    /** A SQLite file connection: a file inside the file manager's root. */
    private function sqliteModel(): ?DatabaseConnection
    {
        $this->validate([
            'connection.name' => ['required', 'string', 'max:80'],
            'connection.database' => ['required', 'string', 'max:1024'],
            'connection.color' => ['nullable', Rule::in(['', ...array_keys(DatabaseConnection::COLORS)])],
        ], [], ['connection.database' => 'file', 'connection.name' => 'name']);

        $paths = app(SitePaths::class);

        try {
            $root = $paths->globalRoot();
            $chosen = trim((string) $this->connection['database']);

            if (! $paths->inside($root, $chosen)) {
                throw new \RuntimeException('outside');
            }

            $path = $paths->resolveIn($root, $paths->relative($root, $chosen));
        } catch (Throwable) {
            $this->addError('connection.database', 'Pick a file inside the file manager ('.config('laravelify.files_root', config('laravelify.sites_root')).').');

            return null;
        }

        if (! is_file($path)) {
            $this->addError('connection.database', 'There is no such file.');

            return null;
        }

        $model = $this->connectionId ? DatabaseConnection::query()->findOrFail($this->connectionId) : new DatabaseConnection;
        $model->fill([
            'name' => trim($this->connection['name']),
            'driver' => 'sqlite',
            'host' => null,
            'port' => 0,
            'instance' => null,
            'database' => $path,
            'username' => null,
            'read_only' => (bool) $this->connection['read_only'],
            'timeout' => max(1, min(120, (int) ($this->connection['timeout'] ?: 8))),
            'color' => $this->connection['color'] !== '' ? $this->connection['color'] : null,
        ]);
        $model->password = null;

        return $model;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'connection';
        $slug = $base;

        for ($i = 2; DatabaseConnection::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    public function runSearch(DatabaseBrowser $browser, CryptoMethods $crypto): void
    {
        $this->searchError = null;
        $this->found = null;
        $this->searchedFor = null;

        if (trim($this->query) === '') {
            $this->searchError = 'Type what to look for.';

            return;
        }

        try {
            $term = $this->query;
            $method = $this->mode === 'data' && $this->searchCrypto !== '' ? CryptoMethod::query()->find((int) $this->searchCrypto) : null;

            if ($method) {
                $term = $crypto->encrypt($method, trim($this->query));
                $this->searchedFor = $term;
            }

            $this->found = $browser->search($term, [
                'mode' => $this->mode,
                'match' => $this->match,
                'column' => $this->column,
                'databases' => $this->scope,
                'encrypted' => $method === null && $this->mode === 'data' ? $crypto->termsForEncryptedColumns($this->query, $this->scope) : [],
            ]);
        } catch (Throwable $exception) {
            $this->searchError = $exception->getMessage();
        }
    }

    /** A new database for the SQL box: the editor loads its tables and columns again. */
    public function updatedSqlIn(): void
    {
        $this->dispatch('sql-schema-changed');
    }

    protected function sqlDatabase(): string
    {
        return DatabaseBrowser::validDatabase($this->sqlIn) ? $this->sqlIn : '';
    }

    public function sortBy(string $column): void
    {
        // The same column again reverses the order; a new one starts A–Z for names, largest first for numbers.
        $this->direction = $this->sort === $column ? ($this->direction === 'asc' ? 'desc' : 'asc') : ($column === 'name' ? 'asc' : 'desc');
        $this->sort = in_array($column, ['name', 'tables', 'size'], true) ? $column : 'name';
    }

    public function openExport(DatabaseBrowser $browser, string $database): void
    {
        if (! DatabaseBrowser::validDatabase($database)) {
            return;
        }

        try {
            $this->exportTables = array_column($browser->tables($database), 'name');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->exportDatabase = $database;
        $this->exportSingle = false;
        $this->exportOpen = true;
    }

    /**
     * Download a PostgreSQL (.sql.gz), SQLite (.sqlite.gz) or MongoDB (mongodump .archive.gz) dump.
     */
    public function exportEngine(EngineDatabases $engines, string $engine, string $database): mixed
    {
        return ExportsEngineDump::download($this, $engines, $engine, $database);
    }

    public function backup(BackupManager $backups, string $database): void
    {
        try {
            $backups->backupDatabases([$database]);
            $this->toast('Backup of '.$database.' started. It shows under Backups → Databases.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function render(DatabaseBrowser $browser, EngineDatabases $engines)
    {
        $databases = [];
        $error = null;

        if (DatabaseEngines::has(DatabaseEngines::MYSQL)) {
            try {
                $databases = array_map(fn (array $database): array => ['engine' => DatabaseEngines::MYSQL] + $database, $browser->databases());
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        // PostgreSQL, SQLite and MongoDB are asked only on the Databases tab: they can be slower.
        $mysqlNames = array_column($databases, 'name');
        $engineErrors = [];

        if ($this->tab === 'databases') {
            $others = $engines->databases();
            $databases = [...$databases, ...$others['databases']];
            $engineErrors = $others['errors'];
        }

        $engineChoices = [...(DatabaseEngines::has(DatabaseEngines::MYSQL) ? [DatabaseEngines::MYSQL] : []), ...$engines->engines(), ...(DatabaseConnection::query()->exists() ? [EngineDatabases::REMOTE] : [])];

        $shown = collect($databases)
            ->filter(fn (array $database): bool => $this->filter === '' || str_contains(strtolower($database['name']), strtolower(trim($this->filter))))
            ->sortBy(in_array($this->sort, ['tables', 'size'], true) ? $this->sort : 'name', SORT_NATURAL | SORT_FLAG_CASE, $this->direction === 'desc')
            ->values();

        return view('livewire.database.index', [
            'databases' => $shown,
            'allDatabases' => $mysqlNames,
            'databaseCount' => count($databases),
            'engineErrors' => $engineErrors,
            'engineChoices' => $engineChoices,
            'totalSize' => array_sum(array_column($databases, 'size')),
            'error' => $error,
            'changes' => $this->tab === 'changes' ? DatabaseChange::query()->with('user')->latest('id')->limit(200)->get() : collect(),
            'cryptoMethods' => CryptoMethod::query()->with('website')->orderBy('name')->get(),
            'cryptoWebsites' => $this->tab === 'encryption' ? $this->cryptoWebsites() : collect(),
        ])->layout('layouts.app')->title('Database');
    }
}
