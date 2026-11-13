<?php

namespace App\Livewire\Database;

use App\Livewire\Concerns\KeepsSqlHistory;
use App\Livewire\PanelComponent;
use App\Models\DatabaseConnection;
use App\Services\BackupManager;
use App\Services\DatabaseBrowser;
use App\Services\EngineDatabases;
use App\Support\DatabaseEngines;
use Livewire\Attributes\Url;
use RuntimeException;
use Throwable;

/**
 * A PostgreSQL, SQLite or MongoDB database on this server, or a database outside it (SQL Server,
 * MySQL, PostgreSQL): its tables, one table's rows in the same spreadsheet grid as MySQL (search,
 * header filters, sort, double-click a row to open it), adding, changing and deleting rows, and an
 * SQL box. MongoDB shows documents as JSON with a JSON filter.
 */
class Engine extends PanelComponent
{
    use KeepsSqlHistory;

    public string $engine = '';

    public string $database = '';

    #[Url(history: true)]
    public string $table = '';

    #[Url(history: true)]
    public string $tab = 'tables';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public int $page = 1;

    /** @var list<array{column: string, operator: string, value: string, values?: list<string>, blanks?: bool}> The grid's header filters. */
    public array $filters = [];

    public ?string $sort = null;

    public string $direction = 'asc';

    /** How the tables list is sorted: name, rows or size. */
    #[Url(as: 'tsort')]
    public string $tableSort = 'name';

    #[Url(as: 'tdir')]
    public string $tableDirection = 'asc';

    public string $sql = '';

    /** @var array{columns: list<string>, rows: list<list<mixed>>, affected: int}|null */
    public ?array $sqlResult = null;

    public ?string $sqlError = null;

    public bool $editOpen = false;

    /** @var array<string, mixed>|null The primary key of the row being changed; null for a new row. */
    public ?array $editKeys = null;

    /** @var array<string, string|null> */
    public array $editValues = [];

    /** @var array<string, bool> Columns set to NULL. */
    public array $editNulls = [];

    /** The _id (Extended JSON) of the document being changed; null for a new one. */
    public ?string $documentId = null;

    public string $documentJson = '';

    /** The name typed in "New schema". */
    public string $newSchema = '';

    /** Make an empty schema (PostgreSQL, SQL Server). */
    public function createSchema(EngineDatabases $databases): void
    {
        $name = trim($this->newSchema);

        try {
            $databases->createSchema($this->engine, $this->database, $name);
        } catch (Throwable $exception) {
            $this->addError('newSchema', $exception->getMessage());

            return;
        }

        $this->newSchema = '';
        $this->dispatch('schema-created');
        $this->toast('Schema '.$name.' is ready. Make tables in it with SQL: CREATE TABLE '.$name.'.items (...).', 'success');
    }

    public function mount(string $engine, string $database): void
    {
        $known = $engine === EngineDatabases::REMOTE
            ? DatabaseConnection::query()->where('slug', $database)->exists()
            : EngineDatabases::validEngine($engine) && DatabaseEngines::has($engine);
        abort_unless($known, 404);
        $this->engine = $engine;
        $this->database = $database;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedTable(): void
    {
        $this->filters = [];
        $this->sort = null;
        $this->page = 1;
    }

    /** The same column again reverses the order; a new one starts A–Z for names, largest first for numbers. */
    public function sortTables(string $column): void
    {
        $column = in_array($column, ['name', 'rows', 'size'], true) ? $column : 'name';
        $this->tableDirection = $this->tableSort === $column ? ($this->tableDirection === 'asc' ? 'desc' : 'asc') : ($column === 'name' ? 'asc' : 'desc');
        $this->tableSort = $column;
    }

    /** Download a dump of this database. */
    public function export(EngineDatabases $databases): mixed
    {
        return ExportsEngineDump::download($this, $databases, $this->engine, $this->database);
    }

    /** Back up this database now to the backup storage, listed under Backups → Databases. */
    public function backup(BackupManager $backups): void
    {
        try {
            $backups->backupDatabases([EngineDatabases::backupTarget($this->engine, $this->database)]);
            $this->toast('Backup of '.$this->database.' started. It shows under Backups → Databases.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    /**
     * Rows for the grid as it scrolls.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, error?: string}
     */
    public function fetchRows(EngineDatabases $databases, int $start, int $end, ?string $sort = null, string $direction = 'asc'): array
    {
        $this->sort = $sort !== null && $sort !== '' ? $sort : null;
        $this->direction = $direction === 'desc' ? 'desc' : 'asc';

        try {
            $data = $databases->rows($this->engine, $this->database, $this->table, $this->query($start, $end - $start));
        } catch (Throwable $exception) {
            return ['rows' => [], 'total' => 0, 'error' => $exception->getMessage()];
        }

        return ['rows' => array_map($this->preview(...), $data['rows']), 'total' => $data['total']];
    }

    /**
     * A column's most common values, for the header filter's list.
     *
     * @return array{values: list<array{value: mixed, count: int}>, more: bool, error?: string}
     */
    public function columnValues(EngineDatabases $databases, string $column, string $find = ''): array
    {
        try {
            return $databases->distinctValues($this->engine, $this->database, $this->table, $column, $this->search, $this->filters, $find);
        } catch (Throwable $exception) {
            return ['values' => [], 'more' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * @param  array{mode?: string, operator?: string, value?: string, values?: list<mixed>, blanks?: bool}  $filter
     */
    public function setColumnFilter(string $column, array $filter): void
    {
        $this->filters = array_values(array_filter($this->filters, fn (array $existing): bool => ($existing['column'] ?? '') !== $column));

        if (($filter['mode'] ?? '') === 'values') {
            $this->filters[] = ['column' => $column, 'operator' => 'in', 'values' => array_values(array_map('strval', array_filter((array) ($filter['values'] ?? []), 'is_scalar'))), 'blanks' => (bool) ($filter['blanks'] ?? false), 'value' => ''];
        } elseif (($filter['mode'] ?? '') === 'condition' && array_key_exists((string) ($filter['operator'] ?? ''), DatabaseBrowser::OPERATORS)) {
            $this->filters[] = ['column' => $column, 'operator' => (string) $filter['operator'], 'value' => (string) ($filter['value'] ?? '')];
        }

        $this->page = 1;
    }

    public function clearFilters(): void
    {
        $this->filters = [];
        $this->search = '';
    }

    /**
     * The tables and columns, for the SQL editor's autocomplete.
     *
     * @return array<string, list<string>>
     */
    public function sqlSchema(EngineDatabases $databases): array
    {
        try {
            return $databases->schema($this->engine, $this->database);
        } catch (Throwable) {
            return [];
        }
    }

    public function runSql(EngineDatabases $databases, string $selection = '', ?string $sql = null): void
    {
        if ($sql !== null) {
            $this->sql = $sql;
        }

        $this->sqlResult = null;
        $this->sqlError = null;

        $ranSql = trim($selection) !== '' ? $selection : $this->sql;
        $started = microtime(true);

        try {
            $result = $databases->run($this->engine, $this->database, $ranSql);
            $write = $result['columns'] === [];
            $this->saveSqlRun($ranSql, $write ? 'write' : 'read', true, $write ? null : count($result['rows']), $write ? $result['affected'] : null, null, $started, array_values(array_filter([$result['changes'] ?? null])));
            unset($result['changes']);
            $this->sqlResult = $result;
        } catch (Throwable $exception) {
            $this->sqlError = $exception->getMessage();
            $this->saveSqlRun($ranSql, 'error', false, null, null, $exception->getMessage(), $started);
        }
    }

    protected function runFromHistory(string $sql): void
    {
        $this->runSql(app(EngineDatabases::class), '', $sql);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function historyTarget(): array
    {
        return [$this->engine, $this->database];
    }

    public function newRow(EngineDatabases $databases): void
    {
        $this->resetErrorBag();
        $this->editKeys = null;
        $this->documentId = null;

        if ($this->isMongo()) {
            $this->documentJson = "{\n  \n}";
        } else {
            $columns = array_column($databases->columns($this->engine, $this->database, $this->table), 'name');
            $this->editValues = array_fill_keys($columns, '');
            $this->editNulls = array_fill_keys($columns, false);
        }

        $this->editOpen = true;
    }

    /**
     * Open one row of the grid (double-click) by its primary key.
     *
     * @param  array<string, mixed>  $keys
     */
    public function openRow(EngineDatabases $databases, array $keys): void
    {
        $this->resetErrorBag();

        try {
            $row = $databases->row($this->engine, $this->database, $this->table, $keys);
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        if ($row === null) {
            $this->toast('This row is not there any more.', 'error');

            return;
        }

        $this->editKeys = array_intersect_key($row, $keys);
        $this->editValues = array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, $row);
        $this->editNulls = array_map(fn (mixed $value): bool => $value === null, $row);
        $this->editOpen = true;
    }

    /** Open a MongoDB document from the list. */
    public function editRow(EngineDatabases $databases, int $index): void
    {
        $this->resetErrorBag();
        $row = $this->isMongo() ? ($databases->documents($this->database, $this->table, $this->page, $this->search)['rows'][$index] ?? null) : null;

        if ($row === null) {
            $this->toast('That document is no longer on this page.', 'error');

            return;
        }

        $this->documentId = $row['id'];
        $this->documentJson = $row['json'];
        $this->editOpen = true;
    }

    public function save(EngineDatabases $databases): void
    {
        $adding = $this->isMongo() ? $this->documentId === null : $this->editKeys === null;

        try {
            if ($this->isMongo()) {
                $databases->saveDocument($this->database, $this->table, $this->documentId, $this->documentJson);
            } else {
                $values = [];

                foreach ($this->editValues as $column => $value) {
                    if ($adding && ! ($this->editNulls[$column] ?? false) && ($value ?? '') === '') {
                        continue;
                    }

                    $values[$column] = ($this->editNulls[$column] ?? false) ? null : (string) $value;
                }

                $databases->saveRow($this->engine, $this->database, $this->table, $this->editKeys, $values);
            }
        } catch (Throwable $exception) {
            $this->addError('edit', $exception->getMessage());

            return;
        }

        $this->editOpen = false;
        $this->dispatch('rows-deleted');
        $this->toast($adding ? 'Added.' : 'Saved.');
    }

    /** Delete the open row. */
    public function deleteOpenRow(EngineDatabases $databases): void
    {
        if ($this->editKeys === null) {
            return;
        }

        $this->deleteKeys($databases, [(string) json_encode($this->editKeys)]);
        $this->editOpen = false;
    }

    /**
     * Delete the rows ticked in the grid (their primary keys as JSON).
     *
     * @param  list<string>  $rows
     */
    public function deleteKeys(EngineDatabases $databases, array $rows): void
    {
        $deleted = 0;

        try {
            foreach (array_slice($rows, 0, 500) as $json) {
                $keys = json_decode($json, true);

                if (! is_array($keys) || $keys === []) {
                    throw new RuntimeException('A ticked row has no key.');
                }

                $databases->deleteRow($this->engine, $this->database, $this->table, $keys);
                $deleted++;
            }
        } catch (Throwable $exception) {
            $this->toast(($deleted > 0 ? $deleted.' deleted, then: ' : '').$exception->getMessage(), 'error');
        }

        $this->dispatch('rows-deleted');

        if ($deleted > 0) {
            $this->toast($deleted === 1 ? 'Deleted 1 row.' : 'Deleted '.$deleted.' rows.');
        }
    }

    public function deleteDocument(EngineDatabases $databases, string $id): void
    {
        try {
            $databases->deleteDocument($this->database, $this->table, $id);
            $this->toast('Deleted.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function render(EngineDatabases $databases)
    {
        $error = null;
        $tables = [];
        $page = null;
        $grid = null;
        $schemas = [];

        try {
            $label = $databases->label($this->engine, $this->database);

            if ($this->table === '') {
                $tables = $databases->tables($this->engine, $this->database);
                $schemas = $this->isMongo() ? [] : $databases->schemas($this->engine, $this->database);
            } elseif ($this->tab === 'tables' && $this->isMongo()) {
                $page = $databases->documents($this->database, $this->table, $this->page, $this->search);
            } elseif ($this->tab === 'tables') {
                $first = $databases->rows($this->engine, $this->database, $this->table, $this->query(0, 1));
                $grid = $this->gridPayload($first['columns'], $first['total']);
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            $label ??= DatabaseEngines::label($this->engine);
        }

        $connection = $this->engine === EngineDatabases::REMOTE ? DatabaseConnection::query()->where('slug', $this->database)->first() : null;

        return view('livewire.database.engine', [
            'label' => $label,
            'connection' => $connection,
            'isMongo' => $this->isMongo(),
            'tables' => collect($tables)
                ->filter(fn (array $table): bool => $this->search === '' || $this->table !== '' || str_contains(strtolower($table['name']), strtolower($this->search)))
                ->sortBy(in_array($this->tableSort, ['rows', 'size'], true) ? $this->tableSort : 'name', SORT_NATURAL | SORT_FLAG_CASE, $this->tableDirection === 'desc')
                ->values(),
            'result' => $page,
            'grid' => $grid,
            'perPage' => EngineDatabases::PER_PAGE,
            'error' => $error,
            'routeEngine' => $this->engine,
            'schemas' => $schemas,
            'hasSchemas' => ! $this->isMongo() && in_array(rescue(fn () => $databases->dialect($this->engine, $this->database), '', false), ['pgsql', 'sqlsrv'], true),
        ])->layout('layouts.app')->title(($this->table !== '' ? $this->table.' · ' : '').($connection->name ?? $this->database).' · Database');
    }

    private function isMongo(): bool
    {
        return $this->engine === DatabaseEngines::MONGODB;
    }

    /**
     * @return array{offset: int, limit: int, sort: ?string, direction: string, search: string, filters: list<array<string, mixed>>}
     */
    private function query(int $offset, int $limit): array
    {
        return ['offset' => max(0, $offset), 'limit' => max(1, min(500, $limit)), 'sort' => $this->sort, 'direction' => $this->direction, 'search' => $this->search, 'filters' => $this->filters];
    }

    /**
     * What the grid needs: the columns, their keys and filters, and the row count.
     *
     * @param  list<array{name: string, type: string, key: bool, nullable: bool}>  $columns
     * @return array<string, mixed>
     */
    private function gridPayload(array $columns, int $total): array
    {
        $keyColumns = array_values(array_column(array_filter($columns, fn (array $column): bool => $column['key']), 'name'));
        $numeric = ['smallint', 'integer', 'int', 'bigint', 'tinyint', 'mediumint', 'decimal', 'numeric', 'real', 'double precision', 'double', 'float', 'money', 'smallmoney', 'bit', 'year'];

        return [
            'database' => $this->engine.':'.$this->database,
            'table' => $this->table,
            'urlTemplate' => route('database.engine', [$this->engine, $this->database]).'?table=__TABLE__',
            'columns' => array_map(fn (array $column): array => [
                'name' => $column['name'],
                'type' => $column['type'],
                'dataType' => strtolower($column['type']),
                'key' => $column['key'],
                'numeric' => in_array(strtolower(preg_replace('/\(.*$/', '', $column['type']) ?? ''), $numeric, true),
                'out' => null,
                'in' => [],
                'encrypted' => null,
                'raw' => false,
            ], $columns),
            'methods' => [],
            'total' => $total,
            'version' => md5(json_encode([$this->search, $this->filters, $this->sort, $this->direction])),
            'filtered' => array_values(array_unique(array_filter(array_column($this->filters, 'column')))),
            'columnFilters' => (object) collect($this->filters)->keyBy('column')->all(),
            'operators' => DatabaseBrowser::OPERATORS,
            'keyColumns' => $keyColumns,
            'canChange' => $keyColumns !== [] && app(EngineDatabases::class)->writable($this->engine, $this->database),
            'sort' => $this->sort,
            'direction' => $this->direction,
            'anyFilter' => $this->search !== '' || $this->filters !== [],
        ];
    }

    /**
     * A row as the grid shows it: long text shortened (the row dialog has it whole).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function preview(array $row): array
    {
        return array_map(fn (mixed $value): mixed => is_string($value) && mb_strlen($value) > 300 ? mb_substr($value, 0, 300).'…' : $value, $row);
    }
}
