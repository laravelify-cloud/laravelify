<?php

namespace App\Services;

use RuntimeException;

/**
 * MySQL dumps and restores through the same login the panel uses to create site databases.
 * Names go to the shell as arguments, never as part of a command string.
 */
class DatabaseDumper
{
    /**
     * Databases MySQL owns, and phpMyAdmin's own settings database. They are never listed or
     * backed up: MySQL and the installer recreate them.
     */
    public const SYSTEM = ['information_schema', 'performance_schema', 'mysql', 'sys', 'phpmyadmin'];

    public function __construct(private CommandRunner $runner, private DatabaseProvisioner $provisioner) {}

    public static function validName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) === 1;
    }

    /**
     * @return list<string>
     */
    public function databases(): array
    {
        $lines = $this->query('SHOW DATABASES');

        return array_values(array_filter($lines, fn (string $name) => ! in_array($name, self::SYSTEM, true) && self::validName($name)));
    }

    /**
     * @return list<string>
     */
    public function tables(string $database): array
    {
        $this->assertName($database);

        return $this->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"', $database, true);
    }

    /**
     * @param  list<string>  $skipTables  Tables of this database to leave out (not even their structure).
     */
    public function dump(string $database, string $file, array $skipTables = []): void
    {
        $this->assertName($database);
        $ignore = array_map(fn (string $table): string => '--ignore-table='.$database.'.'.$table, array_values(array_filter(
            $skipTables,
            fn (string $table): bool => preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $table) === 1,
        )));

        $result = $this->withDefaults(fn (string $defaults) => $this->runner->run([
            'bash', '-c',
            'set -o pipefail; mysqldump --defaults-extra-file="$1" --single-transaction --quick --routines --triggers --events --no-tablespaces --default-character-set=utf8mb4 "${@:4}" "$2" | gzip -c > "$3"',
            '_', $defaults, $database, $file, ...$ignore,
        ]));

        if ($result->skipped) {
            touch($file);

            return;
        }

        if (! $result->ok) {
            @unlink($file);
            throw new RuntimeException('mysqldump failed: '.$result->output);
        }
    }

    /**
     * Dump only some tables of a database (structure and rows), for a copy before a risky change.
     *
     * @param  list<string>  $tables
     */
    public function dumpTables(string $database, array $tables, string $file): void
    {
        $this->assertName($database);
        $tables = array_values(array_filter($tables, fn (string $table): bool => preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $table) === 1));

        if ($tables === []) {
            throw new RuntimeException('Choose at least one table.');
        }

        $result = $this->withDefaults(fn (string $defaults) => $this->runner->run([
            'bash', '-c',
            'set -o pipefail; mysqldump --defaults-extra-file="$1" --single-transaction --quick --no-tablespaces --default-character-set=utf8mb4 "$2" "${@:4}" | gzip -c > "$3"',
            '_', $defaults, $database, $file, ...$tables,
        ]));

        if ($result->skipped) {
            touch($file);

            return;
        }

        if (! $result->ok) {
            @unlink($file);
            throw new RuntimeException('mysqldump failed: '.$result->output);
        }
    }

    /**
     * Load a whole dump into a database (created when it does not exist), or only some tables.
     *
     * @param  list<string>|null  $tables
     */
    public function restore(string $file, string $database, ?array $tables = null): void
    {
        $this->assertName($database);
        $source = $file;
        $filtered = null;

        if ($tables !== null) {
            $filtered = $file.'.tables.sql';
            $found = $this->extractTables($file, $tables, $filtered);
            $missing = array_diff($tables, $found);

            if ($missing !== []) {
                @unlink($filtered);
                throw new RuntimeException('Not in this backup: '.implode(', ', $missing));
            }

            $source = $filtered;
        }

        try {
            $this->withDefaults(function (string $defaults) use ($database, $source, $tables) {
                $create = $this->runner->run([
                    'mysql', '--defaults-extra-file='.$defaults, '--protocol=socket',
                    '-e', 'CREATE DATABASE IF NOT EXISTS `'.$database.'`',
                ]);

                if (! $create->ok) {
                    throw new RuntimeException('Could not create the database: '.$create->output);
                }

                $reader = $tables === null ? 'gzip -dc "$3"' : 'cat "$3"';
                $load = $this->runner->run([
                    'bash', '-c',
                    'set -o pipefail; '.$reader.' | mysql --defaults-extra-file="$1" --protocol=socket --default-character-set=utf8mb4 "$2"',
                    '_', $defaults, $database, $source,
                ]);

                if (! $load->ok) {
                    throw new RuntimeException('mysql could not load the dump: '.$load->output);
                }
            });
        } finally {
            if ($filtered !== null) {
                @unlink($filtered);
            }
        }
    }

    /**
     * Copy the header and the chosen tables of a gzipped mysqldump into a plain SQL file.
     * mysqldump writes "-- Table structure for table `x`" before each table, with its
     * rows and triggers after it, so a table is everything up to the next marker.
     *
     * @param  list<string>  $tables
     * @return list<string> The tables that were found.
     */
    public function extractTables(string $gzFile, array $tables, string $outFile): array
    {
        $input = gzopen($gzFile, 'rb');
        $output = fopen($outFile, 'wb');

        if ($input === false || $output === false) {
            throw new RuntimeException('Could not read the backup file.');
        }

        $wanted = array_flip($tables);
        $found = [];
        $inHeader = true;
        $current = null;
        $atLineStart = true;

        while (($chunk = gzgets($input, 65536)) !== false) {
            if ($atLineStart) {
                if (preg_match('/^-- Table structure for table `(.+)`\s*$/', $chunk, $match)) {
                    $inHeader = false;
                    $current = $match[1];

                    if (isset($wanted[$current])) {
                        $found[$current] = true;
                    }
                } elseif (! $inHeader && preg_match('/^-- (Dumping (events|routines) for database|Temporary view structure|Final view structure)/', $chunk)) {
                    $current = null;
                }
            }

            if ($inHeader || ($current !== null && isset($wanted[$current]))) {
                fwrite($output, $chunk);
            }

            $atLineStart = str_ends_with($chunk, "\n");
        }

        gzclose($input);
        fclose($output);

        return array_keys($found);
    }

    private function assertName(string $name): void
    {
        if (! self::validName($name)) {
            throw new RuntimeException('That database name is not safe to use.');
        }
    }

    /**
     * @return list<string>
     */
    private function query(string $sql, ?string $database = null, bool $firstColumn = false): array
    {
        $result = $this->withDefaults(fn (string $defaults) => $this->runner->run(array_filter([
            'mysql', '--defaults-extra-file='.$defaults, '--protocol=socket', '-N', '-B',
            $database,
            '-e', $sql,
        ], fn ($part) => $part !== null)));

        if (! $result->ok) {
            throw new RuntimeException('MySQL said: '.$result->output);
        }

        if ($result->skipped) {
            return [];
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $result->output))));

        return $firstColumn ? array_map(fn (string $line) => explode("\t", $line)[0], $lines) : $lines;
    }

    private function withDefaults(callable $callback): mixed
    {
        $defaults = $this->provisioner->clientDefaultsFile();

        try {
            return $callback($defaults);
        } finally {
            @unlink($defaults);
        }
    }
}
