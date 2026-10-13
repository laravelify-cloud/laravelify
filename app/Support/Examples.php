<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Ready-made starter projects, one per framework, for trying Laravelify without a repository.
 * Each website gets its own local git repository made from them.
 */
class Examples
{
    public static function path(string $type): string
    {
        return resource_path('examples/'.$type);
    }

    public static function has(string $type): bool
    {
        return preg_match('/^[a-z]+$/', $type) === 1 && is_dir(self::path($type));
    }

    /**
     * Copy the example into a new git repository (branch main) and return its path.
     */
    public static function prepare(string $type, string $slug): string
    {
        if (! self::has($type)) {
            throw new RuntimeException("There is no example for {$type}.");
        }

        $target = storage_path('app/examples/'.$slug);
        File::deleteDirectory($target);
        File::ensureDirectoryExists($target);
        File::copyDirectory(self::path($type), $target);

        foreach ([
            ['init', '-q', '-b', 'main'],
            ['add', '-A'],
            ['-c', 'user.name=Laravelify', '-c', 'user.email=laravelify@localhost', 'commit', '-q', '-m', 'Example project'],
        ] as $arguments) {
            $result = Process::path($target)->run(['git', ...$arguments]);

            if ($result->failed()) {
                throw new RuntimeException('Could not create the example repository: '.trim($result->errorOutput()));
            }
        }

        return $target;
    }
}
