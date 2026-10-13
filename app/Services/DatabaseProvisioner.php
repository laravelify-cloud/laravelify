<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseProvisioner
{
    /**
     * @return array{db_name: string, db_username: string, db_password: string}
     */
    public function credentials(string $slug): array
    {
        $base = 'lv_'.substr(str_replace('-', '_', $slug), 0, 20);

        return [
            'db_name' => $base,
            'db_username' => substr($base, 0, 16).'_u',
            'db_password' => Str::password(24, symbols: false),
        ];
    }

    public function create(Website $website): string
    {
        if (! $website->db_name || ! $website->db_username || ! $website->db_password) {
            throw new RuntimeException('Database credentials are missing.');
        }

        if (! preg_match('/^[A-Za-z0-9_]+$/', $website->db_name.$website->db_username)) {
            throw new RuntimeException('Database name or user is not safe to create.');
        }

        $runner = app(CommandRunner::class);

        if (! $runner->supportsHostCommands()) {
            return '[skipped] host commands are disabled';
        }

        $password = str_replace("'", "''", $website->db_password);
        $sql = sprintf(
            "CREATE DATABASE IF NOT EXISTS `%s`; CREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s'; ALTER USER '%s'@'localhost' IDENTIFIED BY '%s'; GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'localhost'; FLUSH PRIVILEGES;",
            $website->db_name,
            $website->db_username,
            $password,
            $website->db_username,
            $password,
            $website->db_name,
            $website->db_username,
        );

        $defaultsFile = $this->clientDefaultsFile();

        try {
            $result = $runner->run([
                'mysql',
                '--defaults-extra-file='.$defaultsFile,
                '--protocol=socket',
                '-e',
                $sql,
            ]);
        } finally {
            @unlink($defaultsFile);
        }

        if (! $result->ok) {
            throw new RuntimeException($result->output ?: 'mysql command failed');
        }

        return 'Database '.$website->db_name.' is ready.';
    }

    public function clientDefaultsFile(): string
    {
        $username = (string) config('database.connections.mysql.username');
        $password = (string) config('database.connections.mysql.password');
        $path = storage_path('app/mysql-client-'.bin2hex(random_bytes(8)).'.cnf');
        $quoted = str_replace(['\\', '"'], ['\\\\', '\\"'], $password);

        file_put_contents($path, "[client]\nuser={$username}\npassword=\"{$quoted}\"\n");
        @chmod($path, 0600);

        return $path;
    }
}
