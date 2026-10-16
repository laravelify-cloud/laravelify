<?php

namespace App\Services;

use App\Models\DatabaseChange;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The Database tool: what is on the MySQL server (databases, tables, rows, triggers, events,
 * procedures, functions), searching it, and changing it. Every change is written to the change
 * log (database_changes) with what was there before, so it can be undone on its own later.
 *
 * It uses the panel's own MySQL login, which can manage every database. Names of databases and
 * tables are checked against what exists and quoted; values are always bound.
 */
class DatabaseBrowser
{
    public const CONNECTION = 'dbtool';

    public const OBJECT_TYPES = ['trigger', 'event', 'procedure', 'function'];

    public const OPERATORS = [
        'contains' => 'contains',
        'equals' => '=',
        'not_equals' => '≠',
        'starts' => 'starts with',
        'ends' => 'ends with',
        'regex' => 'matches regex',
        'gt' => '>',
        'lt' => '<',
        'null' => 'is empty (NULL)',
        'not_null' => 'is not NULL',
    ];

    /** Longest value shown in lists; the row dialog shows the whole value. */
    private const PREVIEW = 300;

    private bool $fresh = false;

    public function __construct(private DatabaseDumper $dumper) {}

    public function connection(): Connection
    {
        if (! config('database.connections.'.self::CONNECTION)) {
            config(['database.connections.'.self::CONNECTION => [...(array) config('database.connections.mysql'), 'database' => 'information_schema']]);
        }

        $connection = DB::connection(self::CONNECTION);

        // MySQL 8 caches table sizes and row counts for a day; this tool shows them as they are now.
        if (! $this->fresh) {
            try {
                $connection->statement('SET SESSION information_schema_stats_expiry = 0');
            } catch (Throwable) {
                // MySQL 5.7 and MariaDB have no such cache.
            }

            $this->fresh = true;
        }

        return $connection;
    }

    public static function validDatabase(string $name): bool
    {
        return DatabaseDumper::validName($name) && ! in_array(strtolower($name), DatabaseDumper::SYSTEM, true) && ! DatabaseDumper::isPanelDatabase($name);
    }

    public static function validTable(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $name) === 1;
    }

    // Reading

    /**
     * @return list<array{name: string, tables: int, size: int}>
     */
    public function databases(): array
    {
        $rows = $this->connection()->select(
            'SELECT s.SCHEMA_NAME AS name, COUNT(t.TABLE_NAME) AS tables, COALESCE(SUM(t.DATA_LENGTH + t.INDEX_LENGTH), 0) AS size
             FROM information_schema.SCHEMATA s
             LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME AND t.TABLE_TYPE = \'BASE TABLE\'
             GROUP BY s.SCHEMA_NAME ORDER BY s.SCHEMA_NAME'
        );

        return array_values(array_filter(array_map(fn (object $row): array => [
            'name' => (string) $row->name,
            'tables' => (int) $row->tables,
            'size' => (int) $row->size,
        ], $rows), fn (array $row): bool => self::validDatabase($row['name'])));
    }

    /**
     * @return list<array{name: string, rows: int, size: int, engine: ?string, collation: ?string, comment: string, updated: ?string}>
     */
    public function tables(string $database): array
    {
        $this->assertDatabase($database);

        return array_map(fn (object $row): array => [
            'name' => (string) $row->name,
            'rows' => (int) $row->table_rows,
            'size' => (int) $row->size,
            'engine' => $row->engine,
            'collation' => $row->collation,
            'comment' => (string) $row->comment,
            'updated' => $row->updated,
        ], $this->connection()->select(
            'SELECT TABLE_NAME AS name, TABLE_ROWS AS table_rows, DATA_LENGTH + INDEX_LENGTH AS size, ENGINE AS engine,
                    TABLE_COLLATION AS collation, TABLE_COMMENT AS comment, UPDATE_TIME AS updated
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME',
            [$database],
        ));
    }

    /**
     * @return list<array{name: string, type: string, data_type: string, nullable: bool, key: string, default: ?string, extra: string}>
     */
    public function columns(string $database, string $table): array
    {
        $this->assertTable($database, $table);

        return array_map(fn (object $row): array => [
            'name' => (string) $row->name,
            'type' => (string) $row->type,
            'data_type' => strtolower((string) $row->data_type),
            'nullable' => $row->nullable === 'YES',
            'key' => (string) $row->column_key,
            'default' => $row->column_default,
            'extra' => (string) $row->extra,
        ], $this->connection()->select(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, DATA_TYPE AS data_type, IS_NULLABLE AS nullable,
                    COLUMN_KEY AS column_key, COLUMN_DEFAULT AS column_default, EXTRA AS extra
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$database, $table],
        ));
    }

    /**
     * The columns that find one row: the primary key, or else a unique key whose columns are never NULL.
     * Empty when the table has neither (its rows can be read, not changed).
     *
     * @return list<string>
     */
    public function keyColumns(string $database, string $table): array
    {
        $this->assertTable($database, $table);
        $indexes = [];

        foreach ($this->connection()->select(
            'SELECT INDEX_NAME AS name, COLUMN_NAME AS col, NON_UNIQUE AS non_unique, NULLABLE AS nullable
             FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$database, $table],
        ) as $row) {
            if ((int) $row->non_unique === 0) {
                $indexes[$row->name]['columns'][] = (string) $row->col;
                $indexes[$row->name]['nullable'] = ($indexes[$row->name]['nullable'] ?? false) || $row->nullable === 'YES';
            }
        }

        if (isset($indexes['PRIMARY'])) {
            return $indexes['PRIMARY']['columns'];
        }

        foreach ($indexes as $index) {
            if (! $index['nullable']) {
                return $index['columns'];
            }
        }

        return [];
    }

    /**
     * One page of rows.
     *
     * @param  array{page?: int, per_page?: int, sort?: ?string, direction?: string, search?: string, filters?: list<array{column: string, operator: string, value?: ?string}>}  $options
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function rows(string $database, string $table, array $options = []): array
    {
        $columns = array_column($this->columns($database, $table), 'name');
        $perPage = max(10, min(500, (int) ($options['per_page'] ?? 25)));
        [$where, $bindings] = $this->where($columns, (string) ($options['search'] ?? ''), (array) ($options['filters'] ?? []));
        $from = $this->qualified($database, $table);

        $total = (int) ($this->connection()->selectOne('SELECT COUNT(*) AS c FROM '.$from.$where, $bindings)->c ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) ($options['page'] ?? 1)));

        $order = '';
        $sort = $options['sort'] ?? null;

        if (is_string($sort) && in_array($sort, $columns, true)) {
            $order = ' ORDER BY '.$this->quote($sort).(($options['direction'] ?? 'asc') === 'desc' ? ' DESC' : ' ASC');
        }

        $rows = $this->connection()->select(
            'SELECT /*+ MAX_EXECUTION_TIME(15000) */ * FROM '.$from.$where.$order.' LIMIT '.$perPage.' OFFSET '.(($page - 1) * $perPage),
            $bindings,
        );

        return [
            'rows' => array_map(fn (object $row): array => $this->readable((array) $row), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * One row, every value whole.
     *
     * @param  array<string, mixed>  $keys
     * @return array<string, mixed>|null
     */
    public function row(string $database, string $table, array $keys): ?array
    {
        $raw = $this->rawRow($database, $table, $keys);

        return $raw === null ? null : $this->readable($raw, false);
    }

    // Changing rows

    /**
     * Add a row. Columns left out get their default.
     *
     * @param  array<string, ?string>  $values
     * @return array<string, mixed> The keys of the new row.
     */
    public function insertRow(string $database, string $table, array $values): array
    {
        $columns = $this->columns($database, $table);
        $values = $this->known($columns, $values);
        $keys = $this->requireKeys($database, $table);

        if ($values === []) {
            $this->connection()->insert('INSERT INTO '.$this->qualified($database, $table).' () VALUES ()');
        } else {
            $this->connection()->insert(
                'INSERT INTO '.$this->qualified($database, $table).' ('.implode(', ', array_map($this->quote(...), array_keys($values))).') VALUES ('.implode(', ', array_fill(0, count($values), '?')).')',
                array_values($values),
            );
        }

        $found = [];

        foreach ($keys as $column) {
            $found[$column] = array_key_exists($column, $values) ? $values[$column] : $this->connection()->getPdo()->lastInsertId();
        }

        $after = $this->rawRow($database, $table, $found);
        $this->log($database, $table, 'insert', $this->keysOf($keys, $after ?? $found), null, $after);

        return $this->keysOf($keys, $after ?? $found);
    }

    /**
     * Change some values of one row. Only values that differ are written.
     *
     * @param  array<string, mixed>  $keys
     * @param  array<string, ?string>  $values
     * @return array<string, mixed> The keys of the row afterwards (a key value can change too).
     */
    public function updateRow(string $database, string $table, array $keys, array $values): array
    {
        $keyColumns = $this->requireKeys($database, $table);
        $before = $this->rawRow($database, $table, $keys) ?? throw new RuntimeException('This row is not there any more. Someone may have deleted it.');
        $values = $this->known($this->columns($database, $table), $values);
        $changes = array_filter($values, fn ($value, string $column): bool => $this->differs($before[$column] ?? null, $value), ARRAY_FILTER_USE_BOTH);

        if ($changes === []) {
            return $this->keysOf($keyColumns, $before);
        }

        [$match, $matchBindings] = $this->match($keys);
        $this->connection()->update(
            'UPDATE '.$this->qualified($database, $table).' SET '.implode(', ', array_map(fn (string $column): string => $this->quote($column).' = ?', array_keys($changes))).$match.' LIMIT 1',
            [...array_values($changes), ...$matchBindings],
        );

        $newKeys = $this->keysOf($keyColumns, [...$before, ...$changes]);
        $after = $this->rawRow($database, $table, $newKeys);
        $this->log($database, $table, 'update', $newKeys, $before, $after);

        return $newKeys;
    }

    /**
     * Delete rows, each found by its keys. Each deleted row is kept in the change log.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function deleteRows(string $database, string $table, array $rows, ?string $snapshot = null): int
    {
        $this->requireKeys($database, $table);
        $deleted = 0;

        foreach ($rows as $keys) {
            $before = $this->rawRow($database, $table, $keys);

            if ($before === null) {
                continue;
            }

            [$match, $bindings] = $this->match($keys);
            $deleted += $this->connection()->delete('DELETE FROM '.$this->qualified($database, $table).$match.' LIMIT 1', $bindings);
            $this->log($database, $table, 'delete', $keys, $before, null, snapshot: $snapshot);
        }

        return $deleted;
    }

    /**
     * Save a copy of one table (structure and rows) before a risky change, to put back later.
     */
    public function snapshotTable(string $database, string $table): string
    {
        $this->assertTable($database, $table);
        $directory = storage_path('app/database-snapshots');

        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        $file = $directory.'/'.$database.'.'.$table.'.'.now()->format('Ymd-His').'-'.Str::random(6).'.sql.gz';
        $this->dumper->dumpTables($database, [$table], $file);

        return $file;
    }

    /**
     * Put a saved copy of a table back: the table becomes exactly what it was then.
     */
    public function restoreSnapshot(DatabaseChange $change): void
    {
        if (! $change->snapshot || ! is_file($change->snapshot)) {
            throw new RuntimeException('The saved copy of this table is gone.');
        }

        $this->dumper->restore($change->snapshot, $change->database);
    }

    // Triggers, events, procedures, functions

    /**
     * @return array{trigger: list<array<string, mixed>>, event: list<array<string, mixed>>, procedure: list<array<string, mixed>>, function: list<array<string, mixed>>}
     */
    public function objects(string $database): array
    {
        $this->assertDatabase($database);
        $db = $this->connection();
        $rows = fn (string $sql): array => array_map(fn (object $row): array => (array) $row, $db->select($sql, [$database]));

        $routines = $rows('SELECT ROUTINE_NAME AS name, ROUTINE_TYPE AS type, CREATED AS created, LAST_ALTERED AS changed FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_NAME');

        return [
            'trigger' => $rows('SELECT TRIGGER_NAME AS name, ACTION_TIMING AS timing, EVENT_MANIPULATION AS happens, EVENT_OBJECT_TABLE AS on_table, CREATED AS created FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME'),
            'event' => $rows('SELECT EVENT_NAME AS name, STATUS AS status, EVENT_TYPE AS kind, INTERVAL_VALUE AS every, INTERVAL_FIELD AS unit, EXECUTE_AT AS at, LAST_EXECUTED AS last_run FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? ORDER BY EVENT_NAME'),
            'procedure' => array_values(array_filter($routines, fn (array $row): bool => $row['type'] === 'PROCEDURE')),
            'function' => array_values(array_filter($routines, fn (array $row): bool => $row['type'] === 'FUNCTION')),
        ];
    }

    /**
     * The CREATE statement of a trigger, event, procedure, or function.
     */
    public function definition(string $database, string $type, string $name): string
    {
        $this->assertObject($database, $type, $name);
        $row = (array) $this->connection()->selectOne('SHOW CREATE '.strtoupper($type).' '.$this->quote($database).'.'.$this->quote($name));
        $column = ['trigger' => 'SQL Original Statement', 'event' => 'Create Event', 'procedure' => 'Create Procedure', 'function' => 'Create Function'][$type];

        return (string) ($row[$column] ?? throw new RuntimeException('MySQL did not show the definition. The panel login may lack the rights for it.'));
    }

    public function dropObject(string $database, string $type, string $name): void
    {
        $before = $this->definition($database, $type, $name);
        $this->connection()->unprepared('DROP '.strtoupper($type).' '.$this->quote($database).'.'.$this->quote($name));
        $this->log($database, null, 'drop', null, null, null, $type, $name, $before, null);
    }

    /**
     * Create a trigger, event, procedure, or function from its CREATE statement, or replace one
     * ($replacing is its current name). When the new statement fails, the old one is put back.
     */
    public function saveObject(string $database, string $type, string $sql, ?string $replacing = null): string
    {
        $this->assertDatabase($database);
        $sql = trim(preg_replace('/^\s*DELIMITER\s+\S+\s*$/mi', '', $sql) ?? $sql);
        $sql = rtrim(preg_replace('/(\$\$|\/\/)\s*$/', '', $sql) ?? $sql);
        $sql = rtrim($sql, "; \n\r\t");
        $pattern = '/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:DEFINER\s*=\s*\S+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?'.strtoupper($type).'\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?[A-Za-z0-9_$-]+`?\.)?`?([A-Za-z0-9_$]+)`?/i';

        if (! in_array($type, self::OBJECT_TYPES, true) || preg_match($pattern, $sql, $match) !== 1) {
            throw new RuntimeException('Write a CREATE '.strtoupper($type).' statement, like the ones MySQL shows for existing ones.');
        }

        $name = $match[1];
        $before = $replacing !== null ? $this->definition($database, $type, $replacing) : null;
        $db = $this->connection();

        if ($replacing !== null) {
            $db->unprepared('DROP '.strtoupper($type).' '.$this->quote($database).'.'.$this->quote($replacing));
        }

        try {
            $db->unprepared('USE '.$this->quote($database));
            $db->unprepared($sql);
        } catch (Throwable $exception) {
            if ($before !== null) {
                try {
                    $db->unprepared($before);
                } catch (Throwable) {
                    // The log below still has the old statement to put back by hand.
                }
            }

            throw new RuntimeException('MySQL did not accept it: '.$this->mysqlMessage($exception).($before !== null ? ' The old one was put back.' : ''));
        } finally {
            $db->unprepared('USE information_schema');
        }

        $this->log($database, null, $replacing !== null ? 'alter' : 'create', null, null, null, $type, $name, $before, $this->definition($database, $type, $name));

        return $name;
    }

    // Undo

    /**
     * Undo one change, only for the row or object it touched. Stops with a clear message when the row
     * changed since (someone else edited it); $force writes it back anyway.
     */
    public function revert(DatabaseChange $change, bool $force = false): DatabaseChange
    {
        if ($change->reverted_at !== null) {
            throw new RuntimeException('This change was already undone.');
        }

        $newest = (int) DatabaseChange::query()->max('id');
        // Rows in one transaction; a trigger or event is DDL, which MySQL commits at once anyway.
        $change->isRow()
            ? $this->connection()->transaction(fn () => $this->revertRow($change, $force))
            : $this->revertObject($change);
        $change->forceFill(['reverted_at' => now()])->save();

        // The undo is a change of its own (it can be undone too); it says what it undid.
        DatabaseChange::query()->where('id', '>', $newest)->update(['reverts_id' => $change->id]);

        return $change;
    }

    private function revertRow(DatabaseChange $change, bool $force): ?DatabaseChange
    {
        $database = $change->database;
        $table = (string) $change->table;
        $keys = (array) $change->keys;
        $current = $this->rawRow($database, $table, $keys);

        switch ($change->action) {
            case 'insert':
                if ($current === null) {
                    return null;
                }

                if (! $force && ($changed = $this->changedSince((array) $change->after, $current)) !== []) {
                    throw new RuntimeException('The added row was edited since ('.implode(', ', $changed).'). Undo anyway to delete it as it is now.');
                }

                $this->deleteRows($database, $table, [$keys]);

                return null;

            case 'update':
                if ($current === null) {
                    throw new RuntimeException('The row is not there any more, so the edit cannot be undone. Someone may have deleted it.');
                }

                $columns = $change->changedColumns();
                $after = (array) $change->after;
                $before = (array) $change->before;

                if (! $force && ($changed = $this->changedSince(array_intersect_key($after, array_flip($columns)), $current)) !== []) {
                    throw new RuntimeException('These values were changed again since: '.implode(', ', $changed).'. Undo anyway to write the old values over them.');
                }

                $this->updateRow($database, $table, $keys, array_intersect_key($before, array_flip($columns)));

                return null;

            case 'delete':
                if ($current !== null) {
                    throw new RuntimeException('A row with the same key is there again, so the deleted row cannot be put back.');
                }

                $this->insertRow($database, $table, (array) $change->before);

                return null;
        }

        throw new RuntimeException('This change cannot be undone.');
    }

    private function revertObject(DatabaseChange $change): ?DatabaseChange
    {
        $type = (string) $change->object_type;
        $name = (string) $change->object_name;

        return match ($change->action) {
            'drop' => tap(null, fn () => $this->saveObject($change->database, $type, (string) $change->sql_before)),
            'create' => tap(null, fn () => $this->dropObject($change->database, $type, $name)),
            'alter' => tap(null, fn () => $this->saveObject($change->database, $type, (string) $change->sql_before, $name)),
            default => throw new RuntimeException('This change cannot be undone.'),
        };
    }

    // Search

    /**
     * Search table names, column names, or the data itself, in some databases or all of them.
     *
     * @param  array{mode?: string, match?: string, column?: string, databases?: list<string>, limit?: int}  $options
     * @return array{results: list<array<string, mixed>>, searched: int, skipped: list<string>, truncated: bool}
     */
    public function search(string $term, array $options = []): array
    {
        $term = trim($term);
        $mode = $options['mode'] ?? 'data';
        $match = $options['match'] ?? 'contains';
        $limit = max(10, min(1000, (int) ($options['limit'] ?? 300)));
        $scope = array_values(array_filter((array) ($options['databases'] ?? []), fn ($name) => is_string($name) && self::validDatabase($name)));
        $scope = $scope !== [] ? $scope : array_column($this->databases(), 'name');

        if ($term === '' && $mode !== 'data') {
            return ['results' => [], 'searched' => 0, 'skipped' => [], 'truncated' => false];
        }

        if ($match === 'regex') {
            $this->assertRegex($term);
        }

        if ($mode === 'table' || $mode === 'column') {
            return $this->searchNames($mode, $term, $match, $scope, $limit);
        }

        return $this->searchData($term, $match, trim((string) ($options['column'] ?? '')), $scope, $limit);
    }

    /**
     * @param  list<string>  $scope
     * @return array{results: list<array<string, mixed>>, searched: int, skipped: list<string>, truncated: bool}
     */
    private function searchNames(string $mode, string $term, string $match, array $scope, int $limit): array
    {
        $placeholders = implode(', ', array_fill(0, count($scope), '?'));
        [$condition, $binding] = $this->condition($mode === 'table' ? 'TABLE_NAME' : 'COLUMN_NAME', $match, $term);

        $rows = $mode === 'table'
            ? $this->connection()->select("SELECT TABLE_SCHEMA AS db, TABLE_NAME AS tbl, TABLE_ROWS AS table_rows, DATA_LENGTH + INDEX_LENGTH AS size FROM information_schema.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA IN ({$placeholders}) AND {$condition} ORDER BY TABLE_SCHEMA, TABLE_NAME LIMIT ".($limit + 1), [...$scope, ...$binding])
            : $this->connection()->select("SELECT TABLE_SCHEMA AS db, TABLE_NAME AS tbl, COLUMN_NAME AS col, COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA IN ({$placeholders}) AND {$condition} ORDER BY TABLE_SCHEMA, TABLE_NAME, ORDINAL_POSITION LIMIT ".($limit + 1), [...$scope, ...$binding]);

        $results = array_map(fn (object $row): array => array_filter([
            'database' => $row->db,
            'table' => $row->tbl,
            'column' => $row->col ?? null,
            'type' => $row->type ?? null,
            'rows' => isset($row->table_rows) ? (int) $row->table_rows : null,
            'size' => isset($row->size) ? (int) $row->size : null,
        ], fn ($value) => $value !== null), array_slice($rows, 0, $limit));

        return ['results' => $results, 'searched' => count($scope), 'skipped' => [], 'truncated' => count($rows) > $limit];
    }

    /**
     * @param  list<string>  $scope
     * @return array{results: list<array<string, mixed>>, searched: int, skipped: list<string>, truncated: bool}
     */
    private function searchData(string $term, string $match, string $columnFilter, array $scope, int $limit): array
    {
        if ($term === '') {
            throw new RuntimeException('Type what to look for.');
        }

        $placeholders = implode(', ', array_fill(0, count($scope), '?'));
        $columns = $this->connection()->select(
            "SELECT c.TABLE_SCHEMA AS db, c.TABLE_NAME AS tbl, c.COLUMN_NAME AS col, c.DATA_TYPE AS data_type
             FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
             WHERE c.TABLE_SCHEMA IN ({$placeholders}) ORDER BY c.TABLE_SCHEMA, c.TABLE_NAME, c.ORDINAL_POSITION",
            $scope,
        );

        $searchable = ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set', 'json',
            'int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'float', 'double', 'date', 'datetime', 'timestamp', 'time', 'year'];
        $tables = [];

        foreach ($columns as $column) {
            if (! in_array(strtolower($column->data_type), $searchable, true)) {
                continue;
            }

            if ($columnFilter !== '' && ! str_contains(strtolower($column->col), strtolower($columnFilter))) {
                continue;
            }

            $tables[$column->db.'.'.$column->tbl][] = (string) $column->col;
        }

        $results = [];
        $skipped = [];
        $searched = 0;
        $started = microtime(true);

        foreach ($tables as $name => $tableColumns) {
            if (count($results) >= $limit || microtime(true) - $started > 25) {
                return ['results' => $results, 'searched' => $searched, 'skipped' => $skipped, 'truncated' => true];
            }

            [$database, $table] = explode('.', $name, 2);
            $searched++;

            try {
                $keys = $this->keyColumns($database, $table);
                $conditions = [];
                $bindings = [];

                foreach ($tableColumns as $column) {
                    [$condition, $binding] = $this->condition('CAST('.$this->quote($column).' AS CHAR)', $match, $term);
                    $conditions[] = $condition;
                    array_push($bindings, ...$binding);
                }

                $select = implode(', ', array_map($this->quote(...), array_values(array_unique([...$keys, ...$tableColumns]))));
                $rows = $this->connection()->select(
                    'SELECT /*+ MAX_EXECUTION_TIME(4000) */ '.$select.' FROM '.$this->qualified($database, $table).' WHERE '.implode(' OR ', $conditions).' LIMIT 20',
                    $bindings,
                );
            } catch (Throwable $exception) {
                $skipped[] = $name;

                continue;
            }

            foreach ($rows as $row) {
                $row = (array) $row;
                $found = [];

                foreach ($tableColumns as $column) {
                    if ($this->matches($row[$column] ?? null, $match, $term)) {
                        $found[$column] = $this->preview($row[$column]);
                    }
                }

                $results[] = [
                    'database' => $database,
                    'table' => $table,
                    'keys' => $keys === [] ? null : $this->keysOf($keys, $row),
                    'found' => $found,
                ];
            }
        }

        return ['results' => array_slice($results, 0, $limit), 'searched' => $searched, 'skipped' => $skipped, 'truncated' => count($results) > $limit];
    }

    // Helpers

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function condition(string $expression, string $match, string $term): array
    {
        return match ($match) {
            'equals' => [$expression.' = ?', [$term]],
            'regex' => [$expression.' REGEXP ?', [$term]],
            'starts' => [$expression.' LIKE ?', [$this->like($term).'%']],
            default => [$expression.' LIKE ?', ['%'.$this->like($term).'%']],
        };
    }

    private function matches(mixed $value, string $match, string $term): bool
    {
        if ($value === null) {
            return false;
        }

        $value = (string) $value;

        return match ($match) {
            'equals' => mb_strtolower($value) === mb_strtolower($term),
            'regex' => @preg_match('/'.str_replace('/', '\/', $term).'/iu', $value) === 1,
            'starts' => str_starts_with(mb_strtolower($value), mb_strtolower($term)),
            default => str_contains(mb_strtolower($value), mb_strtolower($term)),
        };
    }

    private function assertRegex(string $term): void
    {
        try {
            $this->connection()->select('SELECT ? REGEXP ? AS ok', ['x', $term]);
        } catch (Throwable $exception) {
            throw new RuntimeException('That regex is not valid for MySQL: '.$this->mysqlMessage($exception));
        }
    }

    /**
     * WHERE for the rows list: a search in every column, and filters on single columns.
     *
     * @param  list<string>  $columns
     * @param  list<array{column: string, operator: string, value?: ?string}>  $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(array $columns, string $search, array $filters): array
    {
        $parts = [];
        $bindings = [];
        $search = trim($search);

        if ($search !== '' && $columns !== []) {
            $parts[] = '('.implode(' OR ', array_map(fn (string $column): string => 'CAST('.$this->quote($column).' AS CHAR) LIKE ?', $columns)).')';
            array_push($bindings, ...array_fill(0, count($columns), '%'.$this->like($search).'%'));
        }

        foreach ($filters as $filter) {
            $column = (string) ($filter['column'] ?? '');
            $operator = (string) ($filter['operator'] ?? 'contains');
            $value = (string) ($filter['value'] ?? '');

            if (! in_array($column, $columns, true) || ! array_key_exists($operator, self::OPERATORS)) {
                continue;
            }

            if ($value === '' && ! in_array($operator, ['null', 'not_null', 'equals'], true)) {
                continue;
            }

            $quoted = $this->quote($column);

            match ($operator) {
                'null' => $parts[] = $quoted.' IS NULL',
                'not_null' => $parts[] = $quoted.' IS NOT NULL',
                'equals' => [$parts[] = $quoted.' = ?', $bindings[] = $value],
                'not_equals' => [$parts[] = '('.$quoted.' <> ? OR '.$quoted.' IS NULL)', $bindings[] = $value],
                'starts' => [$parts[] = 'CAST('.$quoted.' AS CHAR) LIKE ?', $bindings[] = $this->like($value).'%'],
                'ends' => [$parts[] = 'CAST('.$quoted.' AS CHAR) LIKE ?', $bindings[] = '%'.$this->like($value)],
                'regex' => [$parts[] = 'CAST('.$quoted.' AS CHAR) REGEXP ?', $bindings[] = $value],
                'gt' => [$parts[] = $quoted.' > ?', $bindings[] = $value],
                'lt' => [$parts[] = $quoted.' < ?', $bindings[] = $value],
                default => [$parts[] = 'CAST('.$quoted.' AS CHAR) LIKE ?', $bindings[] = '%'.$this->like($value).'%'],
            };
        }

        return [$parts === [] ? '' : ' WHERE '.implode(' AND ', $parts), $bindings];
    }

    /**
     * @param  array<string, mixed>  $keys
     * @return array{0: string, 1: list<mixed>}
     */
    private function match(array $keys): array
    {
        if ($keys === []) {
            throw new RuntimeException('This row has no key to find it by.');
        }

        $parts = [];
        $bindings = [];

        foreach ($keys as $column => $value) {
            if ($value === null) {
                $parts[] = $this->quote((string) $column).' IS NULL';
            } else {
                $parts[] = $this->quote((string) $column).' = ?';
                $bindings[] = $value;
            }
        }

        return [' WHERE '.implode(' AND ', $parts), $bindings];
    }

    /**
     * @param  array<string, mixed>  $keys
     * @return array<string, mixed>|null
     */
    private function rawRow(string $database, string $table, array $keys): ?array
    {
        $columns = array_column($this->columns($database, $table), 'name');
        $keys = array_intersect_key($keys, array_flip($columns));
        [$match, $bindings] = $this->match($keys);
        $row = $this->connection()->selectOne('SELECT * FROM '.$this->qualified($database, $table).$match.' LIMIT 1', $bindings);

        return $row === null ? null : (array) $row;
    }

    /**
     * @param  list<string>  $keyColumns
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function keysOf(array $keyColumns, array $row): array
    {
        $keys = [];

        foreach ($keyColumns as $column) {
            $keys[$column] = $row[$column] ?? null;
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function requireKeys(string $database, string $table): array
    {
        $keys = $this->keyColumns($database, $table);

        if ($keys === []) {
            throw new RuntimeException('This table has no primary or unique key, so its rows cannot be changed here safely. Use phpMyAdmin.');
        }

        return $keys;
    }

    /**
     * Only columns the table has. A value of null writes NULL.
     *
     * @param  list<array{name: string}>  $columns
     * @param  array<string, ?string>  $values
     * @return array<string, ?string>
     */
    private function known(array $columns, array $values): array
    {
        return array_intersect_key($values, array_flip(array_column($columns, 'name')));
    }

    private function differs(mixed $old, mixed $new): bool
    {
        if ($old === null || $new === null) {
            return $old !== $new;
        }

        return (string) $old !== (string) $new;
    }

    /**
     * Columns whose current value is no longer what the change left there.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    private function changedSince(array $expected, array $current): array
    {
        return array_values(array_filter(array_keys($expected), fn (string $column): bool => array_key_exists($column, $current) && $this->differs($expected[$column], $current[$column])));
    }

    /**
     * Values for the page: binary data is described, not shown; lists cut long text.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function readable(array $row, bool $preview = true): array
    {
        foreach ($row as $column => $value) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $row[$column] = '[binary, '.strlen($value).' bytes]';
            } elseif ($preview && is_string($value)) {
                $row[$column] = $this->preview($value);
            }
        }

        return $row;
    }

    private function preview(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            return '[binary, '.strlen($value).' bytes]';
        }

        return mb_strlen($value) > self::PREVIEW ? mb_substr($value, 0, self::PREVIEW).'…' : $value;
    }

    /**
     * @param  array<string, mixed>|null  $keys
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(string $database, ?string $table, string $action, ?array $keys, ?array $before, ?array $after, string $type = DatabaseChange::ROW, ?string $name = null, ?string $sqlBefore = null, ?string $sqlAfter = null, ?string $snapshot = null): DatabaseChange
    {
        $clean = fn (?array $row): ?array => $row === null ? null : array_map(fn ($value) => is_string($value) && ! mb_check_encoding($value, 'UTF-8') ? '[binary, '.strlen($value).' bytes]' : $value, $row);

        return DatabaseChange::query()->create([
            'user_id' => auth()->id(),
            'database' => $database,
            'table' => $table,
            'action' => $action,
            'object_type' => $type,
            'object_name' => $name,
            'keys' => $keys,
            'before' => $clean($before),
            'after' => $clean($after),
            'sql_before' => $sqlBefore,
            'sql_after' => $sqlAfter,
            'snapshot' => $snapshot,
        ]);
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function qualified(string $database, string $table): string
    {
        return $this->quote($database).'.'.$this->quote($table);
    }

    private function assertDatabase(string $database): void
    {
        if (! self::validDatabase($database)) {
            throw new RuntimeException('That is not a database this tool can open.');
        }
    }

    private function assertTable(string $database, string $table): void
    {
        $this->assertDatabase($database);

        if (! self::validTable($table)) {
            throw new RuntimeException('That is not a table name.');
        }
    }

    private function assertObject(string $database, string $type, string $name): void
    {
        $this->assertDatabase($database);

        if (! in_array($type, self::OBJECT_TYPES, true) || preg_match('/^[A-Za-z0-9_$]{1,64}$/', $name) !== 1) {
            throw new RuntimeException('That is not a trigger, event, procedure, or function name.');
        }
    }

    private function mysqlMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return preg_match('/\]:? (?:[^:]+: )?\d*\s*(.+?)(?: \(Connection:|$)/s', $message, $match) === 1 ? trim($match[1]) : Str::limit($message, 300);
    }
}
