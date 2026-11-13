<?php

namespace App\Plugins;

/**
 * Its own PostgreSQL in Docker, apart from the server's PostgreSQL the panel makes website
 * databases in.
 */
class PostgresPlugin extends PluginDefinition
{
    public function key(): string
    {
        return 'postgres';
    }

    public function label(): string
    {
        return 'PostgreSQL';
    }

    public function description(): string
    {
        return 'A PostgreSQL 17 server in Docker with one database, apart from the server\'s own databases.';
    }

    public function logo(): string
    {
        return 'PG';
    }

    public function color(): string
    {
        return '#336791';
    }

    public function ports(): array
    {
        return ['postgres' => 5433];
    }

    public function credentials(): array
    {
        return ['database' => 'app', 'username' => 'app', 'password' => self::secret(32)];
    }

    public function compose(array $ports): string
    {
        return <<<YAML
services:
  postgres:
    image: postgres:17-alpine
    restart: unless-stopped
    env_file: .env
    ports:
      - "127.0.0.1:{$ports['postgres']}:5432"
    volumes:
      - data:/var/lib/postgresql/data
volumes:
  data:

YAML;
    }

    public function containerEnv(array $credentials): array
    {
        return [
            'POSTGRES_DB' => (string) $credentials['database'],
            'POSTGRES_USER' => (string) $credentials['username'],
            'POSTGRES_PASSWORD' => (string) $credentials['password'],
        ];
    }

    public function websiteEnv(array $ports, array $credentials): array
    {
        $port = $ports['postgres'];
        ['database' => $database, 'username' => $username, 'password' => $password] = $credentials;

        return [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => (string) $port,
            'DB_DATABASE' => (string) $database,
            'DB_USERNAME' => (string) $username,
            'DB_PASSWORD' => (string) $password,
            'DATABASE_URL' => "postgresql://{$username}:{$password}@127.0.0.1:{$port}/{$database}",
            'SPRING_DATASOURCE_URL' => "jdbc:postgresql://127.0.0.1:{$port}/{$database}",
            'SPRING_DATASOURCE_USERNAME' => (string) $username,
            'SPRING_DATASOURCE_PASSWORD' => (string) $password,
        ];
    }

    public function shownCredentials(array $ports, array $credentials): array
    {
        return [
            'Host' => '127.0.0.1:'.$ports['postgres'],
            'Database' => (string) $credentials['database'],
            'Username' => (string) $credentials['username'],
            'Password' => (string) $credentials['password'],
        ];
    }

    public function backupMethod(): string
    {
        return 'pg_dump';
    }
}
