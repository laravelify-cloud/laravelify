<?php

namespace App\Services;

use Throwable;

/**
 * The rows an UPDATE or DELETE of one table touches, for the SQL history: read with the
 * statement's own WHERE before it runs, and (for an UPDATE) read again by their keys after.
 * Statements it cannot read this way (joins, several tables, INSERT, DDL) are not captured.
 */
class SqlChangeCapture
{
    public const MAX_ROWS = 200;

    private const IDENTIFIER = '(?:[`"\[]?[A-Za-z0-9_$]+[`"\]]?)(?:\.(?:[`"\[]?[A-Za-z0-9_$]+[`"\]]?))?';

    /**
     * What the statement changes: the action, the table as written, and its WHERE (null for every row).
     *
     * @param  string  $bare  The statement with quoted text and comments blanked, the same length.
     * @return array{action: string, table: string, where: ?string}|null
     */
    public function parse(string $statement, string $bare): ?array
    {
        if (strlen($bare) !== strlen($statement)) {
            return null;
        }

        if (preg_match('/^\s*update\s+('.self::IDENTIFIER.')\s+set\s/i', $statement, $match)) {
            $action = 'update';
        } elseif (preg_match('/^\s*delete\s+from\s+('.self::IDENTIFIER.')(\s|$)/i', $statement, $match)) {
            $action = 'delete';
        } else {
            return null;
        }

        $where = $this->topLevel($bare, 'where');

        if ($where === null) {
            return ['action' => $action, 'table' => $match[1], 'where' => null];
        }

        $start = $where + 5;
        $end = strlen($bare);

        foreach (['order', 'limit', 'returning'] as $word) {
            $at = $this->topLevel($bare, $word, $start);

            if ($at !== null) {
                $end = min($end, $at);
            }
        }

        $condition = trim(substr($statement, $start, $end - $start));

        return $condition === '' ? null : ['action' => $action, 'table' => $match[1], 'where' => $condition];
    }

    /**
     * Run the statement with its changes captured.
     *
     * @param  callable(string, list<mixed>=): list<array<string, mixed>>  $select  Runs a SELECT (with bound values) and gives back its rows.
     * @param  callable(string): list<string>  $keysOf  The key columns of a table as written.
     * @param  callable(): mixed  $run  Runs the statement itself.
     * @param  callable(string): string  $quote  Quotes a column name for this database.
     * @return array{0: mixed, 1: array<string, mixed>|null} What $run gave back, and the captured change.
     */
    public function around(string $statement, string $bare, callable $select, callable $keysOf, callable $run, callable $quote, string $limit = 'LIMIT %d'): array
    {
        $plan = $this->parse($statement, $bare);
        $before = null;
        $keys = [];

        if ($plan) {
            try {
                $keys = $keysOf($plan['table']);
                $before = $select('SELECT * FROM '.$plan['table'].($plan['where'] !== null ? ' WHERE '.$plan['where'] : '').' '.sprintf($limit, self::MAX_ROWS + 1));
            } catch (Throwable) {
                $before = null;
            }
        }

        $result = $run();

        if ($plan === null || $before === null) {
            return [$result, null];
        }

        $truncated = count($before) > self::MAX_ROWS;
        $before = array_slice($before, 0, self::MAX_ROWS);
        $after = [];

        if ($plan['action'] === 'update' && $before !== [] && $keys !== []) {
            try {
                $after = $this->readAgain($plan['table'], $keys, $before, $select, $quote);
            } catch (Throwable) {
                $after = [];
            }
        }

        return [$result, [
            'table' => $plan['table'],
            'action' => $plan['action'],
            'keys' => $keys,
            'before' => $before,
            'after' => $after,
            'truncated' => $truncated,
        ]];
    }

    /**
     * The same rows after the update, found by their key values (as they were before).
     *
     * @param  list<string>  $keys
     * @param  list<array<string, mixed>>  $before
     * @return list<array<string, mixed>>
     */
    private function readAgain(string $table, array $keys, array $before, callable $select, callable $quote): array
    {
        $params = [];
        $conditions = array_map(function (array $row) use ($keys, $quote, &$params): string {
            return '('.implode(' AND ', array_map(function (string $key) use ($row, $quote, &$params): string {
                if (($row[$key] ?? null) === null) {
                    return $quote($key).' IS NULL';
                }

                $params[] = $row[$key];

                return $quote($key).' = ?';
            }, $keys)).')';
        }, $before);

        return $select('SELECT * FROM '.$table.' WHERE '.implode(' OR ', $conditions), $params);
    }

    /** Where a keyword stands outside brackets in the blanked statement, or null. */
    private function topLevel(string $bare, string $word, int $from = 0): ?int
    {
        $depth = 0;
        $length = strlen($bare);
        $size = strlen($word);

        for ($i = $from; $i < $length; $i++) {
            $char = $bare[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && strncasecmp(substr($bare, $i, $size), $word, $size) === 0
                && ($i === 0 || ! preg_match('/[A-Za-z0-9_$]/', $bare[$i - 1]))
                && ! preg_match('/[A-Za-z0-9_$]/', $bare[$i + $size] ?? ' ')) {
                return $i;
            }
        }

        return null;
    }
}
