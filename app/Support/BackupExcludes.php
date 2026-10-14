<?php

namespace App\Support;

use App\Models\Website;
use App\Services\SitePaths;
use Throwable;

/**
 * What a website backup can leave out because a deploy rebuilds it: dependencies
 * (node_modules, vendor), build output (dist, .next, .nuxt ...), caches, and logs.
 */
class BackupExcludes
{
    /** Skipped in every folder, whatever the site is. */
    public const EVERYWHERE = ['node_modules', '.git'];

    /**
     * Folders inside a site of this type, relative to the site folder.
     *
     * @return list<string>
     */
    public static function forType(string $type): array
    {
        return match ($type) {
            'laravel' => ['vendor', 'public/build', 'public/hot', 'bootstrap/cache', 'storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views'],
            'php' => ['vendor'],
            'nextjs' => ['.next'],
            'nuxt' => ['.nuxt', '.output'],
            'sveltekit' => ['.svelte-kit', 'build'],
            'express', 'nestjs', 'node' => ['dist', '.cache'],
            'angular' => ['dist', '.angular'],
            'astro' => ['dist', '.astro'],
            'react', 'vue', 'svelte' => ['dist', 'build'],
            default => [],
        };
    }

    /**
     * The skips for one website inside a backup of the file manager root: the folders above
     * prefixed with the site folder, plus node_modules and .git everywhere.
     *
     * @return list<string>
     */
    public static function forWebsite(Website $website, string $folder): array
    {
        return array_values(array_unique([
            ...self::EVERYWHERE,
            ...array_map(fn (string $path) => trim($folder.'/'.$path, '/'), self::forType((string) $website->type)),
        ]));
    }

    /**
     * The website's folder relative to the file manager root, or null when it is outside it
     * or not deployed yet.
     */
    public static function folderOf(Website $website, string $root): ?string
    {
        try {
            $paths = app(SitePaths::class);
            $full = $paths->root($website);
        } catch (Throwable) {
            return null;
        }

        return $paths->inside($root, $full) && $full !== $root ? $paths->relative($root, $full) : null;
    }

    /**
     * Every website with its folder and skips, for the "every website" backups.
     *
     * @return list<array{website: Website, folder: string, excludes: list<string>}>
     */
    public static function websites(string $root): array
    {
        $result = [];

        foreach (Website::query()->orderBy('slug')->get() as $website) {
            $folder = self::folderOf($website, $root);

            if ($folder !== null) {
                $result[] = ['website' => $website, 'folder' => $folder, 'excludes' => self::forWebsite($website, $folder)];
            }
        }

        return $result;
    }
}
