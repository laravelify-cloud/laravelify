<?php

namespace App\Support;

/**
 * Finds the folder a front-end build wrote its files to (dist, build, dist/app/browser…),
 * so nginx can serve it.
 */
final class BuildOutput
{
    /**
     * The output folder relative to the site root, or null when there is none.
     *
     * A folder with an index.html wins. Without one, the first folder holding any file does,
     * which covers sites that start from another page.
     *
     * @param  list<string>  $candidates  Folder names; "*" matches one folder level (dist/*\/browser).
     */
    public static function locate(string $root, ?string $configured, array $candidates): ?string
    {
        $configured = trim((string) $configured, '/');
        $patterns = $configured !== '' ? [$configured] : $candidates;
        $folders = [];

        foreach ($patterns as $pattern) {
            if (! self::safe($pattern)) {
                continue;
            }

            if ($pattern === '.') {
                $folders[] = '.';

                continue;
            }

            if (str_contains($pattern, '*')) {
                $matches = glob(rtrim($root, '/').'/'.$pattern, GLOB_ONLYDIR) ?: [];
                sort($matches);

                foreach ($matches as $match) {
                    $folders[] = ltrim(substr(str_replace('\\', '/', $match), strlen(rtrim(str_replace('\\', '/', $root), '/'))), '/');
                }

                continue;
            }

            if (is_dir(rtrim($root, '/').'/'.$pattern)) {
                $folders[] = $pattern;
            }
        }

        foreach ($folders as $folder) {
            if (is_file(self::path($root, $folder).'/index.html')) {
                return $folder;
            }
        }

        foreach ($folders as $folder) {
            if ($folder !== '.' && (glob(self::path($root, $folder).'/*') ?: []) !== []) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * A folder name from the form: relative, no "..", and only plain path characters.
     */
    public static function safe(string $folder): bool
    {
        return $folder === '.' || preg_match('#^[A-Za-z0-9._*-]+(/[A-Za-z0-9._*-]+)*$#', $folder) === 1 && ! str_contains($folder, '..');
    }

    private static function path(string $root, string $folder): string
    {
        return $folder === '.' ? rtrim($root, '/') : rtrim($root, '/').'/'.$folder;
    }
}
