<?php

namespace App\Services;

use App\Models\Website;
use App\Support\DatabaseEngines;
use App\Support\EnvFile;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/**
 * A website's own database and user, in the engine it picked: MySQL or MariaDB, PostgreSQL,
 * MongoDB, or SQLite (a file in the site folder), and the .env values that reach it.
 */
class DatabaseProvisioner
{
    /** Where a website's SQLite database lives: in its folder, kept by deploys, and private (nginx refuses dot paths). */
    public const SQLITE_FILE = '.data/database.sqlite';

    /**
     * @return array{db_name: string, db_username: string, db_password: string}
     */
    public function credentials(string $slug): array
    {
        $name = str_replace('-', '_', $slug);

        // Short slugs keep their readable names. A long one is cut, so a short hash of the whole
        // slug keeps it apart from another that starts the same (shop and shop-development).
        for ($attempt = 0; ; $attempt++) {
            $hash = substr(sha1($slug.($attempt > 0 ? '#'.$attempt : '')), 0, 6);
            $database = strlen($name) <= 20 && $attempt === 0 ? 'lv_'.$name : 'lv_'.substr($name, 0, 13).'_'.$hash;
            $user = strlen($database) <= 16 ? $database.'_u' : substr($name, 0, 9).'_'.$hash.'_u';

            // Never a name another website already uses: creating it would take over that one's
            // database and reset its user's password.
            $taken = Website::query()->where('db_name', $database)->orWhere('db_username', $user)->exists();

            if (! $taken) {
                break;
            }
        }

        return [
            'db_name' => $database,
            'db_username' => $user,
            'db_password' => Str::password(24, symbols: false),
        ];
    }

    /**
     * Variables written into the site .env. PHP uses DB_* (MONGODB_* for MongoDB). Node servers
     * also get DATABASE_URL, which Prisma and most Node drivers read, pointing at 127.0.0.1 (TCP).
     * Keys of another engine are removed (null), so switching engines leaves no stale login.
     *
     * @return array<string, ?string>
     */
    public function envValues(Website $website): array
    {
        $engine = $website->db_engine ?: DatabaseEngines::MYSQL;

        if ($engine === DatabaseEngines::SQLITE) {
            $path = $this->sqlitePath($website);
            $values = ['DB_HOST' => null, 'DB_PORT' => null, 'DB_DATABASE' => $path, 'DB_USERNAME' => null, 'DB_PASSWORD' => null];

            return $website->isNode()
                ? ['DATABASE_URL' => 'file:'.$path] + $values
                : ['DB_CONNECTION' => 'sqlite'] + $values + self::redisValues($website);
        }

        if ($engine === DatabaseEngines::MONGODB) {
            $values = ['MONGODB_URI' => $this->url($website), 'MONGODB_DATABASE' => (string) $website->db_name];

            return $website->isNode()
                ? ['DATABASE_URL' => $this->url($website)] + $values
                : ['DB_CONNECTION' => 'mongodb'] + $values + self::redisValues($website);
        }

        $values = [
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => $engine === DatabaseEngines::POSTGRESQL ? '5432' : '3306',
            'DB_DATABASE' => (string) $website->db_name,
            'DB_USERNAME' => (string) $website->db_username,
            'DB_PASSWORD' => (string) $website->db_password,
        ];

        if ($website->isNode()) {
            return ['DATABASE_URL' => $this->url($website)] + $values;
        }

        return ['DB_CONNECTION' => $engine === DatabaseEngines::POSTGRESQL ? 'pgsql' : 'mysql'] + $values + self::redisValues($website);
    }

    /**
     * Websites share the server's Redis. Laravel names its keys after APP_NAME, which an
     * environment copies from its main website, so each site gets prefixes of its own: its
     * cache, sessions and queues never mix with another's.
     *
     * @return array<string, string>
     */
    public static function redisValues(Website $website): array
    {
        $prefix = str_replace('-', '_', (string) $website->slug);

        return [
            'REDIS_PREFIX' => $prefix.'_database_',
            'CACHE_PREFIX' => $prefix.'_cache_',
        ];
    }

    public function url(Website $website): string
    {
        $engine = $website->db_engine ?: DatabaseEngines::MYSQL;
        $user = rawurlencode((string) $website->db_username);
        $password = rawurlencode((string) $website->db_password);
        $name = rawurlencode((string) $website->db_name);

        return match ($engine) {
            DatabaseEngines::SQLITE => 'file:'.$this->sqlitePath($website),
            DatabaseEngines::POSTGRESQL => "postgresql://{$user}:{$password}@127.0.0.1:5432/{$name}",
            DatabaseEngines::MONGODB => "mongodb://{$user}:{$password}@127.0.0.1:27017/{$name}?authSource={$name}",
            default => "mysql://{$user}:{$password}@127.0.0.1:3306/{$name}",
        };
    }

    public function sqlitePath(Website $website): string
    {
        return rtrim((string) $website->deploy_path, '/').'/'.self::SQLITE_FILE;
    }

    /**
     * Give a website without a database one in this engine: its login, its .env (PHP gets its
     * usual template; a Node app keeps its own .env and gets the database variables, DATABASE_URL
     * for Prisma and the other drivers), then the database itself.
     */
    public function provisionFor(Website $website, string $engine): string
    {
        if (! $website->db_name) {
            $website->db_engine = DatabaseEngines::has($engine) ? $engine : DatabaseEngines::default();
            $website->fill($this->credentials($website->slug));
            $website->provision_database = true;
            $website->env_content = $website->isNode()
                ? EnvFile::set((string) $website->env_content, $this->envValues($website))
                : app(ConfigRenderer::class)->env($website);
            $website->save();
        }

        return $this->create($website);
    }

    public function create(Website $website): string
    {
        $engine = $website->db_engine ?: DatabaseEngines::MYSQL;

        if ($engine === DatabaseEngines::SQLITE) {
            return $this->createSqlite($website);
        }

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

        return match ($engine) {
            DatabaseEngines::POSTGRESQL => $this->createPostgres($website),
            DatabaseEngines::MONGODB => $this->createMongo($website, $runner),
            default => $this->createMysql($website, $runner),
        };
    }

    private function createMysql(Website $website, CommandRunner $runner): string
    {
        $password = str_replace("'", "''", $website->db_password);
        // localhost is the socket (PHP). 127.0.0.1 is TCP, which Node and Prisma use.
        $sql = sprintf(
            "CREATE DATABASE IF NOT EXISTS `%s`; CREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s'; ALTER USER '%s'@'localhost' IDENTIFIED BY '%s'; GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'localhost'; CREATE USER IF NOT EXISTS '%s'@'127.0.0.1' IDENTIFIED BY '%s'; ALTER USER '%s'@'127.0.0.1' IDENTIFIED BY '%s'; GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'127.0.0.1'; FLUSH PRIVILEGES;",
            $website->db_name,
            $website->db_username,
            $password,
            $website->db_username,
            $password,
            $website->db_name,
            $website->db_username,
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

    /**
     * The panel signs in as postgres (over TCP, with the password the installer set) and makes the
     * role and a database it owns. Running again resets the role's password.
     */
    private function createPostgres(Website $website): string
    {
        $password = (string) config('laravelify.postgres_password');

        if ($password === '' || ! extension_loaded('pdo_pgsql')) {
            throw new RuntimeException('PostgreSQL is not set up for the panel on this server. Install it with the installer (--databases=postgresql).');
        }

        try {
            $pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres', (string) config('laravelify.postgres_user', 'postgres'), $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $role = '"'.$website->db_username.'"';
            $secret = $pdo->quote((string) $website->db_password);
            $exists = $pdo->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
            $exists->execute([$website->db_username]);
            $pdo->exec(($exists->fetchColumn() ? 'ALTER ROLE ' : 'CREATE ROLE ').$role.' LOGIN PASSWORD '.$secret);

            $exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $exists->execute([$website->db_name]);

            if (! $exists->fetchColumn()) {
                $pdo->exec('CREATE DATABASE "'.$website->db_name.'" OWNER '.$role." ENCODING 'UTF8'");
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('PostgreSQL: '.$exception->getMessage(), 0, $exception);
        }

        return 'PostgreSQL database '.$website->db_name.' is ready.';
    }

    /**
     * The panel signs in as MongoDB's admin and makes a user that owns the website's database.
     * Passwords go through the environment, never the command line.
     */
    private function createMongo(Website $website, CommandRunner $runner): string
    {
        $password = (string) config('laravelify.mongodb_password');

        if ($password === '') {
            throw new RuntimeException('MongoDB is not set up for the panel on this server. Install it with the installer (--databases=mongodb).');
        }

        $script = <<<'JS'
            const admin = connect('mongodb://' + encodeURIComponent(process.env.LFY_ADMIN_USER) + ':' + encodeURIComponent(process.env.LFY_ADMIN) + '@127.0.0.1:27017/admin');
            const site = admin.getSiblingDB(process.env.LFY_DB);
            const user = { pwd: process.env.LFY_PASSWORD, roles: [{ role: 'dbOwner', db: process.env.LFY_DB }] };
            if (site.getUser(process.env.LFY_USER)) { site.updateUser(process.env.LFY_USER, user); }
            else { site.createUser(Object.assign({ user: process.env.LFY_USER }, user)); }
            JS;

        $result = $runner->run(['mongosh', '--quiet', '--nodb', '--eval', $script], null, [
            'LFY_ADMIN_USER' => (string) config('laravelify.mongodb_user', 'admin'),
            'LFY_ADMIN' => $password,
            'LFY_DB' => (string) $website->db_name,
            'LFY_USER' => (string) $website->db_username,
            'LFY_PASSWORD' => (string) $website->db_password,
        ]);

        if (! $result->ok) {
            throw new RuntimeException('MongoDB: '.($result->output ?: 'mongosh failed'));
        }

        return 'MongoDB database '.$website->db_name.' is ready.';
    }

    /** An empty SQLite file in the site folder, made by the site's own user so the site can write it. */
    private function createSqlite(Website $website): string
    {
        $path = $this->sqlitePath($website);
        $files = app(SiteFiles::class);

        if (! file_exists($path)) {
            $files->makeDirectory(dirname($path), 0750, true);
            $files->put($path, '', 0640);
        }

        return 'SQLite database '.$path.' is ready.';
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
