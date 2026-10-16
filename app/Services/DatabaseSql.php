<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * The SQL box on a database page. Statements run on this server's MySQL, in that database.
 * What is typed here is not written to the change log.
 */
class DatabaseSql
{
    public const MAX_ROWS = 200;

    public const MAX_STATEMENTS = 20;

    /**
     * Statements split on semicolons that are outside quotes and comments.
     *
     * @return list<string>
     */
    public function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote === null && $this->startsComment($sql, $i)) {
                $end = $this->commentEnd($sql, $i);
                $current .= substr($sql, $i, $end - $i);
                $i = $end - 1;

                continue;
            }

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    if ($next === $quote) {
                        $current .= $next;
                        $i++;

                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;

                continue;
            }

            if ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }

                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    /**
     * Why a statement must not run, or null when it may.
     */
    public function rejection(string $statement): ?string
    {
        $bare = $this->bare($statement);

        if (preg_match('/\binto\s+(outfile|dumpfile)\b/i', $bare)) {
            return 'Writing a file on the server is not allowed.';
        }

        if (preg_match('/\bload\s+data\b/i', $bare) || preg_match('/\bload_file\s*\(/i', $bare)) {
            return 'Reading a file on the server is not allowed.';
        }

        if (preg_match('/(?<![A-Za-z0-9_$-])use(?![A-Za-z0-9_$-])/i', $bare)) {
            return 'Stay in this database. Open the other one to run SQL there.';
        }

        foreach (DatabaseDumper::hiddenDatabases() as $name) {
            $quoted = preg_quote($name, '/');

            if (preg_match('/`'.$quoted.'`|(?<![A-Za-z0-9_$-])'.$quoted.'(?![A-Za-z0-9_$-])/i', $bare)) {
                return 'That database is not available here.';
            }
        }

        return null;
    }

    /**
     * Run the statements. The first error stops the rest; earlier results are kept.
     *
     * @return array{results: list<array<string, mixed>>, error: ?string}
     */
    public function run(string $database, string $sql): array
    {
        if (! DatabaseBrowser::validDatabase($database)) {
            return ['results' => [], 'error' => 'That database is not available here.'];
        }

        if (strlen($sql) > 100_000) {
            return ['results' => [], 'error' => 'That is too long to run here.'];
        }

        $statements = $this->split($sql);

        if ($statements === []) {
            return ['results' => [], 'error' => 'Type a statement.'];
        }

        if (count($statements) > self::MAX_STATEMENTS) {
            return ['results' => [], 'error' => 'Run up to '.self::MAX_STATEMENTS.' statements at a time.'];
        }

        $connection = app(DatabaseBrowser::class)->connection();

        try {
            $connection->statement('USE `'.$database.'`');
        } catch (QueryException $exception) {
            $previous = $exception->getPrevious();

            return ['results' => [], 'error' => $previous ? $previous->getMessage() : $exception->getMessage()];
        }

        $results = [];

        foreach ($statements as $statement) {
            if ($reason = $this->rejection($statement)) {
                return ['results' => $results, 'error' => $reason];
            }

            try {
                $results[] = $this->execute($connection, $statement);
            } catch (QueryException $exception) {
                $previous = $exception->getPrevious();

                return ['results' => $results, 'error' => $previous ? $previous->getMessage() : $exception->getMessage()];
            }
        }

        return ['results' => $results, 'error' => null];
    }

    /**
     * The rows on screen, as INSERT statements.
     *
     * @param  list<string>  $columns
     * @param  list<array<string, ?string>>  $rows
     */
    public function inserts(string $table, array $columns, array $rows): string
    {
        if (! DatabaseBrowser::validTable($table)) {
            $table = 'rows';
        }

        $names = array_map(fn (string $column): string => '`'.str_replace('`', '``', $column).'`', $columns);
        $lines = [];

        foreach ($rows as $row) {
            $values = array_map(fn (string $column): string => $this->quote($row[$column] ?? null), $columns);
            $lines[] = 'INSERT INTO `'.$table.'` ('.implode(', ', $names).') VALUES ('.implode(', ', $values).');';
        }

        return implode("\n", $lines).(count($lines) > 0 ? "\n" : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function execute(Connection $connection, string $statement): array
    {
        $kind = $this->kind($statement);

        if ($kind === 'write') {
            $affected = $connection->affectingStatement($statement);

            return [
                'kind' => 'write',
                'sql' => $statement,
                'affected' => $affected,
                'message' => $affected === 0 ? 'Done.' : $affected.' '.($affected === 1 ? 'row' : 'rows').' changed.',
            ];
        }

        $query = $statement;

        if ($kind === 'select' && ! preg_match('/\blimit\b/i', $this->bare($statement))) {
            $query = rtrim($statement)."\nLIMIT ".(self::MAX_ROWS + 1);
        }

        $rows = array_map(function (object $row): array {
            $cells = [];

            foreach ((array) $row as $column => $value) {
                $cells[(string) $column] = $value === null ? null : $this->text((string) $value);
            }

            return $cells;
        }, $connection->select($query));

        $truncated = count($rows) > self::MAX_ROWS;

        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }

        return [
            'kind' => 'rows',
            'sql' => $statement,
            'columns' => array_keys($rows[0] ?? []),
            'rows' => $rows,
            'truncated' => $truncated,
            'table' => $this->guessTable($statement),
        ];
    }

    private function kind(string $statement): string
    {
        $bare = ltrim($this->bare($statement));

        if (preg_match('/^(select|with|table)\b/i', $bare)) {
            return 'select';
        }

        if (preg_match('/^(show|describe|desc|explain)\b/i', $bare)) {
            return 'meta';
        }

        return 'write';
    }

    private function guessTable(string $statement): string
    {
        $bare = $this->bare($statement);

        if (preg_match('/\bfrom\s+`([A-Za-z0-9_$-]+)`/i', $bare, $match) && DatabaseBrowser::validTable($match[1])) {
            return $match[1];
        }

        if (preg_match('/\bfrom\s+([A-Za-z0-9_$-]+)/i', $bare, $match) && DatabaseBrowser::validTable($match[1])) {
            return $match[1];
        }

        return 'rows';
    }

    private function text(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
    }

    private function quote(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return "'".str_replace(['\\', "'", "\0", "\n", "\r"], ['\\\\', "\\'", '\\0', '\\n', '\\r'], $value)."'";
    }

    /**
     * The statement with quotes and comments replaced by spaces, so a check sees the SQL itself.
     */
    private function bare(string $sql): string
    {
        $out = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote === null && $this->startsComment($sql, $i)) {
                $end = $this->commentEnd($sql, $i);
                $out .= str_repeat(' ', $end - $i);
                $i = $end - 1;

                continue;
            }

            if ($quote !== null) {
                $out .= ' ';

                if ($char === '\\') {
                    $out .= ' ';
                    $i++;

                    continue;
                }

                if ($char === $quote && $next !== $quote) {
                    $quote = null;
                } elseif ($char === $quote && $next === $quote) {
                    $out .= ' ';
                    $i++;
                }

                continue;
            }

            // Backticks stay: they name tables and databases, which the checks have to see.
            if ($char === "'" || $char === '"') {
                $quote = $char;
                $out .= ' ';

                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    private function startsComment(string $sql, int $i): bool
    {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($char === '#') {
            return true;
        }

        if ($char === '/' && $next === '*') {
            return true;
        }

        if ($char === '-' && $next === '-') {
            $after = $sql[$i + 2] ?? "\n";

            return $after === ' ' || $after === "\t" || $after === "\n" || $after === "\r";
        }

        return false;
    }

    private function commentEnd(string $sql, int $i): int
    {
        if ($sql[$i] === '/' && ($sql[$i + 1] ?? '') === '*') {
            $end = strpos($sql, '*/', $i + 2);

            return $end === false ? strlen($sql) : $end + 2;
        }

        $end = strpos($sql, "\n", $i);

        return $end === false ? strlen($sql) : $end;
    }
}
