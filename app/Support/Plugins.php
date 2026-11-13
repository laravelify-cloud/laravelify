<?php

namespace App\Support;

use App\Plugins\MinioPlugin;
use App\Plugins\MongodbPlugin;
use App\Plugins\MysqlPlugin;
use App\Plugins\PluginDefinition;
use App\Plugins\PostgresPlugin;
use App\Plugins\RedisPlugin;
use App\Plugins\VaultPlugin;
use InvalidArgumentException;

/**
 * The services the Plugins page offers. A new plugin is one class in app/Plugins listed here.
 */
final class Plugins
{
    /** @var list<class-string<PluginDefinition>> */
    public const DEFINITIONS = [
        PostgresPlugin::class,
        RedisPlugin::class,
        MinioPlugin::class,
        VaultPlugin::class,
        MysqlPlugin::class,
        MongodbPlugin::class,
    ];

    /**
     * @return array<string, PluginDefinition>
     */
    public static function all(): array
    {
        $plugins = [];

        foreach (self::DEFINITIONS as $class) {
            $plugin = new $class;
            $plugins[$plugin->key()] = $plugin;
        }

        return $plugins;
    }

    public static function get(?string $key): ?PluginDefinition
    {
        return self::all()[(string) $key] ?? null;
    }

    public static function find(string $key): PluginDefinition
    {
        return self::get($key) ?? throw new InvalidArgumentException('Unknown plugin "'.$key.'". Plugins: '.implode(', ', array_keys(self::all())).'.');
    }
}
