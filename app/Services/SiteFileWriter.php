<?php

namespace App\Services;

use App\Models\Website;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Puts files the repository does not carry into a website's folder: secrets like a Firebase
 * key in storage/app/private, or a whole archive unpacked into a folder. Paths stay inside the
 * site folder, links are never followed or created, and deploys keep the files (they reset
 * tracked files only).
 */
class SiteFileWriter
{
    /** The largest file or archive accepted, after decoding. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    /** The most an archive may hold once unpacked. */
    private const MAX_EXTRACTED_BYTES = 200 * 1024 * 1024;

    public function __construct(private SitePaths $paths) {}

    /**
     * Write one file. Missing folders on the way are created.
     *
     * @return string The path written, relative to the site folder.
     */
    public function write(Website $website, string $path, string $contents, bool $overwrite = true): string
    {
        $this->guardSize(strlen($contents));
        $root = $this->paths->root($website);
        $relative = $this->cleanPath($path);
        $directory = $this->ensureDirectory($root, dirname($relative) === '.' ? '' : dirname($relative));
        $target = $directory.DIRECTORY_SEPARATOR.basename($relative);

        if (is_link($target) || is_dir($target)) {
            throw new RuntimeException($relative.' is a folder or a link, so it was not replaced.');
        }

        if (! $overwrite && file_exists($target)) {
            throw new RuntimeException($relative.' already exists. Pass overwrite true to replace it.');
        }

        $this->put($target, $contents);

        return $relative;
    }

    /**
     * Unpack a .zip or .tar.gz into a folder of the site, replacing files with the same names.
     *
     * @return list<string> The files written, relative to the site folder.
     */
    public function extract(Website $website, string $folder, string $archive, string $filename): array
    {
        $this->guardSize(strlen($archive));
        $root = $this->paths->root($website);
        $folder = trim(str_replace('\\', '/', $folder), '/');
        $folder = $folder === '' || $folder === '.' ? '' : $this->cleanPath($folder);
        $destination = $this->ensureDirectory($root, $folder);
        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravelify-upload-'.bin2hex(random_bytes(8));
        $unpacked = $work.DIRECTORY_SEPARATOR.'files';
        $written = [];

        File::ensureDirectoryExists($unpacked, 0700);

        try {
            $archivePath = $work.DIRECTORY_SEPARATOR.$this->archiveName($filename);
            file_put_contents($archivePath, $archive);
            $this->unpack($archivePath, $unpacked);

            $total = 0;
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($unpacked, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

            foreach ($files as $file) {
                // Links inside an archive could point anywhere on the server: they are skipped.
                if ($file->isLink() || ! $file->isFile()) {
                    continue;
                }

                $total += $file->getSize();

                if ($total > self::MAX_EXTRACTED_BYTES) {
                    throw new RuntimeException('The archive holds more than 200 MB once unpacked.');
                }

                $inner = str_replace('\\', '/', substr($file->getPathname(), strlen($unpacked) + 1));
                $relative = ltrim(($folder !== '' ? $folder.'/' : '').$this->cleanPath($inner), '/');
                $directory = $this->ensureDirectory($root, dirname($relative) === '.' ? '' : dirname($relative));
                $target = $directory.DIRECTORY_SEPARATOR.basename($relative);

                if (is_link($target) || is_dir($target)) {
                    throw new RuntimeException($relative.' is a folder or a link in the site, so the archive was not unpacked over it.');
                }

                $this->put($target, (string) file_get_contents($file->getPathname()));
                $written[] = $relative;
            }
        } finally {
            File::deleteDirectory($work);
        }

        if ($written === []) {
            throw new RuntimeException('The archive has no files.');
        }

        return $written;
    }

    private function unpack(string $archivePath, string $into): void
    {
        $name = strtolower($archivePath);

        try {
            if (str_ends_with($name, '.zip')) {
                $zip = new ZipArchive;

                if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
                    throw new RuntimeException('That .zip cannot be read.');
                }

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $this->cleanPath((string) $zip->getNameIndex($i), true);
                }

                $zip->extractTo($into);
                $zip->close();

                return;
            }

            $tar = new PharData($archivePath);

            foreach (new RecursiveIteratorIterator($tar) as $entry) {
                $this->cleanPath(substr(str_replace('\\', '/', $entry->getPathname()), strlen('phar://'.str_replace('\\', '/', $archivePath)) + 1), true);
            }

            $tar->extractTo($into, null, true);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException('That archive cannot be unpacked: '.$exception->getMessage());
        }
    }

    /**
     * A relative path with no "..", no absolute start, and no empty parts.
     */
    private function cleanPath(string $path, bool $allowFolderEnd = false): string
    {
        $path = str_replace('\\', '/', trim($path));
        $trimmed = $allowFolderEnd ? rtrim($path, '/') : $path;

        if ($trimmed === '' || str_contains($trimmed, "\0") || str_starts_with($trimmed, '/') || preg_match('/^[A-Za-z]:/', $trimmed) === 1) {
            throw new RuntimeException('Use a path inside the site folder, like storage/app/private/firebase.json ("'.$path.'" is not).');
        }

        foreach (explode('/', $trimmed) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('Use a path inside the site folder, like storage/app/private/firebase.json ("'.$path.'" is not).');
            }
        }

        return $trimmed;
    }

    /**
     * Create the folders of a relative path one by one, checking each stays inside the root.
     */
    private function ensureDirectory(string $root, string $relative): string
    {
        $current = $root;

        foreach ($relative === '' ? [] : explode('/', $this->cleanPath($relative)) as $part) {
            $next = $current.DIRECTORY_SEPARATOR.$part;

            if (is_link($next) || (file_exists($next) && ! is_dir($next))) {
                throw new RuntimeException('"'.$part.'" in that path is a file or a link, so nothing was written.');
            }

            if (! is_dir($next) && ! mkdir($next, 0755) && ! is_dir($next)) {
                throw new RuntimeException('Could not create the folder '.$part.'.');
            }

            $current = $this->paths->resolveIn($root, $this->paths->relative($root, $next));
        }

        return $current;
    }

    private function put(string $target, string $contents): void
    {
        $temporary = $target.'.laravelify-'.bin2hex(random_bytes(4));

        if (file_put_contents($temporary, $contents) === false) {
            throw new RuntimeException('Could not write '.basename($target).'.');
        }

        @chmod($temporary, 0640);

        if (! @rename($temporary, $target)) {
            @unlink($temporary);

            throw new RuntimeException('Could not write '.basename($target).'.');
        }
    }

    private function guardSize(int $bytes): void
    {
        if ($bytes > self::MAX_BYTES) {
            throw new RuntimeException('That is more than 20 MB. Upload bigger files in the panel\'s file manager.');
        }
    }

    private function archiveName(string $filename): string
    {
        $name = strtolower(basename(str_replace('\\', '/', $filename)));

        return match (true) {
            str_ends_with($name, '.zip') => 'archive.zip',
            str_ends_with($name, '.tar.gz'), str_ends_with($name, '.tgz') => 'archive.tar.gz',
            str_ends_with($name, '.tar') => 'archive.tar',
            default => throw new RuntimeException('Unpack works with .zip, .tar.gz, .tgz, and .tar files.'),
        };
    }
}
