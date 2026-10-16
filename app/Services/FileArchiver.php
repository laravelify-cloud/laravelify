<?php

namespace App\Services;

use RuntimeException;

/**
 * Folder backups: tar + gzip of folders inside the file manager root (/var/www by default),
 * with exclude patterns, and restores of the whole archive or one folder from it.
 */
class FileArchiver
{
    /** Folders that are cheap to rebuild and big. Offered as one-click excludes. */
    public const SUGGESTED_EXCLUDES = ['node_modules', 'vendor', '.git', '.next', '.nuxt', 'storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views'];

    private const MAX_LISTED = 400;

    public function __construct(private CommandRunner $runner, private SitePaths $paths, private SiteFiles $files, private SiteUser $site) {}

    public function root(): string
    {
        return $this->paths->globalRoot();
    }

    /**
     * A folder or file inside the root, written the way tar needs it: relative, no "..".
     */
    public static function cleanPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if (preg_match('/[\x00-\x1f]/', $path) || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            throw new RuntimeException('That path is not allowed.');
        }

        return $path === '.' ? '' : $path;
    }

    /**
     * Folders and files of a folder for the backup picker, folders first.
     *
     * @return list<array{name: string, relative: string, dir: bool, link: bool, size: int, modified: int}>
     */
    public function listing(string $relative): array
    {
        $relative = self::cleanPath($relative);
        $directory = $this->paths->resolveIn($this->root(), $relative);
        $rows = [];

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $full = $directory.DIRECTORY_SEPARATOR.$name;
            $link = is_link($full);
            $dir = ! $link && is_dir($full);

            $rows[] = [
                'name' => $name,
                'relative' => ltrim($relative.'/'.$name, '/'),
                'dir' => $dir,
                'link' => $link,
                'size' => $dir ? 0 : (int) @filesize($full),
                'modified' => (int) @filemtime($full),
            ];
        }

        usort($rows, fn (array $a, array $b) => $a['dir'] === $b['dir'] ? strnatcasecmp($a['name'], $b['name']) : ($a['dir'] ? -1 : 1));

        return $rows;
    }

    /**
     * Whether a tar exclude pattern skips this path: a name matches in every folder
     * (node_modules), a path matches that path and anything ending with it (storage/logs).
     *
     * @param  list<string>  $patterns
     */
    public static function excluded(string $path, array $patterns): bool
    {
        $path = trim($path, '/');

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern, '/');

            if ($pattern === '') {
                continue;
            }

            if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
                if (fnmatch($pattern, $path) || fnmatch($pattern, basename($path)) || fnmatch('*/'.$pattern, $path)) {
                    return true;
                }

                continue;
            }

            if ($path === $pattern || str_ends_with($path, '/'.$pattern) || str_starts_with($path, $pattern.'/') || str_contains($path, '/'.$pattern.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pack the folders into $file. Returns what is inside, for restoring one folder later.
     *
     * @param  list<string>  $paths  Relative to the root. An empty string is the whole root.
     * @param  list<string>  $excludes  tar patterns, like node_modules or storage/logs.
     * @return array{entries: list<string>, files: int, warning: ?string}
     */
    public function archive(array $paths, array $excludes, string $file): array
    {
        $root = $this->root();
        $members = [];

        foreach ($paths as $path) {
            $path = self::cleanPath($path);
            $this->paths->resolveIn($root, $path);

            if ($path !== '') {
                $members[] = $path;

                continue;
            }

            // The whole root: list what is in it, so names in the archive never start with "./",
            // and leave out the folder restores are written to.
            foreach (scandir($root) ?: [] as $name) {
                if (! in_array($name, ['.', '..', 'laravelify-restore'], true)) {
                    $members[] = $name;
                }
            }
        }

        if ($members === []) {
            throw new RuntimeException('Choose at least one folder.');
        }

        $command = ['tar', '--force-local', '-czf', $file, '-C', $root];

        foreach ($excludes as $pattern) {
            $pattern = self::cleanPath($pattern);

            if ($pattern !== '') {
                $command[] = '--exclude='.$pattern;
            }
        }

        $command = [...$command, '--', ...array_values(array_unique($members))];

        // The site user reads the sites (some files are its own only) and the panel keeps the archive.
        $result = $this->site->separate()
            ? $this->files->execToFile(array_replace($command, [3 => '-']), $file)
            : $this->runner->run($command);

        if ($result->skipped) {
            touch($file);

            return ['entries' => [], 'files' => 0, 'warning' => null];
        }

        // tar exits with 1 when a file changed while it was being read. The archive is still good.
        if (! $result->ok && $result->exitCode !== 1) {
            @unlink($file);
            throw new RuntimeException('tar failed: '.$result->output);
        }

        $warning = $result->ok ? null : 'Some files changed while they were being read.';

        return [...$this->describe($file), 'warning' => $warning];
    }

    /**
     * Unpack the whole archive (no members) or only the chosen folders and files, into the
     * original place or a new restore folder. Returns the folder the files were written to.
     *
     * @param  list<string>  $members
     */
    public function restore(string $file, array $members, bool $toOriginal): string
    {
        $root = $this->root();
        $destination = $root;

        if (! $toOriginal) {
            $destination = $root.'/laravelify-restore/'.now()->format('Ymd-His');

            if (! is_dir($destination)) {
                $this->files->makeDirectory($destination, 0775, true);
            }
        }

        $command = ['tar', '--force-local', '-xzf', $file, '-C', $destination, '--no-same-owner'];

        $members = array_values(array_unique(array_filter(array_map(self::cleanPath(...), $members), fn (string $member) => $member !== '')));

        if ($members !== []) {
            $command[] = '--';
            array_push($command, ...$members);
        }

        if ($this->site->separate()) {
            // The site user writes the files, reading the archive the panel downloaded from stdin.
            $stream = @fopen($file, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Could not read the downloaded backup.');
            }

            try {
                $result = $this->files->exec(array_replace($command, [3 => '-']), $stream);
            } finally {
                fclose($stream);
            }
        } else {
            $result = $this->runner->run($command);
        }

        if (! $result->ok) {
            throw new RuntimeException('tar could not unpack the backup: '.$result->output);
        }

        return $destination;
    }

    /**
     * The folders (three levels deep at most) and the file count of an archive.
     *
     * @return array{entries: list<string>, files: int}
     */
    private function describe(string $file): array
    {
        $result = $this->runner->run(['tar', '--force-local', '-tzf', $file]);
        $directories = [];
        $files = 0;

        foreach (explode("\n", $result->output) as $line) {
            $line = preg_replace('#^\./#', '', trim($line));

            if ($line === '' || $line === '.') {
                continue;
            }

            if (! str_ends_with($line, '/')) {
                $files++;
            }

            $parts = explode('/', trim($line, '/'));

            for ($depth = 1; $depth <= min(3, count($parts) - (str_ends_with($line, '/') ? 0 : 1)); $depth++) {
                $directories[implode('/', array_slice($parts, 0, $depth))] = true;
            }
        }

        $names = array_keys($directories);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return ['entries' => array_slice(array_values(array_filter($names, fn (string $name) => $name !== '.')), 0, self::MAX_LISTED), 'files' => $files];
    }
}
