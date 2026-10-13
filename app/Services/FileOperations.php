<?php

namespace App\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * File manager work on folders: zip, unzip, copy, and delete, inside a root folder only.
 * Small jobs run while the page waits; big ones run in the queue with the same code and
 * report their progress through the callback, as (done, total).
 */
class FileOperations
{
    /** Largest total size an archive may unpack to, in bytes. */
    public const int EXTRACT_LIMIT = 2 * 1024 * 1024 * 1024;

    /** More files and folders than this, and the work goes to the queue. */
    public const int INLINE_LIMIT = 1500;

    public function __construct(private SitePaths $paths) {}

    public static function canZip(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * Files and folders under the entries, counted up to $limit (counting stops there).
     *
     * @param  list<string>  $fullPaths
     */
    public function count(array $fullPaths, int $limit = PHP_INT_MAX): int
    {
        $count = 0;

        foreach ($fullPaths as $full) {
            $count++;

            if (is_link($full) || ! is_dir($full)) {
                continue;
            }

            foreach ($this->walk($full) as $_) {
                if (++$count >= $limit) {
                    return $count;
                }
            }
        }

        return $count;
    }

    /**
     * Whether these entries are small enough to handle during the request.
     *
     * @param  list<string>  $relatives
     */
    public function isSmall(string $root, array $relatives): bool
    {
        $full = array_map(fn (string $relative) => $this->paths->locateIn($root, $relative), $relatives);

        return $this->count($full, self::INLINE_LIMIT + 1) <= self::INLINE_LIMIT;
    }

    /**
     * Zip entries into an archive in $directory. Returns the archive path relative to the root.
     *
     * @param  list<string>  $relatives
     */
    public function compress(string $root, string $directory, array $relatives, ?callable $progress = null): string
    {
        $this->requireZip();
        $dir = $this->paths->resolveIn($root, $directory);
        $sources = $this->sources($root, $relatives);

        if ($sources === []) {
            throw new RuntimeException('Choose something to compress.');
        }

        $name = count($sources) === 1 ? basename(array_key_first($sources)).'.zip' : 'archive.zip';
        $target = $this->uniquePath($dir, $name, true);
        $files = $this->zipList($sources);

        $this->writeZip($target, $files, $progress);

        return $this->paths->relative($root, $target);
    }

    /**
     * Zip one folder into $target (outside the root) for a download. Returns the download name.
     */
    public function zipForDownload(string $root, string $relative, string $target, bool $allowRoot, ?callable $progress = null): string
    {
        $this->requireZip();
        $full = $this->paths->locateIn($root, $relative);

        if (is_link($full) || ! is_dir($full) || (! $allowRoot && $full === $root)) {
            throw new RuntimeException('Only folders inside the root can be downloaded as a zip.');
        }

        $base = basename($full) ?: 'folder';
        $this->writeZip($target, $this->zipList([$full => $base]), $progress);

        return $base.'.zip';
    }

    /**
     * Unpack a .zip into a new folder next to it. Returns the folder relative to the root.
     */
    public function extract(string $root, string $relative, ?callable $progress = null): string
    {
        $this->requireZip();
        $full = $this->paths->locateIn($root, $relative);

        if (is_link($full) || ! is_file($full) || strtolower(pathinfo($full, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('Only .zip archives can be extracted.');
        }

        $zip = new ZipArchive;

        if ($zip->open($full, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('That archive cannot be read.');
        }

        try {
            $total = 0;
            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                $stat = $zip->statIndex($i);
                $total += is_array($stat) ? (int) $stat['size'] : 0;

                if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0") || preg_match('/^[A-Za-z]:/', $name) === 1 || in_array('..', explode('/', $name), true)) {
                    throw new RuntimeException('That archive contains unsafe paths and was not extracted.');
                }

                $names[] = $name;
            }

            if ($total > self::EXTRACT_LIMIT) {
                throw new RuntimeException('That archive is larger than 2 GB when extracted.');
            }

            $target = $this->uniquePath(dirname($full), pathinfo($full, PATHINFO_FILENAME), false);

            if (! mkdir($target, 0755)) {
                throw new RuntimeException('Could not create the folder to extract into.');
            }

            foreach (array_chunk($names, 200) as $index => $batch) {
                if (! $zip->extractTo($target, $batch)) {
                    throw new RuntimeException('Could not extract that archive.');
                }

                $progress && $progress(min(count($names), ($index + 1) * 200), count($names));
            }
        } finally {
            $zip->close();
        }

        return $this->paths->relative($root, $target);
    }

    /**
     * Copy entries into a folder. Existing names get " copy" added. Returns how many were copied.
     *
     * @param  list<string>  $relatives
     */
    public function copy(string $root, array $relatives, string $destination, ?callable $progress = null): int
    {
        $destinationPath = $this->paths->resolveIn($root, $destination);

        if (is_link($destinationPath) || ! is_dir($destinationPath)) {
            throw new RuntimeException('That folder does not exist.');
        }

        $sources = $this->sources($root, $relatives);
        $total = max(1, $this->count(array_keys($sources)));
        $done = 0;
        $copied = 0;

        foreach ($sources as $source => $name) {
            if (is_link($source)) {
                throw new RuntimeException('Links cannot be copied.');
            }

            if ($source === $root) {
                throw new RuntimeException('The root folder cannot be copied.');
            }

            if (is_dir($source) && $this->paths->inside($source, $destinationPath)) {
                throw new RuntimeException($name.' cannot go inside itself.');
            }

            $target = $this->uniquePath($destinationPath, $name, ! is_dir($source));
            $this->copyRecursive($source, $target, function () use (&$done, $total, $progress) {
                $done++;
                $progress && $progress($done, $total);
            });
            $copied++;
        }

        return $copied;
    }

    /**
     * Delete entries, folders with everything inside them. Returns how many were deleted.
     *
     * @param  list<string>  $relatives
     */
    public function delete(string $root, array $relatives, ?callable $progress = null): int
    {
        $sources = $this->sources($root, $relatives, followLinks: false);
        $total = max(1, $this->count(array_filter(array_keys($sources), fn (string $path) => ! is_link($path))));
        $done = 0;

        foreach (array_keys($sources) as $full) {
            if ($full === $root) {
                throw new RuntimeException('The root folder cannot be deleted.');
            }

            $this->remove($full, $root, function () use (&$done, $total, $progress) {
                $done++;
                $progress && $progress($done, $total);
            });
        }

        return count($sources);
    }

    /**
     * A free path in the folder, adding " copy", " copy 2"... before the extension.
     */
    public function uniquePath(string $dir, string $name, bool $keepExtension): string
    {
        $candidate = $dir.DIRECTORY_SEPARATOR.$name;

        if (! file_exists($candidate) && ! is_link($candidate)) {
            return $candidate;
        }

        $extension = $keepExtension ? pathinfo($name, PATHINFO_EXTENSION) : '';
        $base = $extension !== '' ? substr($name, 0, -strlen($extension) - 1) : $name;
        $suffix = $extension !== '' ? '.'.$extension : '';

        for ($i = 1; $i < 1000; $i++) {
            $candidate = $dir.DIRECTORY_SEPARATOR.$base.($i === 1 ? ' copy' : ' copy '.$i).$suffix;

            if (! file_exists($candidate) && ! is_link($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not find a free name.');
    }

    /**
     * Full path => name of each entry. Links are returned as links, never followed.
     *
     * @param  list<string>  $relatives
     * @return array<string, string>
     */
    private function sources(string $root, array $relatives, bool $followLinks = true): array
    {
        $sources = [];

        foreach (array_unique(array_map('strval', $relatives)) as $relative) {
            $full = $this->paths->locateIn($root, $relative);

            if ($followLinks && is_link($full)) {
                continue;
            }

            $sources[$full] = basename($full);
        }

        return $sources;
    }

    /**
     * Every folder and file to put in a zip, as full path => name inside the zip.
     *
     * @param  array<string, string>  $sources
     * @return array<string, string>
     */
    private function zipList(array $sources): array
    {
        $files = [];

        foreach ($sources as $full => $base) {
            if (is_link($full)) {
                continue;
            }

            if (is_file($full)) {
                $files[$full] = $base;

                continue;
            }

            $files[$full.DIRECTORY_SEPARATOR] = $base.'/';

            foreach ($this->walk($full) as $path => $item) {
                $name = $base.'/'.str_replace('\\', '/', substr($path, strlen($full) + 1));
                $files[$item->isDir() ? $path.DIRECTORY_SEPARATOR : $path] = $item->isDir() ? $name.'/' : $name;
            }
        }

        return $files;
    }

    /**
     * @param  array<string, string>  $files  Full path => name; folders end with a slash.
     */
    private function writeZip(string $target, array $files, ?callable $progress): void
    {
        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the archive.');
        }

        $total = max(1, count($files));
        $done = 0;

        foreach ($files as $path => $name) {
            if (str_ends_with($name, '/')) {
                $zip->addEmptyDir(rtrim($name, '/'));
            } elseif (is_readable($path)) {
                $zip->addFile($path, $name);
            }

            // Adding only records the files; writing happens on close. Count adding as the first half.
            $progress && ++$done % 200 === 0 && $progress((int) ($done / 2), $total);
        }

        if (method_exists($zip, 'registerProgressCallback') && $progress) {
            $zip->registerProgressCallback(0.02, fn (float $rate) => $progress((int) (($total / 2) + $rate * $total / 2), $total));
        }

        if ($zip->numFiles === 0) {
            $zip->addEmptyDir(pathinfo($target, PATHINFO_FILENAME));
        }

        if (! $zip->close()) {
            @unlink($target);
            throw new RuntimeException('Could not write the archive. Is the disk full?');
        }

        @chmod($target, 0644);
        $progress && $progress($total, $total);
    }

    /**
     * Everything under a folder, without following links.
     *
     * @return RecursiveIteratorIterator<RecursiveDirectoryIterator>
     */
    private function walk(string $directory): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
    }

    private function copyRecursive(string $source, string $target, callable $tick): void
    {
        if (is_link($source)) {
            return;
        }

        $tick();

        if (is_file($source)) {
            if (! copy($source, $target)) {
                throw new RuntimeException('Could not copy '.basename($source).'.');
            }

            @chmod($target, @fileperms($source) & 0777);

            return;
        }

        if (! mkdir($target, @fileperms($source) & 0777 ?: 0755)) {
            throw new RuntimeException('Could not create '.basename($target).'.');
        }

        foreach (scandir($source) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->copyRecursive($source.DIRECTORY_SEPARATOR.$name, $target.DIRECTORY_SEPARATOR.$name, $tick);
            }
        }
    }

    private function remove(string $full, string $root, callable $tick): void
    {
        $tick();

        if (is_link($full)) {
            $parent = realpath(dirname($full));

            if ($parent === false || ! $this->paths->inside($root, $parent) || ! unlink($full)) {
                throw new RuntimeException('Could not delete that link.');
            }

            return;
        }

        $resolved = realpath($full);

        if ($resolved === false || ! $this->paths->inside($root, $resolved) || $resolved === $root) {
            throw new RuntimeException('That path is outside the root folder.');
        }

        if (is_file($resolved)) {
            if (! unlink($resolved)) {
                throw new RuntimeException('Could not delete '.basename($resolved).'.');
            }

            return;
        }

        foreach (scandir($resolved) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->remove($resolved.DIRECTORY_SEPARATOR.$name, $root, $tick);
            }
        }

        if (! rmdir($resolved)) {
            throw new RuntimeException('Could not delete '.basename($resolved).'.');
        }
    }

    private function requireZip(): void
    {
        if (! self::canZip()) {
            throw new RuntimeException('The PHP zip extension is not installed.');
        }
    }
}
