<?php

namespace App\Services;

use App\Models\BackupRun;
use App\Models\PanelTask;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What is inside a backup, and parts of it as a download.
 *
 * A database backup is read from its mysqldump: every table (with an approximate row count, its
 * size, and its CREATE TABLE), stored procedures, functions, triggers, and events. A file backup
 * is read from its archive listing. The result (the manifest) is kept next to the panel, so
 * the page shows it without downloading the backup again.
 */
class BackupInspector
{
    public const PARTS = [
        'tables' => 'Tables (structure and data)',
        'procedures' => 'Stored procedures',
        'functions' => 'Stored functions',
        'triggers' => 'Triggers',
        'events' => 'Events',
    ];

    public function __construct(private BackupStorageClient $storage, private CommandRunner $runner) {}

    public function manifestPath(BackupRun $run): string
    {
        $directory = storage_path('app/backup-manifests');
        File::ensureDirectoryExists($directory);

        return $directory.DIRECTORY_SEPARATOR.$run->id.'.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function manifest(BackupRun $run): ?array
    {
        $path = $this->manifestPath($run);

        if (! is_file($path)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * Read the local copy of a backup that was just made, before it is removed.
     */
    public function remember(BackupRun $run, string $file): void
    {
        $manifest = $run->isDatabase() ? $this->readDump($file) : $this->readArchive($file);
        file_put_contents($this->manifestPath($run), json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function forget(BackupRun $run): void
    {
        @unlink($this->manifestPath($run));
    }

    /**
     * Queue job: download a backup and read it, for backups made before manifests existed.
     *
     * @return array<string, mixed>
     */
    public function inspectTask(PanelTask $task, callable $progress): array
    {
        $run = $this->runFor($task);
        $file = $this->download($run, $progress);

        try {
            $this->remember($run, $file);
        } finally {
            @unlink($file);
        }

        return ['message' => 'Read the backup of '.$run->target.'.'];
    }

    /**
     * Queue job: build the download the person asked for.
     *
     * @return array<string, mixed>
     */
    public function exportTask(PanelTask $task, callable $progress): array
    {
        $run = $this->runFor($task);
        $payload = $task->payload ?? [];
        $file = $this->download($run, $progress);
        $stamp = ($run->created_at ?? now())->format('Ymd-His');
        $slug = Str::slug($run->target, '_') ?: 'backup';

        try {
            if (! empty($payload['original']) || ! $run->isDatabase()) {
                $extension = $run->isDatabase() ? 'sql.gz' : 'tar.gz';
                $name = 'task-'.$task->id.'-'.Str::random(10).'.'.$extension;
                rename($file, PanelTask::directory().DIRECTORY_SEPARATOR.$name);

                return ['file' => $name, 'download_name' => $slug.'-'.$stamp.'.'.$extension, 'message' => 'The backup file is ready to download.'];
            }

            $parts = array_values(array_intersect(array_keys(self::PARTS), (array) ($payload['parts'] ?? [])));

            if ($parts === []) {
                throw new RuntimeException('Choose at least one part to download.');
            }

            $tables = isset($payload['tables']) && is_array($payload['tables']) && $payload['tables'] !== [] ? array_values(array_map('strval', $payload['tables'])) : null;
            $manifest = $this->manifest($run) ?? $this->readDump($file);
            $name = 'task-'.$task->id.'-'.Str::random(10).'.sql';
            $this->exportSql($file, $manifest, $parts, $tables, PanelTask::directory().DIRECTORY_SEPARATOR.$name);

            return ['file' => $name, 'download_name' => $slug.'-'.$stamp.'-'.implode('-', $parts).'.sql', 'message' => 'Your SQL file is ready to download.'];
        } finally {
            @unlink($file);
        }
    }

    /**
     * Read a gzipped mysqldump.
     *
     * @return array{type: string, tables: list<array{name: string, rows: int, bytes: int, create: string}>, procedures: list<array{name: string, sql: string}>, functions: list<array{name: string, sql: string}>, triggers: list<array{name: string, table: string, sql: string}>, events: list<array{name: string, sql: string}>}
     */
    public function readDump(string $gzFile): array
    {
        $input = gzopen($gzFile, 'rb') ?: throw new RuntimeException('Could not read the backup file.');
        $manifest = ['type' => 'database', 'tables' => [], 'procedures' => [], 'functions' => [], 'triggers' => [], 'events' => []];
        $tables = [];
        $current = null;
        $inDelimiter = false;
        $statement = '';
        $create = null;
        $inInsert = false;
        $atLineStart = true;

        while (($chunk = gzgets($input, 65536)) !== false) {
            if ($atLineStart) {
                $inInsert = false;

                if (preg_match('/^-- Table structure for table `(.+)`\s*$/', $chunk, $match)) {
                    $current = $match[1];
                    $tables[$current] = ['name' => $current, 'rows' => 0, 'bytes' => 0, 'create' => ''];
                } elseif (preg_match('/^-- (Dumping (events|routines) for database|Temporary view structure|Final view structure)/', $chunk)) {
                    $current = null;
                } elseif (preg_match('/^DELIMITER ;;\s*$/', $chunk)) {
                    $inDelimiter = true;
                    $statement = '';
                    $atLineStart = true;

                    continue;
                } elseif (preg_match('/^DELIMITER ;\s*$/', $chunk)) {
                    $inDelimiter = false;
                    $statement = '';
                    $atLineStart = true;

                    continue;
                } elseif ($current !== null && str_starts_with($chunk, 'CREATE TABLE')) {
                    $create = '';
                } elseif ($current !== null && str_starts_with($chunk, 'INSERT INTO')) {
                    $inInsert = true;
                    $tables[$current]['rows']++;
                }
            }

            if ($current !== null) {
                $tables[$current]['bytes'] += strlen($chunk);

                if ($inInsert) {
                    $tables[$current]['rows'] += substr_count($chunk, '),(');
                }
            }

            if ($create !== null && $current !== null) {
                $create .= $chunk;

                if (str_ends_with(rtrim($chunk), ';')) {
                    $tables[$current]['create'] = trim($create);
                    $create = null;
                }
            }

            if ($inDelimiter) {
                $statement .= $chunk;

                if (str_ends_with(rtrim($chunk), ';;')) {
                    $this->classify($statement, $manifest);
                    $statement = '';
                }
            }

            $atLineStart = str_ends_with($chunk, "\n");
        }

        gzclose($input);
        $manifest['tables'] = array_values($tables);

        return $manifest;
    }

    /**
     * Read the listing of a tar.gz archive: every folder and file with its size and date.
     *
     * @return array{type: string, entries: list<array{path: string, dir: bool, size: int, modified: string}>}
     */
    public function readArchive(string $tarGz): array
    {
        $result = $this->runner->run(['tar', '--force-local', '-tvzf', $tarGz]);
        $entries = [];

        if (! $result->ok && ! $result->skipped) {
            throw new RuntimeException('tar could not read the archive: '.$result->output);
        }

        foreach (explode("\n", $result->output) as $line) {
            if (! preg_match('/^([dl\-hcbps])\S*\s+\S+\s+(\d+)\s+(\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?)\s+(.+)$/', trim($line), $match)) {
                continue;
            }

            $path = preg_replace('#^\./#', '', $match[4]);

            if ($match[1] === 'l' || $match[1] === 'h') {
                $path = explode(' -> ', explode(' link to ', $path)[0])[0];
            }

            $isDir = $match[1] === 'd';
            $path = rtrim($path, '/');

            if ($path === '' || $path === '.') {
                continue;
            }

            $entries[$path] = ['path' => $path, 'dir' => $isDir, 'size' => $isDir ? 0 : (int) $match[2], 'modified' => $match[3]];

            // tar may list a file without its folders; add them so the tree can be browsed.
            $parent = dirname($path);

            while ($parent !== '.' && $parent !== '' && ! isset($entries[$parent])) {
                $entries[$parent] = ['path' => $parent, 'dir' => true, 'size' => 0, 'modified' => $match[3]];
                $parent = dirname($parent);
            }
        }

        ksort($entries, SORT_NATURAL | SORT_FLAG_CASE);

        return ['type' => 'files', 'entries' => array_values($entries)];
    }

    /**
     * The direct children of a folder of an archive manifest, folders first.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array{path: string, name: string, dir: bool, size: int, modified: string}>
     */
    public static function children(array $manifest, string $folder): array
    {
        $folder = trim($folder, '/');
        $children = [];

        foreach ($manifest['entries'] ?? [] as $entry) {
            $parent = dirname($entry['path']);

            if (($parent === '.' ? '' : $parent) === $folder) {
                $children[] = $entry + ['name' => basename($entry['path'])];
            }
        }

        usort($children, fn (array $a, array $b) => $a['dir'] === $b['dir'] ? strnatcasecmp($a['name'], $b['name']) : ($a['dir'] ? -1 : 1));

        return $children;
    }

    /**
     * Write a SQL file with the chosen parts of a dump. Routines, triggers, and events come from
     * the manifest, each with a DROP ... IF EXISTS first, so the file can be run more than once.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $parts
     * @param  list<string>|null  $tables  Null for every table.
     */
    public function exportSql(string $gzFile, array $manifest, array $parts, ?array $tables, string $outFile): void
    {
        $output = fopen($outFile, 'wb') ?: throw new RuntimeException('Could not write the download.');
        $wanted = $tables === null ? null : array_flip($tables);

        fwrite($output, "-- Laravelify backup export\n-- Parts: ".implode(', ', $parts)."\n-- Made ".now()->toDateTimeString()."\n\n");

        $input = gzopen($gzFile, 'rb') ?: throw new RuntimeException('Could not read the backup file.');
        $inHeader = true;
        $current = null;
        $inDelimiter = false;
        $atLineStart = true;
        $includeTables = in_array('tables', $parts, true);

        while (($chunk = gzgets($input, 65536)) !== false) {
            if ($atLineStart) {
                if (preg_match('/^-- Table structure for table `(.+)`\s*$/', $chunk, $match)) {
                    $inHeader = false;
                    $current = $match[1];
                } elseif (preg_match('/^-- (Dumping (events|routines) for database|Temporary view structure|Final view structure)/', $chunk)) {
                    $inHeader = false;
                    $current = null;
                } elseif (preg_match('/^DELIMITER ;;\s*$/', $chunk)) {
                    $inDelimiter = true;
                } elseif (preg_match('/^DELIMITER ;\s*$/', $chunk)) {
                    $inDelimiter = false;
                    $atLineStart = true;

                    continue;
                }
            }

            $tableWanted = $current !== null && ($wanted === null || isset($wanted[$current]));

            // Triggers sit inside table sections between DELIMITER lines; they are written later.
            if ($inHeader || ($includeTables && $tableWanted && ! $inDelimiter)) {
                fwrite($output, $chunk);
            }

            $atLineStart = str_ends_with($chunk, "\n");
        }

        gzclose($input);

        $keywords = ['procedures' => 'PROCEDURE', 'functions' => 'FUNCTION', 'triggers' => 'TRIGGER', 'events' => 'EVENT'];

        foreach ($keywords as $part => $keyword) {
            if (! in_array($part, $parts, true)) {
                continue;
            }

            $items = $manifest[$part] ?? [];

            if ($part === 'triggers' && $wanted !== null) {
                $items = array_filter($items, fn (array $item) => isset($wanted[$item['table'] ?? '']));
            }

            if ($items === []) {
                continue;
            }

            fwrite($output, "\n--\n-- ".self::PARTS[$part]."\n--\n\n");

            foreach ($items as $item) {
                fwrite($output, 'DROP '.$keyword.' IF EXISTS `'.str_replace('`', '``', $item['name'])."`;\nDELIMITER ;;\n".rtrim($item['sql'], "; \n\r\t")." ;;\nDELIMITER ;\n\n");
            }
        }

        fclose($output);
    }

    /**
     * Sort one DELIMITER ;; statement of a dump into procedures, functions, triggers, or events.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function classify(string $statement, array &$manifest): void
    {
        $clean = preg_replace('#/\*!\d{5}\s?(.*?)\s?\*/#s', '$1', $statement) ?? $statement;
        $clean = trim(preg_replace('/;;\s*$/', '', trim($clean)) ?? $clean);

        if (! preg_match('/^CREATE\s+(?:DEFINER\s*=\s*\S+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?(PROCEDURE|FUNCTION|TRIGGER|EVENT)\s+`([^`]+)`/i', $clean, $match)) {
            return;
        }

        $type = strtoupper($match[1]);
        $item = ['name' => $match[2], 'sql' => $clean];

        if ($type === 'TRIGGER') {
            preg_match('/\bON\s+`([^`]+)`/i', $clean, $table);
            $manifest['triggers'][] = $item + ['table' => $table[1] ?? ''];
        } else {
            $manifest[['PROCEDURE' => 'procedures', 'FUNCTION' => 'functions', 'EVENT' => 'events'][$type]][] = $item;
        }
    }

    private function runFor(PanelTask $task): BackupRun
    {
        $run = BackupRun::query()->with('storage')->find((int) (($task->payload ?? [])['run_id'] ?? 0));

        if (! $run || ! $run->restorable()) {
            throw new RuntimeException('That backup is no longer available.');
        }

        return $run;
    }

    private function download(BackupRun $run, callable $progress): string
    {
        $directory = storage_path('app/backup-work');
        File::ensureDirectoryExists($directory);
        $file = $directory.DIRECTORY_SEPARATOR.Str::random(12).($run->isDatabase() ? '.sql.gz' : '.tar.gz');
        $progress(5, 100);
        $this->storage->download($run->storage, (string) $run->object_key, $file);
        $progress(60, 100);

        return $file;
    }
}
