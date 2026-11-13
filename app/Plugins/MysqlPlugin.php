<?php

namespace App\Plugins;

/**
 * Its own MySQL 8.4 in Docker with one database, apart from the server's MySQL the panel makes
 * website databases in.
 */
class MysqlPlugin extends PluginDefinition
{
    public function key(): string
    {
        return 'mysql';
    }

    public function label(): string
    {
        return 'MySQL';
    }

    public function description(): string
    {
        return 'A MySQL 8.4 server in Docker with one database, apart from the server\'s own databases.';
    }

    public function logo(): string
    {
        return 'My';
    }

    public function color(): string
    {
        return '#00758f';
    }

    public function ports(): array
    {
        return ['mysql' => 3307];
    }

    public function credentials(): array
    {
        return ['database' => 'app', 'username' => 'app', 'password' => self::secret(32), 'root_password' => self::secret(32)];
    }

    public function compose(array $ports): string
    {
        return <<<YAML
services:
  mysql:
    image: mysql:8.4
    restart: unless-stopped
    env_file: .env
    ports:
      - "127.0.0.1:{$ports['mysql']}:3306"
    volumes:
      - data:/var/lib/mysql
volumes:
  data:

YAML;
    }

    public function containerEnv(array $credentials): array
    {
        return [
            'MYSQL_DATABASE' => (string) $credentials['database'],
            'MYSQL_USER' => (string) $credentials['username'],
            'MYSQL_PASSWORD' => (string) $credentials['password'],
            'MYSQL_ROOT_PASSWORD' => (string) $credentials['root_password'],
        ];
    }

    public function websiteEnv(array $ports, array $credentials): array
    {
        $port = $ports['mysql'];
        ['database' => $database, 'username' => $username, 'password' => $password] = $credentials;

        return [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => (string) $port,
            'DB_DATABASE' => (string) $database,
            'DB_USERNAME' => (string) $username,
            'DB_PASSWORD' => (string) $password,
            'DATABASE_URL' => "mysql://{$username}:{$password}@127.0.0.1:{$port}/{$database}",
            'SPRING_DATASOURCE_URL' => "jdbc:mysql://127.0.0.1:{$port}/{$database}",
            'SPRING_DATASOURCE_USERNAME' => (string) $username,
            'SPRING_DATASOURCE_PASSWORD' => (string) $password,
        ];
    }

    public function shownCredentials(array $ports, array $credentials): array
    {
        return [
            'Host' => '127.0.0.1:'.$ports['mysql'],
            'Database' => (string) $credentials['database'],
            'Username' => (string) $credentials['username'],
            'Password' => (string) $credentials['password'],
            'Root password' => (string) $credentials['root_password'],
        ];
    }

    public function backupMethod(): string
    {
        return 'mysqldump';
    }
}
