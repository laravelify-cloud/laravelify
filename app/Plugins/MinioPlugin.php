<?php

namespace App\Plugins;

class MinioPlugin extends PluginDefinition
{
    public function key(): string
    {
        return 'minio';
    }

    public function label(): string
    {
        return 'MinIO';
    }

    public function description(): string
    {
        return 'S3-compatible object storage for uploads, files, and backups, with a web console.';
    }

    public function logo(): string
    {
        return 'S3';
    }

    public function color(): string
    {
        return '#c72c48';
    }

    public function ports(): array
    {
        return ['api' => 9000, 'console' => 9001];
    }

    public function credentials(): array
    {
        return ['access_key' => 'lfy'.strtolower(self::secret(17)), 'secret_key' => self::secret(40)];
    }

    public function compose(array $ports): string
    {
        return <<<YAML
services:
  minio:
    image: pgsty/minio:RELEASE.2026-08-04T00-00-00Z
    command: ["server", "/data", "--console-address", ":9001"]
    restart: unless-stopped
    env_file: .env
    ports:
      - "127.0.0.1:{$ports['api']}:9000"
      - "127.0.0.1:{$ports['console']}:9001"
    volumes:
      - data:/data
volumes:
  data:

YAML;
    }

    public function containerEnv(array $credentials): array
    {
        return ['MINIO_ROOT_USER' => (string) $credentials['access_key'], 'MINIO_ROOT_PASSWORD' => (string) $credentials['secret_key']];
    }

    public function websiteEnv(array $ports, array $credentials): array
    {
        return [
            'MINIO_ENDPOINT' => 'http://127.0.0.1:'.$ports['api'],
            'MINIO_ACCESS_KEY' => (string) $credentials['access_key'],
            'MINIO_SECRET_KEY' => (string) $credentials['secret_key'],
        ];
    }

    public function shownCredentials(array $ports, array $credentials): array
    {
        return [
            'Endpoint' => 'http://127.0.0.1:'.$ports['api'],
            'Console' => 'http://127.0.0.1:'.$ports['console'],
            'Access key' => (string) $credentials['access_key'],
            'Secret key' => (string) $credentials['secret_key'],
        ];
    }

    public function webPort(): ?string
    {
        return 'console';
    }
}
