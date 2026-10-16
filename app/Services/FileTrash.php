<?php

namespace App\Services;

use App\Models\TrashItem;
use App\Models\User;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * The file manager's trash: deleting moves files and folders here (one item per delete, so
 * the same path deleted five times is five versions), restoring moves them back, and only
 * an authenticator code removes them for good.
 */
class FileTrash
{
    /** Folders bigger than this many entries are not measured; their size shows as unknown. */
    private const SIZE_SCAN_LIMIT = 20000;

    public function __construct(private SitePaths $paths, private FileOperations $operations, private TwoFactor $twoFactor, private SiteFiles $files) {}

    /**
     * Move entries of a root folder to the trash. Returns how many were moved.
     *
     * @param  list<string>  $relatives
     */
    public function move(string $scope, string $root, array $relatives, ?int $userId = null): int
    {
        $moved = 0;

        foreach (array_unique(array_map('strval', $relatives)) as $relative) {
            $full = $this->paths->locateIn($root, $relative);

            if ($full === $root) {
                throw new RuntimeException('The root folder cannot be deleted.');
            }

            $isDir = ! is_link($full) && is_dir($full);
            // A website with its own user keeps deleted files in its own trash folder.
            $owner = $this->files->userOf($full);
            $item = TrashItem::query()->create([
                'scope' => $scope,
                'site_slug' => $owner->isOwn() ? $owner->slug() : null,
                'original_path' => $this->paths->relative($root, $full),
                'name' => basename($full),
                'is_dir' => $isDir,
                'size_bytes' => $this->size($full),
                'user_id' => $userId,
            ]);

            try {
                $this->files->makeDirectory($item->folder(), 0750, true);
                $this->files->move($full, $item->storedPath());
            } catch (RuntimeException $exception) {
                $this->deleteFolder($item);
                $item->delete();

                throw $exception;
            }

            $moved++;
        }

        return $moved;
    }

    /**
     * Put an item back where it was. When that name is taken again, it comes back as
     * "name (restored)". Returns the path it was restored to, relative to the root.
     */
    public function restore(TrashItem $item, string $root): string
    {
        if (! file_exists($item->storedPath()) && ! is_link($item->storedPath())) {
            $item->delete();

            throw new RuntimeException('That item is no longer in the trash folder.');
        }

        $parent = $item->parentPath();
        $parentFull = rtrim($root, '/\\').($parent === '' ? '' : DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $parent));

        if (! is_dir($parentFull)) {
            $this->files->makeDirectory($parentFull, 0755, true);
        }

        // The parent must still be inside the root (it may have been replaced by a link).
        $this->paths->resolveIn($root, $parent);

        $target = $parentFull.DIRECTORY_SEPARATOR.$item->name;

        if (file_exists($target) || is_link($target)) {
            $target = $this->operations->uniquePath($parentFull, $this->restoredName($item), ! $item->is_dir);
        }

        $this->files->move($item->storedPath(), $target);
        $this->deleteFolder($item);
        $item->delete();

        return $this->paths->relative($root, $target);
    }

    /**
     * Delete an item for good.
     */
    public function purge(TrashItem $item): void
    {
        $this->deleteFolder($item);
        $item->delete();
    }

    /**
     * Check an authenticator code before anything is deleted for good. Five wrong codes a
     * minute at most.
     */
    public function confirmCode(User $user, string $code): void
    {
        $this->twoFactor->confirmCode($user, $code, 'trash-purge');
    }

    /**
     * The trash folder is owned by the site user, so the site user empties it.
     */
    private function deleteFolder(TrashItem $item): void
    {
        try {
            $this->files->delete($item->folder());
        } catch (RuntimeException) {
            // Already gone, or a leftover the next purge removes.
        }
    }

    private function size(string $full): ?int
    {
        if (is_link($full)) {
            return 0;
        }

        if (is_file($full)) {
            return (int) @filesize($full);
        }

        $bytes = 0;
        $count = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (++$count > self::SIZE_SCAN_LIMIT) {
                return null;
            }

            $bytes += $file->isFile() && ! $file->isLink() ? (int) $file->getSize() : 0;
        }

        return $bytes;
    }

    private function restoredName(TrashItem $item): string
    {
        $extension = $item->is_dir ? '' : pathinfo($item->name, PATHINFO_EXTENSION);
        $base = $extension === '' ? $item->name : substr($item->name, 0, -strlen($extension) - 1);

        return $base.' (restored)'.($extension === '' ? '' : '.'.$extension);
    }
}
