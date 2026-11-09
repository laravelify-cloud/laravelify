<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * PHP extensions of one PHP version on this server. Turning one on or off changes it for every
 * website on that version: PHP-FPM loads extensions per version, not per site.
 */
class PhpExtensions
{
    /**
     * Extensions that can be turned on and off, with the Ubuntu package (php<version>-<package>)
     * that brings them when they are not installed yet. "common" ones come with PHP itself.
     *
     * @return array<string, array{label: string, package: string, help: string}>
     */
    public static function catalog(): array
    {
        $list = [
            'amqp' => ['amqp', 'RabbitMQ and other AMQP queues.'],
            'apcu' => ['apcu', 'In-memory cache for one server.'],
            'bcmath' => ['bcmath', 'Exact decimal math (money).'],
            'bz2' => ['bz2', 'Bzip2 files.'],
            'calendar' => ['common', 'Calendar conversions.'],
            'ctype' => ['common', 'Character type checks.'],
            'curl' => ['curl', 'HTTP requests.'],
            'dba' => ['dba', 'Berkeley-style key-value files.'],
            'decimal' => ['decimal', 'Arbitrary precision decimals.'],
            'dom' => ['xml', 'XML documents (DOM).'],
            'ds' => ['ds', 'Fast data structures.'],
            'enchant' => ['enchant', 'Spell checking.'],
            'event' => ['event', 'libevent event loop.'],
            'exif' => ['common', 'Photo metadata.'],
            'ffi' => ['common', 'Call C libraries from PHP.'],
            'fileinfo' => ['common', 'File type detection.'],
            'ftp' => ['common', 'FTP client.'],
            'gd' => ['gd', 'Images: resize, crop, thumbnails.'],
            'gettext' => ['common', 'Translations with .mo files.'],
            'gmp' => ['gmp', 'Very large numbers.'],
            'gnupg' => ['gnupg', 'GnuPG encryption.'],
            'grpc' => ['grpc', 'gRPC clients.'],
            'http' => ['http', 'pecl_http requests.'],
            'iconv' => ['common', 'Character set conversion.'],
            'igbinary' => ['igbinary', 'Compact serializer (Redis, Memcached).'],
            'imagick' => ['imagick', 'ImageMagick images.'],
            'imap' => ['imap', 'Reading mailboxes.'],
            'inotify' => ['inotify', 'File change events.'],
            'intl' => ['intl', 'Languages, dates and number formats.'],
            'ldap' => ['ldap', 'LDAP and Active Directory logins.'],
            'lz4' => ['lz4', 'LZ4 compression.'],
            'mailparse' => ['mailparse', 'Parsing emails.'],
            'maxminddb' => ['maxminddb', 'GeoIP2 lookups.'],
            'mbstring' => ['mbstring', 'Unicode strings.'],
            'mcrypt' => ['mcrypt', 'Old encryption (legacy code only).'],
            'memcache' => ['memcache', 'Memcached (old client).'],
            'memcached' => ['memcached', 'Memcached.'],
            'mongodb' => ['mongodb', 'MongoDB.'],
            'msgpack' => ['msgpack', 'MessagePack serializer.'],
            'mysqli' => ['mysql', 'MySQL and MariaDB (old style).'],
            'mysqlnd' => ['mysql', 'MySQL native driver (mysqli and pdo_mysql need it).'],
            'oauth' => ['oauth', 'OAuth 1 clients.'],
            'odbc' => ['odbc', 'ODBC databases.'],
            'opcache' => ['opcache', 'Keeps compiled PHP in memory: much faster.'],
            'pcov' => ['pcov', 'Code coverage for tests.'],
            'pdo' => ['common', 'Database access layer (every pdo_ driver needs it).'],
            'pdo_dblib' => ['sybase', 'SQL Server and Sybase (FreeTDS).'],
            'pdo_mysql' => ['mysql', 'MySQL and MariaDB.'],
            'pdo_odbc' => ['odbc', 'ODBC through PDO.'],
            'pdo_pgsql' => ['pgsql', 'PostgreSQL through PDO.'],
            'pdo_sqlite' => ['sqlite3', 'SQLite through PDO.'],
            'pgsql' => ['pgsql', 'PostgreSQL.'],
            'phar' => ['common', 'PHAR archives (Composer).'],
            'posix' => ['common', 'Unix process and user functions.'],
            'protobuf' => ['protobuf', 'Protocol Buffers.'],
            'ps' => ['ps', 'PostScript files.'],
            'pspell' => ['pspell', 'Spell checking (Aspell).'],
            'psr' => ['psr', 'PSR interfaces (Phalcon needs it).'],
            'raphf' => ['raphf', 'Persistent handles (pecl_http needs it).'],
            'rdkafka' => ['rdkafka', 'Kafka.'],
            'readline' => ['readline', 'Interactive shells (php -a, tinker).'],
            'redis' => ['redis', 'Redis cache, queues and sessions.'],
            'rrd' => ['rrd', 'RRDtool graphs.'],
            'shmop' => ['common', 'Shared memory.'],
            'simplexml' => ['xml', 'Simple XML reading.'],
            'smbclient' => ['smbclient', 'Windows shares (SMB).'],
            'snmp' => ['snmp', 'SNMP.'],
            'soap' => ['soap', 'SOAP web services.'],
            'sockets' => ['common', 'Low-level sockets.'],
            'solr' => ['solr', 'Apache Solr.'],
            'sqlite3' => ['sqlite3', 'SQLite.'],
            'sqlsrv' => ['sqlsrv-pecl', 'Microsoft SQL Server. Installs Microsoft ODBC Driver 18 (you accept Microsoft\'s license) and builds it with PECL: a few minutes.'],
            'pdo_sqlsrv' => ['sqlsrv-pecl', 'Microsoft SQL Server through PDO (Laravel\'s sqlsrv connection). Installs Microsoft ODBC Driver 18 (you accept Microsoft\'s license) and builds it with PECL.'],
            'ssh2' => ['ssh2', 'SSH and SFTP.'],
            'stomp' => ['stomp', 'STOMP message queues.'],
            'swoole' => ['swoole', 'Async server (Octane with Swoole).'],
            'sysvmsg' => ['common', 'System V message queues.'],
            'sysvsem' => ['common', 'System V semaphores.'],
            'sysvshm' => ['common', 'System V shared memory.'],
            'tidy' => ['tidy', 'Cleaning HTML.'],
            'tokenizer' => ['common', 'PHP tokenizer (Laravel needs it).'],
            'uopz' => ['uopz', 'Changing code at runtime (tests).'],
            'uploadprogress' => ['uploadprogress', 'Upload progress bars.'],
            'uuid' => ['uuid', 'UUIDs from libuuid.'],
            'vips' => ['vips', 'Fast image processing (libvips).'],
            'xdebug' => ['xdebug', 'Debugger. Slow: never on a live site.'],
            'xhprof' => ['xhprof', 'Profiler.'],
            'xml' => ['xml', 'XML parser.'],
            'xmlreader' => ['xml', 'Streaming XML reader.'],
            'xmlrpc' => ['xmlrpc', 'XML-RPC.'],
            'xmlwriter' => ['xml', 'Streaming XML writer.'],
            'xsl' => ['xml', 'XSL transforms.'],
            'yaml' => ['yaml', 'YAML.'],
            'zip' => ['zip', 'Zip files.'],
            'zmq' => ['zmq', 'ZeroMQ.'],
            'zstd' => ['zstd', 'Zstandard compression.'],
        ];

        return collect($list)->map(fn (array $item, string $module) => ['label' => $module, 'package' => $item[0], 'help' => $item[1]])->all();
    }

    /**
     * Each catalog extension: installed (its .ini exists) and on (loaded by PHP-FPM).
     *
     * @return list<array{module: string, label: string, help: string, installed: bool, enabled: bool}>
     */
    public function forVersion(string $version): array
    {
        $root = "/etc/php/{$version}";

        return collect(self::catalog())->map(fn (array $extension, string $module) => [
            'module' => $module,
            'label' => $extension['label'],
            'help' => $extension['help'],
            'installed' => File::exists("{$root}/mods-available/{$module}.ini"),
            'enabled' => (File::glob("{$root}/fpm/conf.d/*-{$module}.ini") ?: []) !== [],
        ])->values()->all();
    }

    /**
     * Extensions compiled into this PHP version: always on, they cannot be turned off.
     *
     * @return list<string>
     */
    public function builtIn(string $version): array
    {
        $binary = "/usr/bin/php{$version}";

        if (! preg_match('/^\d\.\d$/', $version) || ! is_executable($binary)) {
            return [];
        }

        $process = new Process([$binary, '-n', '-m']);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        return collect(preg_split('/\R/', $process->getOutput()) ?: [])
            ->map(fn (string $line) => strtolower(trim($line)))
            ->filter(fn (string $line) => preg_match('/^[a-z][a-z0-9_ ]*$/', $line) === 1 && ! File::exists("/etc/php/{$version}/mods-available/{$line}.ini"))
            ->reject(fn (string $line) => isset(self::catalog()[$line]))
            ->unique()->sort()->values()->all();
    }

    /** Turn one on (installing its package first when needed) or off for this PHP version. */
    public function set(string $version, string $module, bool $on, ?callable $onStep = null): void
    {
        $extension = self::catalog()[$module] ?? throw new RuntimeException("Unknown extension {$module}.");

        app(HostWriter::class)->phpExtension($version, $module, $on, $extension['package'], $onStep);
    }

    /**
     * Apply several changes (module => on) and say what happened. One that fails (no package for
     * this PHP version) does not stop the others.
     *
     * @param  array<string, bool>  $changes
     * @return array{message: string, failed?: bool}
     */
    public function apply(string $version, array $changes, ?callable $progress = null, ?callable $onStep = null): array
    {
        $done = [];
        $failed = [];
        $step = 0;

        // Turned off first, then on: an extension that needs another is turned on after it.
        uksort($changes, fn (string $a, string $b) => [$changes[$a], $a] <=> [$changes[$b], $b]);

        foreach ($changes as $module => $on) {
            try {
                $this->set($version, $module, $on, $onStep);
                $done[] = ($on ? '+' : '−').$module;
            } catch (\Throwable $exception) {
                $failed[] = $module.' ('.trim(strtok($exception->getMessage(), "\n") ?: 'failed').')';
            }

            $progress && $progress(++$step, count($changes));
        }

        $message = 'PHP '.$version.': '.($done !== [] ? implode(', ', $done) : 'nothing changed').'.';

        return $failed === []
            ? ['message' => $message]
            : ['message' => $message.' Not changed: '.implode('; ', $failed).'.', 'failed' => $done === []];
    }
}
