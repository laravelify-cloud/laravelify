<?php

namespace App\Models;

use Database\Factories\DatabaseConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A database outside this server the Database tool opens: SQL Server, MySQL or MariaDB, or
 * PostgreSQL, reached over the network with its own login. The password is stored encrypted.
 */
class DatabaseConnection extends Model
{
    /** @use HasFactory<DatabaseConnectionFactory> */
    use HasFactory;

    public const DRIVERS = [
        'sqlsrv' => ['label' => 'Microsoft SQL Server', 'short' => 'SQL Server', 'port' => 1433, 'extension' => 'pdo_sqlsrv'],
        'mysql' => ['label' => 'MySQL / MariaDB', 'short' => 'MySQL', 'port' => 3306, 'extension' => 'pdo_mysql'],
        'pgsql' => ['label' => 'PostgreSQL', 'short' => 'PostgreSQL', 'port' => 5432, 'extension' => 'pdo_pgsql'],
        'sqlite' => ['label' => 'SQLite file', 'short' => 'SQLite', 'port' => 0, 'extension' => 'pdo_sqlite'],
    ];

    /** Files the SQLite picker shows. */
    public const SQLITE_EXTENSIONS = ['sqlite', 'sqlite3', 'db', 'db3', 's3db', 'sl3'];

    /** SSL for MySQL and PostgreSQL, as PostgreSQL names the modes. */
    public const SSL_MODES = [
        'disable' => 'Off',
        'prefer' => 'If the server offers it',
        'require' => 'Required',
        'verify-full' => 'Required, and check the certificate',
    ];

    /** Colors to tell connections apart (a production one in red, say). */
    public const COLORS = ['red' => '#ef4444', 'orange' => '#f97316', 'yellow' => '#eab308', 'green' => '#22c55e', 'blue' => '#3b82f6', 'purple' => '#a855f7'];

    protected $fillable = ['name', 'slug', 'driver', 'host', 'port', 'instance', 'database', 'username', 'password', 'encrypt', 'trust_certificate', 'ssl_mode', 'read_only', 'timeout', 'color'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'port' => 'integer',
            'encrypt' => 'boolean',
            'trust_certificate' => 'boolean',
            'read_only' => 'boolean',
            'timeout' => 'integer',
        ];
    }

    public function driverLabel(): string
    {
        return self::DRIVERS[$this->driver]['short'] ?? $this->driver;
    }

    /** Whether the panel's PHP has the driver for it (pdo_sqlsrv for SQL Server). */
    public function driverLoaded(): bool
    {
        return extension_loaded(self::DRIVERS[$this->driver]['extension'] ?? 'none');
    }

    /** The server as people write it: host:port, or host\instance for a named SQL Server. */
    public function address(): string
    {
        if ($this->driver === 'sqlite') {
            return basename((string) $this->database);
        }

        if ($this->driver === 'sqlsrv' && $this->instance) {
            return $this->host.'\\'.$this->instance.($this->port ? ','.$this->port : '');
        }

        return $this->host.':'.$this->port;
    }

    /** A URL for this connection without the password, shown under the fields. */
    public function url(): string
    {
        $user = $this->username !== '' && $this->username !== null ? rawurlencode((string) $this->username).'@' : '';

        return match ($this->driver) {
            'sqlite' => 'sqlite:'.$this->database,
            'sqlsrv' => 'sqlserver://'.$user.$this->address().'/'.rawurlencode((string) $this->database),
            'mysql' => 'mysql://'.$user.$this->host.':'.$this->port.'/'.rawurlencode((string) $this->database),
            default => 'postgresql://'.$user.$this->host.':'.$this->port.'/'.rawurlencode((string) $this->database),
        };
    }

    /**
     * Settings read from a connection string: an ADO.NET one (SQL Server Management Studio,
     * appsettings.json), a JDBC URL (DataGrip) or a URL like mysql://user:pass@host:3306/db.
     * Only what the string has is returned.
     *
     * @return array<string, string|int|bool>
     */
    public static function parse(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        if (preg_match('#^(jdbc:)?(sqlserver|mysql|mariadb|postgres|postgresql)://#i', $text, $scheme)) {
            $driver = match (strtolower($scheme[2])) {
                'sqlserver' => 'sqlsrv',
                'mysql', 'mariadb' => 'mysql',
                default => 'pgsql',
            };
            $rest = substr($text, strlen($scheme[0]));

            // JDBC SQL Server: host\instance:port;databaseName=x;user=y;encrypt=true
            if ($driver === 'sqlsrv' && ! str_contains(strtok($rest, ';') ?: '', '/')) {
                $parts = explode(';', $rest);
                $values = ['driver' => 'sqlsrv'] + self::server(array_shift($parts) ?: '', ':');

                foreach ($parts as $part) {
                    [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
                    $values += self::adoValue(strtolower(trim($key)), trim($value));
                }

                return $values;
            }

            $url = parse_url($scheme[2].'://'.$rest) ?: [];
            $values = ['driver' => $driver];
            isset($url['host']) && $values['host'] = rawurldecode($url['host']);
            isset($url['port']) && $values['port'] = (int) $url['port'];
            isset($url['user']) && $values['username'] = rawurldecode($url['user']);
            isset($url['pass']) && $values['password'] = rawurldecode($url['pass']);
            isset($url['path']) && trim($url['path'], '/') !== '' && $values['database'] = rawurldecode(trim($url['path'], '/'));
            parse_str($url['query'] ?? '', $query);

            if (isset($query['sslmode']) && isset(self::SSL_MODES[$query['sslmode']])) {
                $values['ssl_mode'] = $query['sslmode'];
            }

            return $values;
        }

        // ADO.NET: Data Source=.\SQLEXPRESS;Initial Catalog=erp;User ID=sa;Password=…;Encrypt=True
        $values = [];

        foreach (preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $text) ?: [] as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $key = strtolower(preg_replace('/\s+/', ' ', trim($key)) ?? '');
            $value = trim(trim($value), '"');

            if ($key === 'data source' || $key === 'server' || $key === 'address' || $key === 'addr') {
                $values += self::server(preg_replace('/^tcp:/i', '', $value) ?? $value, ',');
            } else {
                $values += self::adoValue($key, $value);
            }
        }

        return $values === [] ? [] : ['driver' => 'sqlsrv'] + $values;
    }

    /**
     * "host\instance,port", "(local)\SQLEXPRESS", ".\SQLEXPRESS" or "host:port".
     *
     * @return array<string, string|int>
     */
    private static function server(string $server, string $portSeparator): array
    {
        $values = [];

        if (str_contains($server, $portSeparator)) {
            [$server, $port] = explode($portSeparator, $server, 2);
            $values['port'] = (int) $port;
        }

        if (str_contains($server, '\\')) {
            [$server, $instance] = explode('\\', $server, 2);
            $values['instance'] = $instance;
            $values += ['port' => 0];
        }

        $values['host'] = in_array(strtolower($server), ['.', '(local)', '(localdb)', 'localhost'], true) ? 'localhost' : $server;

        return $values;
    }

    /**
     * @return array<string, string|bool>
     */
    private static function adoValue(string $key, string $value): array
    {
        $true = in_array(strtolower($value), ['true', 'yes', '1', 'mandatory', 'strict'], true);

        return match ($key) {
            'initial catalog', 'database', 'databasename' => ['database' => $value],
            'user id', 'uid', 'user', 'username' => ['username' => $value],
            'password', 'pwd' => ['password' => $value],
            'encrypt' => ['encrypt' => $true],
            'trustservercertificate', 'trust server certificate' => ['trust_certificate' => $true],
            'instancename' => ['instance' => $value],
            'portnumber', 'port' => ['port' => (int) $value],
            'integrated security', 'trusted_connection' => $true || strtolower($value) === 'sspi' ? ['integrated' => true] : [],
            default => [],
        };
    }
}
