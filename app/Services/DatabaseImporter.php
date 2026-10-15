<?php

namespace App\Services;

use App\Models\Website;
use RuntimeException;

/**
 * Imports a .sql or .sql.gz dump that is in a website's folder into the MySQL database the panel
 * made for that website. It signs in as the website's own database user, so a dump can only change
 * that one database, whatever it contains.
 */
class DatabaseImporter
{
    public const MAX_BYTES = 2 * 1024 * 1024 * 1024;

    public function __construct(private CommandRunner $runner, private SitePaths $paths) {}

    /**
     * Check what is asked before anything runs.
     *
     * @return array{file: string, gzip: bool, relative: string}
     *
     * @throws RuntimeException With a message to show the user.
     */
    public function prepare(Website $website, string $relative): array
    {
        if (! $website->isPhp() || ! $website->provision_database || blank($website->db_name) || blank($website->db_username) || blank($website->db_password)) {
            throw new RuntimeException($website->name.' has no database made by the panel. Create the website with create_database, or add one on its page.');
        }

        $relative = trim(str_replace('\\', '/', $relative), '/');

        if (preg_match('/^[A-Za-z0-9_.\/-]+\.(sql|sql\.gz)$/i', $relative) !== 1) {
            throw new RuntimeException('Give a .sql or .sql.gz file inside the site folder (letters, numbers, - _ . / only). Put the dump there first: upload-file for small ones, or scp to the server.');
        }

        try {
            $file = $this->paths->resolveIn($this->paths->root($website), $relative);
        } catch (RuntimeException) {
            throw new RuntimeException($relative.' was not found in the site folder. Put the dump there first (upload-file, or scp to the server).');
        }

        if (is_link($file) || ! is_file($file)) {
            throw new RuntimeException($relative.' is not a file in the site folder.');
        }

        if (filesize($file) > self::MAX_BYTES) {
            throw new RuntimeException('That dump is larger than 2 GB.');
        }

        return ['file' => $file, 'gzip' => str_ends_with(strtolower($file), '.gz'), 'relative' => $relative];
    }

    /**
     * Run the import. With $reset the database is dropped and made again first (needed when the
     * site's migrations already made tables the dump makes too).
     *
     * @param  (callable(int, string): void)|null  $progress
     * @return string What was done, for the task's result.
     *
     * @throws RuntimeException With the first MySQL error, including the line of the dump.
     */
    public function import(Website $website, string $relative, bool $reset, ?callable $progress = null): string
    {
        $input = $this->prepare($website, $relative);
        $database = (string) $website->db_name;
        $defaults = $this->defaultsFile($website);
        $progress && $progress(5, 'Starting');

        try {
            $base = ['mysql', '--defaults-extra-file='.$defaults, '--default-character-set=utf8mb4', '--init-command=SET FOREIGN_KEY_CHECKS=0, UNIQUE_CHECKS=0'];

            if ($reset) {
                $progress && $progress(10, 'Emptying '.$database);
                $drop = $this->runner->run([...$base, '-e', "DROP DATABASE IF EXISTS `{$database}`; CREATE DATABASE `{$database}`"]);

                if (! $drop->ok) {
                    throw new RuntimeException('Could not empty the database: '.$this->firstLines($drop->output));
                }
            }

            $progress && $progress(20, 'Importing '.$input['relative']);

            $result = $input['gzip']
                ? $this->runner->run(['bash', '-c', 'set -o pipefail; gunzip -c "$1" | mysql --defaults-extra-file="$2" --default-character-set=utf8mb4 --init-command="SET FOREIGN_KEY_CHECKS=0, UNIQUE_CHECKS=0" "$3"', 'bash', $input['file'], $defaults, $database])
                : $this->runner->run([...$base, $database, '-e', 'source '.$input['file']]);

            if ($result->skipped) {
                return 'Host commands are disabled on this machine, so nothing was imported.';
            }

            if (! $result->ok) {
                throw new RuntimeException('MySQL stopped the import: '.$this->firstLines($result->output)
                    .($reset ? '' : ' If a table already exists, run the import again with reset true to empty the database first.'));
            }
        } finally {
            @unlink($defaults);
        }

        $progress && $progress(100, 'Done');

        return 'Imported '.$input['relative'].' into '.$database.($reset ? ' (the database was emptied first)' : '').'.';
    }

    private function defaultsFile(Website $website): string
    {
        $path = storage_path('app/mysql-site-'.bin2hex(random_bytes(8)).'.cnf');
        $quoted = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $website->db_password);

        file_put_contents($path, "[client]\nuser={$website->db_username}\npassword=\"{$quoted}\"\nhost=127.0.0.1\nport=3306\nprotocol=tcp\n");
        @chmod($path, 0600);

        return $path;
    }

    private function firstLines(string $output): string
    {
        return implode(' ', array_slice(array_filter(array_map('trim', explode("\n", $output))), 0, 3)) ?: 'no details';
    }
}
