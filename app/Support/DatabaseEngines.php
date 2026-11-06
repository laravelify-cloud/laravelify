<?php

namespace App\Support;

/**
 * The kinds of database a website can have on this server, as the installer set it up
 * (LARAVELIFY_DATABASES). MariaDB is the mysql engine: the same protocol, port and drivers.
 */
class DatabaseEngines
{
    public const MYSQL = 'mysql';

    public const POSTGRESQL = 'postgresql';

    public const MONGODB = 'mongodb';

    public const SQLITE = 'sqlite';

    /**
     * The engines this server offers, the one websites get by default first.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $installed = array_map('strtolower', (array) config('laravelify.databases'));
        $engines = [];

        foreach ([self::MYSQL, self::POSTGRESQL, self::MONGODB, self::SQLITE] as $engine) {
            if ($engine === self::SQLITE || in_array($engine, $installed, true) || ($engine === self::MYSQL && in_array('mariadb', $installed, true))) {
                $engines[] = $engine;
            }
        }

        return $engines;
    }

    public static function default(): string
    {
        return self::available()[0];
    }

    public static function has(string $engine): bool
    {
        return in_array($engine, self::available(), true);
    }

    public static function label(?string $engine): string
    {
        return match ($engine) {
            self::POSTGRESQL => 'PostgreSQL',
            self::MONGODB => 'MongoDB',
            self::SQLITE => 'SQLite',
            default => in_array('mariadb', array_map('strtolower', (array) config('laravelify.databases')), true) ? 'MariaDB' : 'MySQL',
        };
    }

    /** What each engine is, for the form. */
    public static function hint(string $engine): string
    {
        return match ($engine) {
            self::POSTGRESQL => 'A PostgreSQL database and user. Laravel gets DB_CONNECTION=pgsql; Node apps get DATABASE_URL.',
            self::MONGODB => 'A MongoDB database and user. Laravel (with mongodb/laravel-mongodb) gets MONGODB_URI; Node apps get DATABASE_URL.',
            self::SQLITE => 'A file in the site folder (.data/database.sqlite), kept between deploys. No server, for small apps.',
            default => 'A '.self::label($engine).' database and user. PHP gets DB_*; Node apps also get DATABASE_URL.',
        };
    }
}
