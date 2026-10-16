<?php

namespace App\Support;

/**
 * Ready-made starter projects, one per framework, for trying Laravelify without a repository.
 * Each website gets its own local git repository made from them (see ExampleRepositories).
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
}
