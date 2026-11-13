<?php

namespace App\Plugins;

/**
 * Its own Redis in Docker with a password and append-only persistence, apart from the server's
 * Redis that Laravel sites use.
 */
class RedisPlugin extends PluginDefinition
{
    public function key(): string
    {
        return 'redis';
    }

    public function label(): string
    {
        return 'Redis';
    }

    public function description(): string
    {
        return 'A Redis 7 server in Docker with a password and data saved to disk, apart from the server\'s Redis.';
    }

    public function logo(): string
    {
        return 'R';
    }

    public function color(): string
    {
        return '#d82c20';
    }

    public function ports(): array
    {
        return ['redis' => 6380];
    }

    public function credentials(): array
    {
        return ['password' => self::secret(32)];
    }

    public function compose(array $ports): string
    {
        return <<<YAML
services:
  redis:
    image: redis:7-alpine
    command: ["sh", "-c", "exec redis-server --appendonly yes --requirepass \"\$\$REDIS_PASSWORD\""]
    restart: unless-stopped
    env_file: .env
    ports:
      - "127.0.0.1:{$ports['redis']}:6379"
    volumes:
      - data:/data
volumes:
  data:

YAML;
    }

    public function containerEnv(array $credentials): array
    {
        return ['REDIS_PASSWORD' => (string) $credentials['password']];
    }

    public function websiteEnv(array $ports, array $credentials): array
    {
        return [
            'REDIS_URL' => "redis://:{$credentials['password']}@127.0.0.1:{$ports['redis']}",
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => (string) $ports['redis'],
            'REDIS_PASSWORD' => (string) $credentials['password'],
        ];
    }

    public function shownCredentials(array $ports, array $credentials): array
    {
        return ['Host' => '127.0.0.1:'.$ports['redis'], 'Password' => (string) $credentials['password']];
    }
}
