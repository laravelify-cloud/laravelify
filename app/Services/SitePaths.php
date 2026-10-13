<?php

namespace App\Services;

use App\Models\Website;
use RuntimeException;

class SitePaths
{
    public function root(Website $website): string
    {
        $path = $website->deploy_path;

        if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('This website has no deploy folder.');
        }

        $root = realpath($path);

        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('Deploy the site first. The folder does not exist yet.');
        }

        return $root;
    }

    /**
     * The folder the global file manager and terminal start from.
     */
    public function globalRoot(): string
    {
        $path = (string) (config('laravelify.files_root') ?: config('laravelify.sites_root'));

        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('No file manager root is configured.');
        }

        $root = realpath($path);

        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('The file manager root '.$path.' does not exist.');
        }

        return $root;
    }

    public function resolve(Website $website, string $relative): string
    {
        return $this->resolveIn($this->root($website), $relative);
    }

    public function locate(Website $website, string $relative): string
    {
        return $this->locateIn($this->root($website), $relative);
    }

    /**
     * A real folder or file inside the root. Symlinks are followed but must stay inside.
     */
    public function resolveIn(string $root, string $relative): string
    {
        if (str_contains($relative, "\0")) {
            throw new RuntimeException('That path is not allowed.');
        }

        $relative = trim(str_replace('\\', '/', $relative), '/');

        if ($relative === '' || $relative === '.') {
            return $root;
        }

        $full = rtrim($root, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolved = realpath($full);

        if ($resolved === false || ! $this->inside($root, $resolved)) {
            throw new RuntimeException('That path is outside the site folder.');
        }

        return $resolved;
    }

    /**
     * The named entry inside a resolved parent. A symlink is returned as the
     * link itself so a delete cannot follow it out of the root folder.
     */
    public function locateIn(string $root, string $relative): string
    {
        if (str_contains($relative, "\0")) {
            throw new RuntimeException('That path is not allowed.');
        }

        $relative = trim(str_replace('\\', '/', $relative), '/');

        if ($relative === '' || $relative === '.') {
            return $root;
        }

        $name = basename($relative);

        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('That path is not allowed.');
        }

        $parent = trim(dirname($relative), '/');
        $parentFull = $this->resolveIn($root, $parent === '.' ? '' : $parent);
        $full = rtrim($parentFull, '/\\').DIRECTORY_SEPARATOR.$name;

        if (! file_exists($full) && ! is_link($full)) {
            throw new RuntimeException('That path is outside the site folder.');
        }

        if (is_link($full)) {
            return $full;
        }

        $resolved = realpath($full);

        if ($resolved === false || ! $this->inside($root, $resolved)) {
            throw new RuntimeException('That path is outside the site folder.');
        }

        return $resolved;
    }

    public function relative(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = rtrim(str_replace('\\', '/', $path), '/');

        if ($path === $root) {
            return '';
        }

        return ltrim(substr($path, strlen($root)), '/');
    }

    public function inside(string $root, string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return $path === $root || str_starts_with($path, $root.'/');
    }
}
