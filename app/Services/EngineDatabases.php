<?php

namespace App\Services;

use App\Models\DatabaseConnection;
use App\Models\Website;
use App\Support\DatabaseEngines;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/**
 * The Database tool for the engines other than MySQL: PostgreSQL (the panel signs in as postgres),
 * SQLite (each website's file, read as the website's own user) and MongoDB (through mongosh as
 * MongoDB's admin). Lists databases and tables, pages through rows or documents, runs SQL, and
 * adds, changes or deletes one row or document at a time.
 */
class EngineDatabases
{
    public const PER_PAGE = 50;

    /** A database outside this server (App\Models\DatabaseConnection), by its slug. */
    public const REMOTE = 'remote';

    public const ENGINES = [DatabaseEngines::POSTGRESQL, DatabaseEngines::SQLITE, DatabaseEngines::MONGODB, self::REMOTE];

    private const MARK = '@@LFY@@';

    /** Runs statements on a SQLite file as the website's user and prints the results as JSON. */
    private const SQLITE_SCRIPT = <<<'PHP'
        $job = json_decode(stream_get_contents(STDIN), true);
        try {
            $pdo = new PDO('sqlite:'.$job['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $statement = $pdo->prepare($job['sql']);
            $statement->execute($job['params']);
            $columns = [];
            for ($i = 0; $i < $statement->columnCount(); $i++) { $columns[] = $statement->getColumnMeta($i)['name'] ?? ('column'.$i); }
            $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_NUM) : [];
            foreach ($rows as &$row) { foreach ($row as &$value) { if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) { $value = '[binary, '.strlen($value).' bytes]'; } } }
            echo '@@LFY@@'.json_encode(['columns' => $columns, 'rows' => $rows, 'affected' => $statement->rowCount()], JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            echo '@@LFY@@'.json_encode(['error' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
        }
        PHP;

    /** One mongosh call: the operation and its arguments come through the environment. */
    private const MONGO_SCRIPT = <<<'JS'
        const out = (value) => print('@@LFY@@' + EJSON.stringify(value, null, 0, { relaxed: true }));
        try {
            const admin = connect('mongodb://' + encodeURIComponent(process.env.LFY_ADMIN_USER) + ':' + encodeURIComponent(process.env.LFY_ADMIN) + '@127.0.0.1:27017/admin');
            const arg = JSON.parse(process.env.LFY_ARG || '{}');
            const op = process.env.LFY_OP;
            const d = op === 'databases' ? null : admin.getSiblingDB(arg.db);
            if (op === 'databases') {
                out(admin.adminCommand({ listDatabases: 1 }).databases.filter((x) => !['admin', 'config', 'local'].includes(x.name))
                    .map((x) => ({ name: x.name, size: x.sizeOnDisk, tables: admin.getSiblingDB(x.name).getCollectionNames().length })));
            } else if (op === 'collections') {
                out(d.getCollectionNames().sort().map((name) => {
                    const stats = d.runCommand({ collStats: name });
                    return { name, rows: d.getCollection(name).estimatedDocumentCount(), size: stats.size || 0 };
                }));
            } else if (op === 'find') {
                const filter = EJSON.parse(arg.filter || '{}');
                const c = d.getCollection(arg.collection);
                const docs = c.find(filter).sort({ _id: -1 }).skip(arg.skip).limit(arg.limit).toArray();
                out({ total: c.countDocuments(filter), rows: docs.map((doc) => ({ id: EJSON.stringify(doc._id, { relaxed: false }), json: EJSON.stringify(doc, null, 2, { relaxed: true }) })) });
            } else if (op === 'save') {
                const doc = EJSON.parse(arg.json);
                const c = d.getCollection(arg.collection);
                if (arg.id) {
                    const id = EJSON.parse(arg.id);
                    delete doc._id;
                    out({ changed: c.replaceOne({ _id: id }, doc).matchedCount });
                } else {
                    out({ id: EJSON.stringify(c.insertOne(doc).insertedId, { relaxed: false }) });
                }
            } else if (op === 'delete') {
                out({ deleted: d.getCollection(arg.collection).deleteOne({ _id: EJSON.parse(arg.id) }).deletedCount });
            }
        } catch (e) {
            out({ error: e.message });
        }
        JS;

    public function __construct(private CommandRunner $runner, private SiteFiles $files, private SiteUser $siteUser, private DatabaseProvisioner $provisioner) {}

    /**
     * The engines this server has and the panel can open, then outside connections.
     *
     * @return list<string>
     */
    public function engines(): array
    {
        return array_values(array_filter(self::ENGINES, fn (string $engine): bool => $engine !== self::REMOTE && DatabaseEngines::has($engine)));
    }

    /**
     * Every database of these engines and the outside connections, and what could not be read.
     *
     * @return array{databases: list<array{engine: string, name: string, tables: int|null, size: int, label?: string}>, errors: array<string, string>}
     */
    public function databases(): array
    {
        $databases = [];
        $errors = [];

        foreach ($this->engines() as $engine) {
            try {
                foreach ($this->databasesOf($engine) as $database) {
                    $databases[] = ['engine' => $engine] + $database;
                }
            } catch (Throwable $exception) {
                $errors[$engine] = $exception->getMessage();
            }
        }

        try {
            app(PluginManager::class)->syncConnections();
        } catch (Throwable) {
            // The plugins' connections are a convenience; the page lists the rest anyway.
        }

        foreach (DatabaseConnection::query()->orderBy('name')->get() as $connection) {
            $databases[] = ['engine' => self::REMOTE, 'name' => $connection->slug, 'label' => $connection->driverLabel().' · '.$connection->address(), 'color' => DatabaseConnection::COLORS[$connection->color] ?? null, 'title' => $connection->name, 'tables' => null, 'size' => 0];
        }

        return ['databases' => $databases, 'errors' => $errors];
    }

    /** What the page calls this database's engine: PostgreSQL, or SQL Server for a connection. */
    public function label(string $engine, string $database): string
    {
        return $engine === self::REMOTE ? $this->connection($database)->driverLabel() : DatabaseEngines::label($engine);
    }

    /**
     * Tables (collections for MongoDB) with their row counts and sizes.
     *
     * @return list<array{name: string, rows: int, size: int}>
     */
    public function tables(string $engine, string $database): array
    {
        if ($this->engine($engine) === DatabaseEngines::MONGODB) {
            return $this->mongo('collections', ['db' => $this->name($database)]);
        }

        return match ($this->dialect($engine, $database)) {
            'sqlite' => $this->sqliteTables($engine, $database),
            'mysql' => array_map(fn (array $row): array => ['name' => (string) $row[0], 'rows' => (int) $row[1], 'size' => (int) $row[2]], $this->query($engine, $database,
                "SELECT TABLE_NAME, COALESCE(TABLE_ROWS, 0), COALESCE(DATA_LENGTH + INDEX_LENGTH, 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")['rows']),
            'sqlsrv' => array_map(fn (array $row): array => ['name' => (string) $row[0], 'rows' => (int) $row[1], 'size' => (int) $row[2] * 1024], $this->query($engine, $database, <<<'SQL'
                SELECT CASE WHEN s.name = 'dbo' THEN t.name ELSE s.name + '.' + t.name END,
                       COALESCE(SUM(CASE WHEN p.index_id IN (0, 1) THEN p.rows END), 0),
                       COALESCE((SELECT SUM(a.total_pages) * 8 FROM sys.partitions p2 JOIN sys.allocation_units a ON a.container_id = p2.partition_id WHERE p2.object_id = t.object_id), 0)
                FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id LEFT JOIN sys.partitions p ON p.object_id = t.object_id
                GROUP BY s.name, t.name, t.object_id ORDER BY 1
                SQL)['rows']),
            default => array_map(fn (array $row): array => ['name' => $row[0], 'rows' => (int) $row[1], 'size' => (int) $row[2]], $this->query($engine, $database, <<<'SQL'
                SELECT CASE WHEN n.nspname = 'public' THEN c.relname ELSE n.nspname || '.' || c.relname END,
                       GREATEST(c.reltuples, 0)::bigint, pg_total_relation_size(c.oid)
                FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relkind IN ('r', 'p') AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_toast%'
                ORDER BY 1
                SQL)['rows']),
        };
    }

    /**
     * The columns of a table, the primary key marked.
     *
     * @return list<array{name: string, type: string, key: bool, nullable: bool}>
     */
    public function columns(string $engine, string $database, string $table): array
    {
        $dialect = $this->dialect($engine, $database);

        if ($dialect === 'sqlite') {
            return array_map(fn (array $row): array => ['name' => (string) $row[1], 'type' => (string) $row[2], 'key' => (int) $row[5] > 0, 'nullable' => ! (int) $row[3]],
                $this->query($engine, $database, 'PRAGMA table_info('.$this->quote($dialect, $table).')')['rows']);
        }

        if ($dialect === 'mysql') {
            return array_map(fn (array $row): array => ['name' => (string) $row[0], 'type' => (string) $row[1], 'key' => $row[2] === 'PRI', 'nullable' => $row[3] === 'YES'], $this->query($engine, $database,
                'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_KEY, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table])['rows']);
        }

        [$schema, $name] = $this->schemaAndTable($dialect, $table);
        $keyCheck = <<<'SQL'
            (SELECT COUNT(*) FROM information_schema.table_constraints t JOIN information_schema.key_column_usage k
             ON k.constraint_name = t.constraint_name AND k.table_schema = t.table_schema AND k.table_name = t.table_name
             WHERE t.constraint_type = 'PRIMARY KEY' AND t.table_schema = c.table_schema AND t.table_name = c.table_name AND k.column_name = c.column_name)
            SQL;

        return array_map(fn (array $row): array => ['name' => (string) $row[0], 'type' => (string) $row[1], 'key' => (int) $row[2] > 0, 'nullable' => $row[3] === 'YES'], $this->query($engine, $database,
            'SELECT c.column_name, c.data_type, '.$keyCheck.', c.is_nullable FROM information_schema.columns c WHERE c.table_schema = ? AND c.table_name = ? ORDER BY c.ordinal_position', [$schema, $name])['rows']);
    }

    /**
     * Rows for the grid: a slice, sorted, searched in every column, and filtered per column.
     *
     * @param  array{offset?: int, limit?: int, page?: int, sort?: string|null, direction?: string, search?: string, filters?: list<array<string, mixed>>}  $options
     * @return array{columns: list<array{name: string, type: string, key: bool, nullable: bool}>, rows: list<array<string, mixed>>, total: int}
     */
    public function rows(string $engine, string $database, string $table, array $options = []): array
    {
        $dialect = $this->dialect($engine, $database);
        $columns = $this->columns($engine, $database, $table);

        if ($columns === []) {
            throw new RuntimeException('There is no table '.$table.'.');
        }

        $limit = max(1, min(500, (int) ($options['limit'] ?? self::PER_PAGE)));
        $offset = max(0, (int) ($options['offset'] ?? ((max(1, (int) ($options['page'] ?? 1)) - 1) * $limit)));
        [$where, $params] = $this->where($dialect, $columns, (string) ($options['search'] ?? ''), (array) ($options['filters'] ?? []));
        $names = array_column($columns, 'name');
        $sort = in_array($options['sort'] ?? null, $names, true) ? (string) $options['sort'] : null;
        $direction = ($options['direction'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $keys = array_column(array_filter($columns, fn (array $column): bool => $column['key']), 'name');
        $order = $sort !== null
            ? ' ORDER BY '.$this->quote($dialect, $sort).' '.$direction
            : ($keys !== [] ? ' ORDER BY '.implode(', ', array_map(fn (string $key): string => $this->quote($dialect, $key).' DESC', $keys)) : ($dialect === 'sqlsrv' ? ' ORDER BY (SELECT NULL)' : ''));
        $from = ' FROM '.$this->qualified($dialect, $table);
        $total = (int) ($this->query($engine, $database, 'SELECT COUNT(*)'.$from.$where, $params)['rows'][0][0] ?? 0);
        $page = $dialect === 'sqlsrv' ? " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY" : " LIMIT {$limit} OFFSET {$offset}";
        $result = $this->query($engine, $database, 'SELECT *'.$from.$where.$order.$page, $params);

        return [
            'columns' => $columns,
            'rows' => array_map(fn (array $row): array => array_combine($result['columns'], $row), $result['rows']),
            'total' => $total,
        ];
    }

    /**
     * The most common values of one column (with the grid's search and filters), for the header
     * filter's list.
     *
     * @param  list<array<string, mixed>>  $filters
     * @return array{values: list<array{value: mixed, count: int}>, more: bool}
     */
    public function distinctValues(string $engine, string $database, string $table, string $column, string $search = '', array $filters = [], string $find = '', int $limit = 200): array
    {
        $dialect = $this->dialect($engine, $database);
        $columns = $this->columns($engine, $database, $table);

        if (! in_array($column, array_column($columns, 'name'), true)) {
            throw new RuntimeException('There is no column '.$column.'.');
        }

        $filters = array_values(array_filter($filters, fn (array $filter): bool => ($filter['column'] ?? '') !== $column));
        [$where, $params] = $this->where($dialect, $columns, $search, $filters);

        if (trim($find) !== '') {
            $where .= ($where === '' ? ' WHERE ' : ' AND ').$this->like($dialect, $this->quote($dialect, $column));
            $params[] = '%'.addcslashes(trim($find), '%_\\').'%';
        }

        $quoted = $this->quote($dialect, $column);
        $top = $dialect === 'sqlsrv' ? 'TOP '.($limit + 1).' ' : '';
        $tail = $dialect === 'sqlsrv' ? '' : ' LIMIT '.($limit + 1);
        $rows = $this->query($engine, $database, 'SELECT '.$top.$quoted.', COUNT(*) FROM '.$this->qualified($dialect, $table).$where.' GROUP BY '.$quoted.' ORDER BY COUNT(*) DESC'.$tail, $params)['rows'];

        return [
            'values' => array_map(fn (array $row): array => ['value' => $row[0], 'count' => (int) $row[1]], array_slice($rows, 0, $limit)),
            'more' => count($rows) > $limit,
        ];
    }

    /**
     * One row by its primary key.
     *
     * @param  array<string, mixed>  $keys
     * @return array<string, mixed>|null
     */
    public function row(string $engine, string $database, string $table, array $keys): ?array
    {
        $dialect = $this->dialect($engine, $database);
        [$where, $params] = $this->keyCondition($engine, $database, $table, $keys);
        $result = $this->query($engine, $database, 'SELECT *'.' FROM '.$this->qualified($dialect, $table).$where, $params);

        return isset($result['rows'][0]) ? array_combine($result['columns'], $result['rows'][0]) : null;
    }

    /**
     * Add a row ($keys null) or change the row with these primary key values.
     *
     * @param  array<string, mixed>|null  $keys
     * @param  array<string, string|null>  $values
     */
    public function saveRow(string $engine, string $database, string $table, ?array $keys, array $values): void
    {
        $this->assertWritable($engine, $database);
        $dialect = $this->dialect($engine, $database);
        $known = array_column($this->columns($engine, $database, $table), 'name');
        $values = array_intersect_key($values, array_flip($known));

        if ($values === []) {
            throw new RuntimeException('Nothing to save.');
        }

        if ($keys === null) {
            $this->query($engine, $database, 'INSERT INTO '.$this->qualified($dialect, $table).' ('.implode(', ', array_map(fn (string $column): string => $this->quote($dialect, $column), array_keys($values))).') VALUES ('.implode(', ', array_fill(0, count($values), '?')).')', array_values($values));

            return;
        }

        [$where, $params] = $this->keyCondition($engine, $database, $table, $keys);
        $set = implode(', ', array_map(fn (string $column): string => $this->quote($dialect, $column).' = ?', array_keys($values)));
        $result = $this->query($engine, $database, 'UPDATE '.$this->qualified($dialect, $table).' SET '.$set.$where, [...array_values($values), ...$params]);

        if ($result['affected'] === 0 && $dialect !== 'mysql') {
            throw new RuntimeException('That row is gone: someone deleted or changed its key.');
        }
    }

    /**
     * @param  array<string, mixed>  $keys
     */
    public function deleteRow(string $engine, string $database, string $table, array $keys): void
    {
        $this->assertWritable($engine, $database);
        [$where, $params] = $this->keyCondition($engine, $database, $table, $keys);
        $this->query($engine, $database, 'DELETE FROM '.$this->qualified($this->dialect($engine, $database), $table).$where, $params);
    }

    /**
     * Run SQL typed by the person, one statement.
     *
     * @return array{columns: list<string>, rows: list<list<mixed>>, affected: int}
     */
    public function run(string $engine, string $database, string $sql): array
    {
        if ($this->engine($engine) === DatabaseEngines::MONGODB) {
            throw new RuntimeException('MongoDB has no SQL. Use the filter on a collection.');
        }

        $sql = trim(rtrim(trim($sql), ';'));

        if ($sql === '') {
            throw new RuntimeException('Type a statement to run.');
        }

        if ($engine === self::REMOTE && $this->connection($database)->read_only && preg_match('/^\s*(select|with|show|explain|describe|desc|values|table)\b/i', $sql) !== 1) {
            throw new RuntimeException('This connection is read-only: only SELECT, WITH, SHOW and EXPLAIN run here. Change it in the connection\'s Options.');
        }

        $dialect = $this->dialect($engine, $database);

        if ($dialect === 'sqlsrv') {
            $result = $this->query($engine, $database, $sql);
            $result['changes'] = null;
        } else {
            // For the SQL history: the rows an UPDATE or DELETE of one table touches, before and after.
            [$result, $changes] = app(SqlChangeCapture::class)->around(
                $sql,
                app(DatabaseSql::class)->bare($sql),
                function (string $select, array $params = []) use ($engine, $database): array {
                    $read = $this->query($engine, $database, $select, $params);

                    return array_map(fn (array $row): array => array_combine($read['columns'], $row), $read['rows']);
                },
                fn (string $table): array => array_column(array_filter(
                    $this->columns($engine, $database, str_replace(['"', '`'], '', $table)),
                    fn (array $column): bool => $column['key'],
                ), 'name'),
                fn () => $this->query($engine, $database, $sql),
                fn (string $column): string => $this->quote($dialect, $column),
            );
            $result['changes'] = $changes;
        }

        $result['rows'] = array_slice($result['rows'], 0, 1000);

        return $result;
    }

    /**
     * Connect to an outside database with these settings and say what it is, without saving.
     *
     * @return string The server's version.
     */
    public function test(DatabaseConnection $connection): string
    {
        if ($connection->driver === 'sqlite') {
            $tables = $this->sqliteFile((string) $connection->database, "SELECT sqlite_version(), (SELECT COUNT(*) FROM sqlite_master WHERE type = 'table')", [], true)['rows'][0] ?? [];

            return 'SQLite '.($tables[0] ?? '?').', '.($tables[1] ?? 0).' tables';
        }

        $pdo = $this->remotePdo($connection);
        $version = match ($connection->driver) {
            'sqlsrv' => "SELECT CAST(SERVERPROPERTY('ProductVersion') AS NVARCHAR(64)) + ' ' + CAST(SERVERPROPERTY('Edition') AS NVARCHAR(128))",
            default => 'SELECT version()',
        };

        return (string) $pdo->query($version)->fetchColumn();
    }

    /**
     * One page of documents matching a filter (MongoDB's JSON query, like {"status": "active"}).
     *
     * @return array{rows: list<array{id: string, json: string}>, total: int}
     */
    public function documents(string $database, string $collection, int $page = 1, string $filter = ''): array
    {
        $filter = trim($filter) === '' ? '{}' : trim($filter);

        if (! is_array(json_decode($filter, true)) && $filter !== '{}') {
            throw new RuntimeException('The filter must be JSON, like {"status": "active"}.');
        }

        return $this->mongo('find', ['db' => $this->name($database), 'collection' => $collection, 'filter' => $filter, 'skip' => (max(1, $page) - 1) * self::PER_PAGE, 'limit' => self::PER_PAGE]);
    }

    /** Add a document ($id null) or replace the one with this _id (Extended JSON, as documents() gives it). */
    public function saveDocument(string $database, string $collection, ?string $id, string $json): void
    {
        if (! is_array(json_decode($json, true))) {
            throw new RuntimeException('The document must be a JSON object.');
        }

        $result = $this->mongo('save', ['db' => $this->name($database), 'collection' => $collection, 'id' => $id, 'json' => $json]);

        if ($id !== null && ($result['changed'] ?? 0) === 0) {
            throw new RuntimeException('That document is gone.');
        }
    }

    public function deleteDocument(string $database, string $collection, string $id): void
    {
        $this->mongo('delete', ['db' => $this->name($database), 'collection' => $collection, 'id' => $id]);
    }

    public static function validEngine(string $engine): bool
    {
        return in_array($engine, self::ENGINES, true);
    }

    /** Which SQL this database speaks: pgsql, sqlite, mysql or sqlsrv. */
    /**
     * Every table and its columns in one query, for the SQL editor's autocomplete.
     *
     * @return array<string, list<string>>
     */
    public function schema(string $engine, string $database): array
    {
        $dialect = $this->dialect($engine, $database);
        $sql = match ($dialect) {
            'sqlite' => "SELECT m.name, p.name FROM sqlite_master m JOIN pragma_table_info(m.name) p WHERE m.type IN ('table', 'view') AND m.name NOT LIKE 'sqlite_%' ORDER BY m.name, p.cid",
            'mysql' => 'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION',
            'sqlsrv' => "SELECT CASE WHEN TABLE_SCHEMA = 'dbo' THEN TABLE_NAME ELSE TABLE_SCHEMA + '.' + TABLE_NAME END, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS ORDER BY 1, ORDINAL_POSITION",
            default => "SELECT CASE WHEN table_schema = 'public' THEN table_name ELSE table_schema || '.' || table_name END, column_name FROM information_schema.columns WHERE table_schema NOT IN ('pg_catalog', 'information_schema') ORDER BY 1, ordinal_position",
        };
        $schema = [];

        foreach (array_slice($this->query($engine, $database, $sql)['rows'], 0, 20000) as $row) {
            $schema[(string) $row[0]][] = (string) $row[1];
        }

        return $schema;
    }

    public function dialect(string $engine, string $database): string
    {
        return match ($this->engine($engine)) {
            DatabaseEngines::SQLITE => 'sqlite',
            DatabaseEngines::POSTGRESQL => 'pgsql',
            self::REMOTE => $this->connection($database)->driver,  // sqlsrv, mysql, pgsql or sqlite
            default => throw new RuntimeException('MongoDB has no tables.'),
        };
    }

    /**
     * The WHERE of the grid: text in any column, and each column's filter.
     *
     * @param  list<array{name: string}>  $columns
     * @param  list<array<string, mixed>>  $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(string $dialect, array $columns, string $search, array $filters): array
    {
        $parts = [];
        $params = [];
        $names = array_column($columns, 'name');

        if (trim($search) !== '') {
            $parts[] = '('.implode(' OR ', array_map(fn (string $name): string => $this->like($dialect, $this->quote($dialect, $name)), $names)).')';
            array_push($params, ...array_fill(0, count($names), '%'.addcslashes(trim($search), '%_\\').'%'));
        }

        foreach ($filters as $filter) {
            $column = (string) ($filter['column'] ?? '');

            if (! in_array($column, $names, true)) {
                continue;
            }

            $quoted = $this->quote($dialect, $column);
            $value = (string) ($filter['value'] ?? '');
            $operator = (string) ($filter['operator'] ?? 'contains');

            if ($operator === 'in') {
                $values = array_values(array_map('strval', (array) ($filter['values'] ?? [])));
                $any = [];

                if ($values !== []) {
                    $any[] = $this->text($dialect, $quoted).' IN ('.implode(', ', array_fill(0, count($values), '?')).')';
                    array_push($params, ...$values);
                }

                if ($filter['blanks'] ?? false) {
                    $any[] = $quoted.' IS NULL';
                }

                $parts[] = $any === [] ? '1 = 0' : '('.implode(' OR ', $any).')';

                continue;
            }

            [$sql, $bound] = match ($operator) {
                'equals' => [$this->text($dialect, $quoted).' = ?', [$value]],
                'not_equals' => ['('.$this->text($dialect, $quoted).' <> ? OR '.$quoted.' IS NULL)', [$value]],
                'starts' => [$this->like($dialect, $quoted), [addcslashes($value, '%_\\').'%']],
                'ends' => [$this->like($dialect, $quoted), ['%'.addcslashes($value, '%_\\')]],
                'gt' => [$quoted.' > ?', [$value]],
                'lt' => [$quoted.' < ?', [$value]],
                'null' => [$quoted.' IS NULL', []],
                'not_null' => [$quoted.' IS NOT NULL', []],
                'regex' => match ($dialect) {
                    'pgsql' => [$this->text($dialect, $quoted).' ~* ?', [$value]],
                    'mysql' => [$quoted.' REGEXP ?', [$value]],
                    default => throw new RuntimeException('Patterns are not supported here: use Contains.'),
                },
                default => [$this->like($dialect, $quoted), ['%'.addcslashes($value, '%_\\').'%']],
            };
            $parts[] = $sql;
            array_push($params, ...$bound);
        }

        return [$parts === [] ? '' : ' WHERE '.implode(' AND ', $parts), $params];
    }

    private function text(string $dialect, string $quoted): string
    {
        return match ($dialect) {
            'pgsql' => $quoted.'::text',
            'sqlite' => 'CAST('.$quoted.' AS TEXT)',
            'sqlsrv' => 'CAST('.$quoted.' AS NVARCHAR(MAX))',
            default => 'CAST('.$quoted.' AS CHAR)',
        };
    }

    private function like(string $dialect, string $quoted): string
    {
        return $this->text($dialect, $quoted).($dialect === 'pgsql' ? ' ILIKE ?' : ' LIKE ?').($dialect === 'sqlsrv' || $dialect === 'sqlite' ? " ESCAPE '\\'" : '');
    }

    /**
     * The tables of a SQLite file with their row counts, in two queries (each can be a process
     * run as the website's user).
     *
     * @return list<array{name: string, rows: int, size: int}>
     */
    private function sqliteTables(string $engine, string $database): array
    {
        $names = array_column($this->query($engine, $database, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")['rows'], 0);

        if ($names === []) {
            return [];
        }

        $counts = $this->query($engine, $database, implode(' UNION ALL ', array_map(fn (string $name): string => "SELECT '".str_replace("'", "''", $name)."', COUNT(*) FROM ".$this->quote('sqlite', $name), $names)))['rows'];
        $byName = array_combine(array_column($counts, 0), array_column($counts, 1));

        return array_map(fn (string $name): array => ['name' => $name, 'rows' => (int) ($byName[$name] ?? 0), 'size' => 0], $names);
    }

    /** What a dump of each engine is: a gzipped SQL script, the SQLite file itself, or a mongodump archive. */
    public const DUMP_EXTENSIONS = [
        DatabaseEngines::POSTGRESQL => 'sql.gz',
        DatabaseEngines::SQLITE => 'sqlite.gz',
        DatabaseEngines::MONGODB => 'archive.gz',
    ];

    /** The name a backup keeps for a database of another engine, like "postgresql:shop". */
    public static function backupTarget(string $engine, string $database): string
    {
        return $engine.':'.$database;
    }

    /**
     * The engine and database of a backup target name, or null for a MySQL one.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseBackupTarget(string $target): ?array
    {
        if (preg_match('/^(postgresql|sqlite|mongodb):([A-Za-z0-9_-]{1,64})$/', $target, $match) !== 1) {
            return null;
        }

        return [$match[1], $match[2]];
    }

    /**
     * Write a gzipped dump of the database to $file: pg_dump for PostgreSQL, a copy of the file
     * (read as the website's user) for SQLite, and a mongodump archive for MongoDB. Passwords go
     * through the environment or a private file, never the command line.
     */
    public function dump(string $engine, string $database, string $file): void
    {
        $engine = $this->engine($engine);
        $database = $this->name($database);

        if ($engine === DatabaseEngines::SQLITE) {
            $website = Website::query()->where('slug', $database)->where('db_engine', DatabaseEngines::SQLITE)->first()
                ?? throw new RuntimeException('There is no SQLite database '.$database.'.');
            $result = $this->files->execToFile(['gzip', '-c', $this->provisioner->sqlitePath($website)], $file, null, $this->siteUser->for($website));
        } elseif ($engine === DatabaseEngines::POSTGRESQL) {
            $result = $this->runner->run(
                ['bash', '-c', 'set -o pipefail; pg_dump -h 127.0.0.1 -p 5432 -U "$PGUSER" --no-owner --no-privileges "$1" | gzip -c > "$2"', 'dump', $database, $file],
                null,
                ['PGUSER' => (string) config('laravelify.postgres_user', 'postgres'), 'PGPASSWORD' => (string) config('laravelify.postgres_password')],
            );
        } elseif ($engine === DatabaseEngines::MONGODB) {
            $config = storage_path('app/mongodump-'.bin2hex(random_bytes(8)).'.yml');
            file_put_contents($config, 'password: '.json_encode((string) config('laravelify.mongodb_password'))."\n");
            @chmod($config, 0600);

            try {
                $result = $this->runner->run(['mongodump', '--quiet', '--host=127.0.0.1', '--port=27017', '--username=admin', '--authenticationDatabase=admin', '--config='.$config, '--db='.$database, '--gzip', '--archive='.$file]);
            } finally {
                @unlink($config);
            }
        } else {
            throw new RuntimeException('This database cannot be dumped here.');
        }

        if (! $result->ok || ! is_file($file) || filesize($file) === 0) {
            @unlink($file);

            throw new RuntimeException(DatabaseEngines::label($engine).' dump failed: '.($result->skipped ? 'this server cannot run it.' : mb_substr(trim($result->output) ?: 'no output', 0, 400)));
        }
    }

    /**
     * @return list<array{name: string, tables: int|null, size: int}>
     */
    private function databasesOf(string $engine): array
    {
        if ($engine === DatabaseEngines::MONGODB) {
            return $this->mongo('databases');
        }

        if ($engine === DatabaseEngines::SQLITE) {
            return Website::query()->where('db_engine', DatabaseEngines::SQLITE)->orderBy('slug')->get()
                ->map(fn (Website $website): array => ['name' => (string) $website->slug, 'tables' => null, 'size' => 0])->all();
        }

        return array_map(fn (array $row): array => ['name' => (string) $row[0], 'tables' => null, 'size' => (int) $row[1]],
            $this->query($engine, 'postgres', "SELECT datname, pg_database_size(datname) FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres' ORDER BY datname")['rows']);
    }

    /**
     * @param  list<mixed>  $params
     * @return array{columns: list<string>, rows: list<list<mixed>>, affected: int}
     */
    private function query(string $engine, string $database, string $sql, array $params = []): array
    {
        $engine = $this->engine($engine);

        if ($engine === self::REMOTE) {
            $connection = $this->connection($database);

            if ($connection->driver === 'sqlite') {
                return $this->sqliteFile((string) $connection->database, $sql, $params, $connection->read_only);
            }

            try {
                return $this->statement($this->remotePdo($connection), $sql, $params);
            } catch (Throwable $exception) {
                throw new RuntimeException($connection->driverLabel().': '.$exception->getMessage(), 0, $exception);
            }
        }

        if ($engine === DatabaseEngines::SQLITE) {
            $website = Website::query()->where('slug', $database)->where('db_engine', DatabaseEngines::SQLITE)->first()
                ?? throw new RuntimeException('There is no SQLite database '.$database.'.');

            return $this->sqliteFile($this->provisioner->sqlitePath($website), $sql, $params, false, $this->siteUser->for($website));
        }

        $password = (string) config('laravelify.postgres_password');

        if ($password === '' || ! extension_loaded('pdo_pgsql')) {
            throw new RuntimeException('The panel cannot sign in to PostgreSQL on this server (no password or no pdo_pgsql).');
        }

        try {
            $pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname='.$this->name($database), (string) config('laravelify.postgres_user', 'postgres'), $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            return $this->statement($pdo, $sql, $params);
        } catch (Throwable $exception) {
            throw new RuntimeException('PostgreSQL: '.$exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Run SQL on a SQLite file: directly when the panel can read it, otherwise as the user that
     * owns the file (a website's own user).
     *
     * @param  list<mixed>  $params
     * @return array{columns: list<string>, rows: list<list<mixed>>, affected: int}
     */
    private function sqliteFile(string $path, string $sql, array $params, bool $readOnly = false, ?SiteUser $owner = null): array
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('No SQLite file is set.');
        }

        if (is_readable($path) && ($readOnly || is_writable($path))) {
            try {
                $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + ($readOnly && defined('Pdo\\Sqlite::OPEN_READONLY') ? [constant('Pdo\\Sqlite::ATTR_OPEN_FLAGS') => constant('Pdo\\Sqlite::OPEN_READONLY')] : []));

                return $this->statement($pdo, $sql, $params);
            } catch (Throwable $exception) {
                throw new RuntimeException('SQLite: '.$exception->getMessage(), 0, $exception);
            }
        }

        $result = $this->files->exec(['php', '-r', self::SQLITE_SCRIPT], json_encode(['path' => $path, 'sql' => $sql, 'params' => $params]), null, $owner ?? $this->siteUser->forPath($path));

        return $this->decode($result->output, 'SQLite');
    }

    /** A PDO for an outside database, failing quickly when it cannot be reached. */
    private function remotePdo(DatabaseConnection $connection): PDO
    {
        $driver = DatabaseConnection::DRIVERS[$connection->driver] ?? throw new RuntimeException('Unknown database type.');

        if (! extension_loaded($driver['extension'])) {
            throw new RuntimeException('The panel\'s PHP has no '.$driver['extension'].'. Turn it on under a website\'s PHP → PHP extensions for the panel\'s PHP version'.($connection->driver === 'sqlsrv' ? ' (sqlsrv and pdo_sqlsrv, with Microsoft\'s ODBC driver)' : '').'.');
        }

        $host = $connection->host;
        $port = (int) $connection->port;
        $name = $connection->database;
        $timeout = max(1, min(120, (int) ($connection->timeout ?: 8)));
        $ssl = $connection->ssl_mode ?: 'prefer';
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

        if ($connection->driver === 'sqlsrv') {
            // A named instance (SQLEXPRESS) is found through SQL Browser unless a port is given.
            $server = $host.($connection->instance ? '\\'.$connection->instance : '').($port > 0 ? ','.$port : '');
            $dsn = "sqlsrv:Server={$server};Database={$name};LoginTimeout={$timeout};Encrypt=".($connection->encrypt ? 'yes' : 'no').';TrustServerCertificate='.($connection->trust_certificate ? 'yes' : 'no');

            if ($connection->read_only) {
                $dsn .= ';ApplicationIntent=ReadOnly';
            }
        } elseif ($connection->driver === 'mysql') {
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $options[PDO::ATTR_TIMEOUT] = $timeout;

            if ($ssl !== 'disable' && defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[constant('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')] = $ssl === 'verify-full';
            }
        } else {
            $dsn = "pgsql:host={$host};port={$port};dbname={$name};connect_timeout={$timeout};sslmode={$ssl}";
        }

        $pdo = new PDO($dsn, $connection->username, (string) $connection->password, $options);

        if ($connection->read_only && $connection->driver !== 'sqlsrv') {
            $pdo->exec($connection->driver === 'mysql' ? 'SET SESSION TRANSACTION READ ONLY' : 'SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
        }

        return $pdo;
    }

    /** Names a new database or schema may have: letters, digits and underscores, not starting with a digit. */
    public const NEW_NAME = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    /**
     * Where a new database can be made: this server's MySQL and PostgreSQL, and the server of each
     * saved MySQL or PostgreSQL connection (one entry per server and login).
     *
     * @return array<string, string> key ("local:mysql", "local:pgsql" or a connection slug) => label
     */
    public function databaseServers(): array
    {
        $servers = [];

        if (DatabaseEngines::has(DatabaseEngines::MYSQL)) {
            $servers['local:mysql'] = 'This server\'s MySQL';
        }

        if (DatabaseEngines::has(DatabaseEngines::POSTGRESQL)) {
            $servers['local:pgsql'] = 'This server\'s PostgreSQL';
        }

        $seen = [];

        foreach (DatabaseConnection::query()->whereIn('driver', ['mysql', 'pgsql'])->where('read_only', false)->orderBy('name')->get() as $connection) {
            $server = $connection->driver.'|'.$connection->host.'|'.$connection->port.'|'.$connection->username;

            if (! isset($seen[$server])) {
                $seen[$server] = true;
                $servers[$connection->slug] = $connection->name.' · '.$connection->driverLabel().' · '.$connection->address();
            }
        }

        return $servers;
    }

    /**
     * Make an empty database. On a connection's server a new saved connection opens it (same
     * login, the new database), so it shows on the Database page.
     *
     * @return array{engine: string, database: string} Where the new database opens.
     */
    public function createDatabase(string $server, string $name): array
    {
        if (preg_match(self::NEW_NAME, $name) !== 1) {
            throw new RuntimeException('Use letters, digits and underscores (not starting with a digit), up to 63.');
        }

        if ($server === 'local:mysql') {
            if (in_array(strtolower($name), ['mysql', 'sys', 'information_schema', 'performance_schema'], true)) {
                throw new RuntimeException('That name belongs to MySQL itself.');
            }

            app(DatabaseBrowser::class)->connection()->statement('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

            return ['engine' => DatabaseEngines::MYSQL, 'database' => $name];
        }

        if ($server === 'local:pgsql') {
            $this->query(DatabaseEngines::POSTGRESQL, 'postgres', 'CREATE DATABASE "'.$name.'" ENCODING \'UTF8\'');

            return ['engine' => DatabaseEngines::POSTGRESQL, 'database' => $name];
        }

        $connection = $this->connection($server);

        if (! in_array($connection->driver, ['mysql', 'pgsql'], true)) {
            throw new RuntimeException('New databases can be made on MySQL and PostgreSQL connections.');
        }

        $this->assertWritable(self::REMOTE, $connection->slug);
        $this->query(self::REMOTE, $connection->slug, $connection->driver === 'mysql'
            ? 'CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            : 'CREATE DATABASE "'.$name.'" ENCODING \'UTF8\'');

        $copy = $connection->replicate(['slug', 'name', 'database']);
        $copy->forceFill([
            'name' => $connection->name.' / '.$name,
            'database' => $name,
            'slug' => $this->freeSlug(Str::slug($connection->slug.'-'.$name) ?: 'connection'),
            'password' => $connection->password,
        ])->save();

        return ['engine' => self::REMOTE, 'database' => $copy->slug];
    }

    /**
     * The schemas of a PostgreSQL or SQL Server database, with how many tables each has.
     *
     * @return list<array{name: string, tables: int}>
     */
    public function schemas(string $engine, string $database): array
    {
        $sql = match ($this->dialect($engine, $database)) {
            'pgsql' => "SELECT n.nspname, COUNT(c.oid) FROM pg_namespace n LEFT JOIN pg_class c ON c.relnamespace = n.oid AND c.relkind IN ('r', 'p')
                        WHERE n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg\_%' GROUP BY n.nspname ORDER BY n.nspname = 'public' DESC, n.nspname",
            'sqlsrv' => "SELECT s.name, COUNT(t.object_id) FROM sys.schemas s LEFT JOIN sys.tables t ON t.schema_id = s.schema_id
                         WHERE s.name = 'dbo' OR s.schema_id BETWEEN 5 AND 16383 GROUP BY s.name ORDER BY CASE WHEN s.name = 'dbo' THEN 0 ELSE 1 END, s.name",
            default => null,
        };

        if ($sql === null) {
            return [];
        }

        return array_map(fn (array $row): array => ['name' => (string) $row[0], 'tables' => (int) $row[1]], $this->query($engine, $database, $sql)['rows']);
    }

    /** Make an empty schema in a PostgreSQL or SQL Server database. */
    public function createSchema(string $engine, string $database, string $name): void
    {
        if (preg_match(self::NEW_NAME, $name) !== 1) {
            throw new RuntimeException('Use letters, digits and underscores (not starting with a digit), up to 63.');
        }

        if (str_starts_with(strtolower($name), 'pg_')) {
            throw new RuntimeException('Names starting with pg_ belong to PostgreSQL.');
        }

        $this->assertWritable($engine, $database);

        $sql = match ($this->dialect($engine, $database)) {
            'pgsql' => 'CREATE SCHEMA "'.$name.'"',
            'sqlsrv' => 'EXEC(\'CREATE SCHEMA ['.$name.']\')',
            default => throw new RuntimeException('Only PostgreSQL and SQL Server databases have schemas.'),
        };

        $this->query($engine, $database, $sql);
    }

    private function freeSlug(string $base): string
    {
        $slug = $base;

        for ($i = 2; DatabaseConnection::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /** Whether rows can be changed here: not on a connection set to read-only. */
    public function writable(string $engine, string $database): bool
    {
        return $engine !== self::REMOTE || ! $this->connection($database)->read_only;
    }

    private function assertWritable(string $engine, string $database): void
    {
        if (! $this->writable($engine, $database)) {
            throw new RuntimeException('This connection is read-only. Change it in the connection\'s Options.');
        }
    }

    private function connection(string $slug): DatabaseConnection
    {
        return DatabaseConnection::query()->where('slug', $slug)->first() ?? throw new RuntimeException('There is no connection '.$slug.'.');
    }

    /**
     * @param  list<mixed>  $params
     * @return array{columns: list<string>, rows: list<list<mixed>>, affected: int}
     */
    private function statement(PDO $pdo, string $sql, array $params): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $columns = [];

        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $columns[] = (string) ($statement->getColumnMeta($i)['name'] ?? 'column'.$i);
        }

        $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_NUM) : [];

        foreach ($rows as &$row) {
            foreach ($row as &$value) {
                if (is_resource($value)) {
                    $value = stream_get_contents($value);
                }

                if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                    $value = '[binary, '.strlen($value).' bytes]';
                }
            }
        }

        return ['columns' => $columns, 'rows' => $rows, 'affected' => $statement->rowCount()];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function mongo(string $operation, array $arguments = []): array
    {
        $password = (string) config('laravelify.mongodb_password');

        if ($password === '') {
            throw new RuntimeException('The panel cannot sign in to MongoDB on this server (no admin password).');
        }

        $result = $this->runner->run(['mongosh', '--quiet', '--nodb', '--eval', self::MONGO_SCRIPT], null, [
            'LFY_ADMIN_USER' => (string) config('laravelify.mongodb_user', 'admin'),
            'LFY_ADMIN' => $password,
            'LFY_OP' => $operation,
            'LFY_ARG' => json_encode($arguments),
        ]);

        return $this->decode($result->output, 'MongoDB');
    }

    private function decode(string $output, string $label): array
    {
        $at = strrpos($output, self::MARK);
        $data = $at === false ? null : json_decode(substr($output, $at + strlen(self::MARK)), true);

        if (! is_array($data)) {
            throw new RuntimeException($label.': '.(trim($output) !== '' ? mb_substr(trim($output), 0, 300) : 'no answer.'));
        }

        if (isset($data['error']) && is_string($data['error'])) {
            throw new RuntimeException($label.': '.$data['error']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $keys
     * @return array{0: string, 1: list<mixed>}
     */
    private function keyCondition(string $engine, string $database, string $table, array $keys): array
    {
        $dialect = $this->dialect($engine, $database);
        $keyColumns = array_column(array_filter($this->columns($engine, $database, $table), fn (array $column): bool => $column['key']), 'name');

        if ($keyColumns === [] || array_diff($keyColumns, array_keys($keys)) !== []) {
            throw new RuntimeException('This table has no primary key, so a row cannot be picked out safely. Use the SQL tab.');
        }

        return [
            ' WHERE '.implode(' AND ', array_map(fn (string $column): string => $this->quote($dialect, $column).' = ?', $keyColumns)),
            array_map(fn (string $column): mixed => $keys[$column], $keyColumns),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function schemaAndTable(string $dialect, string $table): array
    {
        return str_contains($table, '.') ? explode('.', $table, 2) : [$dialect === 'sqlsrv' ? 'dbo' : 'public', $table];
    }

    private function qualified(string $dialect, string $table): string
    {
        if ($dialect === 'sqlite' || $dialect === 'mysql') {
            return $this->quote($dialect, $table);
        }

        [$schema, $name] = $this->schemaAndTable($dialect, $table);

        return $this->quote($dialect, $schema).'.'.$this->quote($dialect, $name);
    }

    private function quote(string $dialect, string $identifier): string
    {
        return match ($dialect) {
            'mysql' => '`'.str_replace('`', '``', $identifier).'`',
            'sqlsrv' => '['.str_replace(']', ']]', $identifier).']',
            default => '"'.str_replace('"', '""', $identifier).'"',
        };
    }

    private function engine(string $engine): string
    {
        if ($engine === self::REMOTE) {
            return $engine;
        }

        if (! self::validEngine($engine) || ! DatabaseEngines::has($engine)) {
            throw new RuntimeException('This server has no '.DatabaseEngines::label($engine).'.');
        }

        return $engine;
    }

    private function name(string $database): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $database) !== 1) {
            throw new RuntimeException('Not a database name.');
        }

        return $database;
    }
}
