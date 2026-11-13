<?php

namespace App\Plugins;

/**
 * MongoDB 8 in Docker with sign-in on, for apps that store documents.
 */
class MongodbPlugin extends PluginDefinition
{
    public function key(): string
    {
        return 'mongodb';
    }

    public function label(): string
    {
        return 'MongoDB';
    }

    public function description(): string
    {
        return 'A MongoDB 8 server in Docker with sign-in turned on and one database for your app.';
    }

    public function logo(): string
    {
        return 'Mg';
    }

    public function color(): string
    {
        return '#00684a';
    }

    public function ports(): array
    {
        return ['mongodb' => 27018];
    }

    public function credentials(): array
    {
        return ['database' => 'app', 'username' => 'admin', 'password' => self::secret(32)];
    }

    public function compose(array $ports): string
    {
        return <<<YAML
services:
  mongodb:
    image: mongo:8.0
    restart: unless-stopped
    env_file: .env
    ports:
      - "127.0.0.1:{$ports['mongodb']}:27017"
    volumes:
      - data:/data/db
volumes:
  data:

YAML;
    }

    public function containerEnv(array $credentials): array
    {
        return [
            'MONGO_INITDB_ROOT_USERNAME' => (string) $credentials['username'],
            'MONGO_INITDB_ROOT_PASSWORD' => (string) $credentials['password'],
            'MONGO_INITDB_DATABASE' => (string) $credentials['database'],
        ];
    }

    public function websiteEnv(array $ports, array $credentials): array
    {
        $uri = 'mongodb://'.$credentials['username'].':'.$credentials['password'].'@127.0.0.1:'.$ports['mongodb'].'/'.$credentials['database'].'?authSource=admin';

        return [
            'MONGODB_URI' => $uri,
            'MONGO_URL' => $uri,
            'SPRING_DATA_MONGODB_URI' => $uri,
        ];
    }

    public function shownCredentials(array $ports, array $credentials): array
    {
        return [
            'Host' => '127.0.0.1:'.$ports['mongodb'],
            'Database' => (string) $credentials['database'],
            'Username' => (string) $credentials['username'],
            'Password' => (string) $credentials['password'],
        ];
    }

    public function backupMethod(): string
    {
        return 'mongodump';
    }
}
