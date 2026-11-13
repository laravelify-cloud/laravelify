<?php

namespace App\Plugins;

/**
 * One service the Plugins page installs with Docker: its compose file, the credentials made on
 * install, and the variables a linked website gets. Add a plugin by adding a class here and
 * listing it in App\Support\Plugins.
 *
 * Every compose file is checked by host-apply.sh (plugin-up) before it runs: ports are bound to
 * 127.0.0.1 only, volumes are named Docker volumes, and nothing runs privileged or on the host
 * network.
 */
abstract class PluginDefinition
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /** A short word for the card's logo badge. */
    abstract public function logo(): string;

    /** The colour of the logo badge. */
    public function color(): string
    {
        return 'var(--color-primary)';
    }

    /**
     * The ports this plugin needs, by name, with the port it prefers. The first is the main one.
     *
     * @return array<string, int>
     */
    abstract public function ports(): array;

    /**
     * Credentials made on install, saved encrypted. Values only use letters and numbers, so they
     * are safe in a .env file and in a URL.
     *
     * @return array<string, string>
     */
    abstract public function credentials(): array;

    /**
     * The compose file, with the ports picked for this server.
     *
     * @param  array<string, int>  $ports
     */
    abstract public function compose(array $ports): string;

    /**
     * Variables the container reads from the plugin's .env (root-only on the server).
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, string>
     */
    abstract public function containerEnv(array $credentials): array;

    /**
     * Variables a linked website gets.
     *
     * @param  array<string, int>  $ports
     * @param  array<string, mixed>  $credentials
     * @return array<string, string>
     */
    abstract public function websiteEnv(array $ports, array $credentials): array;

    /**
     * What the credentials dialog shows, label => value.
     *
     * @param  array<string, int>  $ports
     * @param  array<string, mixed>  $credentials
     * @return array<string, string>
     */
    public function shownCredentials(array $ports, array $credentials): array
    {
        return array_map('strval', array_filter($credentials, 'is_scalar'));
    }

    /** How a backup saves this plugin's data: "pg_dump" or "volume". */
    public function backupMethod(): string
    {
        return 'volume';
    }

    /** The port (by name) of its web page, which a domain can open; null when it has none. */
    public function webPort(): ?string
    {
        return null;
    }

    /** Whether websites can be linked to it (it gives them variables). */
    public function linkable(): bool
    {
        return true;
    }

    /** Vault needs init and unseal; the others start ready. */
    public function sealable(): bool
    {
        return false;
    }

    protected static function secret(int $length = 32): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }
}
