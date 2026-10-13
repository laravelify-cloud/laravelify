<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ServicePassword
{
    public function apply(string $kind, string $password): void
    {
        if (! app(CommandRunner::class)->supportsHostCommands()) {
            return;
        }

        if ($kind === 'mysql') {
            $this->changeMysqlRoot($password);
        }

        if ($kind === 'redis') {
            $this->rememberRedisPassword($password);
        }
    }

    private function changeMysqlRoot(string $password): void
    {
        if ($password === '') {
            return;
        }

        $escaped = str_replace("'", "''", $password);
        $sql = "ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '{$escaped}'; "
            ."CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY '{$escaped}'; "
            ."ALTER USER 'root'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY '{$escaped}'; "
            ."GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION; FLUSH PRIVILEGES;";

        $this->mysql($sql);

        config(['database.connections.mysql.password' => $password]);
        DB::purge('mysql');
        $this->patchPanel('DB_PASSWORD', $password);
        $this->schedulePanelRestart();
    }

    private function rememberRedisPassword(string $password): void
    {
        $value = $password === '' ? null : $password;
        config([
            'database.redis.default.password' => $value,
            'database.redis.cache.password' => $value,
        ]);

        try {
            app('redis')->purge('default');
            app('redis')->purge('cache');
        } catch (Throwable) {
            // The next request opens a new connection with the password above.
        }

        $this->patchPanel('REDIS_PASSWORD', $password === '' ? 'null' : $password);
        $this->schedulePanelRestart();
    }

    private function mysql(string $sql): void
    {
        $runner = app(CommandRunner::class);
        $defaultsFile = storage_path('app/mysql-client-'.bin2hex(random_bytes(8)).'.cnf');
        $password = (string) config('database.connections.mysql.password');
        $quoted = str_replace(['\\', '"'], ['\\\\', '\\"'], $password);
        $username = (string) config('database.connections.mysql.username');
        file_put_contents($defaultsFile, "[client]\nuser={$username}\npassword=\"{$quoted}\"\n");
        @chmod($defaultsFile, 0600);

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
            throw new RuntimeException(trim($result->output) ?: 'MySQL rejected the new root password.');
        }
    }

    private function patchPanel(string $key, string $value): void
    {
        $env = base_path('.env');
        if (is_file($env)) {
            $this->patchLine($env, '/^'.preg_quote($key, '/').'=.*/m', $key.'='.$value);
        }

        $unit = '/etc/systemd/system/laravelify-panel.service';
        if (is_file($unit)) {
            $this->patchLine($unit, '/^Environment='.preg_quote($key, '/').'=.*/m', 'Environment='.$key.'='.$value);
        }
    }

    private function patchLine(string $path, string $pattern, string $line): void
    {
        $contents = (string) file_get_contents($path);

        if (preg_match($pattern, $contents)) {
            $contents = (string) preg_replace($pattern, $line, $contents, 1);
        } else {
            $contents = rtrim($contents)."\n".$line."\n";
        }

        file_put_contents($path, $contents);
    }

    private function schedulePanelRestart(): void
    {
        if (! is_file('/etc/systemd/system/laravelify-panel.service')) {
            return;
        }

        exec('bash -c '.escapeshellarg('sleep 2; systemctl daemon-reload; systemctl restart laravelify-panel.service').' >/dev/null 2>&1 &');
    }
}
