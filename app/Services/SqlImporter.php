<?php

namespace App\Services;

use RuntimeException;

/**
 * Loads a .sql or .sql.gz dump into a MySQL database from the Database tool, as fast as the mysql
 * client itself goes: the file is read and unzipped in pieces and streamed into one mysql
 * process, with foreign key and unique checks off and one commit at the end (the way DataGrip and
 * mysqldump restores run). A 1 GB dump takes a few minutes. Progress is how much of the file was
 * read, so it is real for .sql.gz too.
 */
class SqlImporter
{
    /** Read 4 MB of the file at a time. */
    private const PIECE = 4 * 1024 * 1024;

    public function __construct(private DatabaseProvisioner $provisioner) {}

    /**
     * @param  (callable(int, string): void)|null  $progress  percent and a line saying where it is
     *
     * @throws RuntimeException With MySQL's first error (it names the line of the dump).
     */
    public function import(string $database, string $file, bool $reset = false, ?callable $progress = null, ?string $name = null): string
    {
        if (! DatabaseBrowser::validDatabase($database)) {
            throw new RuntimeException('That database cannot be imported into from here.');
        }

        if (! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException('The dump file is gone. Upload it again.');
        }

        $total = max(1, (int) filesize($file));
        $name ??= basename($file);
        $gzip = $this->isGzip($file);
        $defaults = $this->provisioner->clientDefaultsFile();
        $started = microtime(true);
        $progress && $progress(1, 'Reading '.$name.'...');

        try {
            if ($reset) {
                $progress && $progress(2, 'Emptying '.$database.'...');
                $this->run($defaults, null, "DROP DATABASE IF EXISTS `{$database}`; CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            } else {
                $this->run($defaults, null, "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            }

            $this->stream($defaults, $database, $file, $gzip, $total, $started, $progress);
        } finally {
            @unlink($defaults);
        }

        $seconds = (int) round(microtime(true) - $started);
        $progress && $progress(100, 'Imported in '.$this->duration($seconds).'.');

        return 'Imported '.$name.' ('.$this->size($total).') into '.$database.' in '.$this->duration($seconds).'.'.($reset ? ' The database was emptied first.' : '');
    }

    /**
     * @param  (callable(int, string): void)|null  $progress
     */
    private function stream(string $defaults, string $database, string $file, bool $gzip, int $total, float $started, ?callable $progress): void
    {
        $process = proc_open(
            ['mysql', '--defaults-extra-file='.$defaults, '--protocol=socket', '--default-character-set=utf8mb4', '--max-allowed-packet=1G', '--net-buffer-length=1M', $database],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('The mysql client could not be started.');
        }

        stream_set_blocking($pipes[2], false);
        $errors = '';
        $input = fopen($file, 'rb');
        $inflate = $gzip ? inflate_init(ZLIB_ENCODING_GZIP) : null;
        $lastReport = 0.0;

        try {
            // Checks off while the rows go in, and one transaction: the slow parts of a restore.
            $this->write($pipes[0], "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET AUTOCOMMIT=0;\n");

            while (! feof($input)) {
                $piece = fread($input, self::PIECE);

                if ($piece === false) {
                    throw new RuntimeException('The dump file could not be read.');
                }

                if ($inflate !== null) {
                    $piece = inflate_add($inflate, $piece, ZLIB_SYNC_FLUSH);

                    if ($piece === false) {
                        throw new RuntimeException('The .gz file is damaged: it could not be unzipped.');
                    }
                }

                if (! $this->write($pipes[0], $piece)) {
                    break;
                }

                $errors .= (string) stream_get_contents($pipes[2]);
                $now = microtime(true);

                if ($progress && $now - $lastReport >= 1) {
                    $lastReport = $now;
                    $read = (int) ftell($input);
                    $percent = 3 + (int) floor($read / $total * 95);
                    $rate = $read / max(0.1, $now - $started);
                    $left = $rate > 0 ? (int) (($total - $read) / $rate) : 0;
                    $progress(min(98, $percent), 'Imported '.$this->size($read).' of '.$this->size($total).' · about '.$this->duration($left).' left');
                }
            }

            $this->write($pipes[0], "\nCOMMIT;\nSET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($input);
            @fclose($pipes[0]);
        }

        stream_set_blocking($pipes[2], true);
        $errors .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            throw new RuntimeException('MySQL stopped the import: '.$this->firstLines($errors ?: 'exit code '.$code));
        }
    }

    /** Write all of $data; false when mysql has stopped reading (it hit an error). */
    private function write(mixed $pipe, string $data): bool
    {
        while ($data !== '') {
            $written = @fwrite($pipe, $data);

            if ($written === false || $written === 0) {
                return false;
            }

            $data = substr($data, $written);
        }

        return true;
    }

    private function run(string $defaults, ?string $database, string $sql): void
    {
        $process = proc_open(
            array_values(array_filter(['mysql', '--defaults-extra-file='.$defaults, '--protocol=socket', '-e', $sql, $database])),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new RuntimeException('MySQL: '.$this->firstLines((string) $output));
        }
    }

    private function isGzip(string $file): bool
    {
        $handle = fopen($file, 'rb');
        $magic = (string) fread($handle, 2);
        fclose($handle);

        return $magic === "\x1f\x8b";
    }

    private function firstLines(string $output): string
    {
        return implode(' ', array_slice(array_filter(array_map('trim', explode("\n", $output))), 0, 3)) ?: 'no details';
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1073741824 ? round($bytes / 1073741824, 2).' GB' : round($bytes / 1048576, 1).' MB';
    }

    private function duration(int $seconds): string
    {
        return $seconds >= 60 ? intdiv($seconds, 60).' min '.($seconds % 60).' s' : $seconds.' s';
    }
}
