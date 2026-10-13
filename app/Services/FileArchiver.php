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

    public function __construct(private CommandRunner $runner, private SitePaths $paths) {}

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
     * Subfolders of a folder, for the folder picker.
     *
     * @return list<string> Names, sorted.
     */
    public function folders(string $relative): array
    {
        $directory = $this->paths->resolveIn($this->root(), self::cleanPath($relative));
        $names = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($directory.DIRECTORY_SEPARATOR.$entry)) {
                $names[] = $entry;
            }
        }

        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
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
            $members[] = $path === '' ? '.' : $path;
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

        $result = $this->runner->run([...$command, '--', ...array_values(array_unique($members))]);

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
     * Unpack the whole archive or only $member into the original place or a restore folder.
     * Returns the folder the files were written to.
     */
    public function restore(string $file, ?string $member, bool $toOriginal): string
    {
        $root = $this->root();
        $destination = $root;

        if (! $toOriginal) {
            $destination = $root.'/laravelify-restore/'.now()->format('Ymd-His');

            if (! is_dir($destination) && ! mkdir($destination, 0775, true)) {
                throw new RuntimeException('Could not create the restore folder in '.$root.'.');
            }
        }

        $command = ['tar', '--force-local', '-xzf', $file, '-C', $destination, '--no-same-owner'];

        if ($member !== null && self::cleanPath($member) !== '') {
            $command[] = '--';
            $command[] = self::cleanPath($member);
        }

        $result = $this->runner->run($command);

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
