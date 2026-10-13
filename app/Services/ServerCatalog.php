<?php

namespace App\Services;

class ServerCatalog
{
    /**
     * @return list<array{key: string, name: string, kind: string, version: ?string, config: string, unit: ?string}>
     */
    public static function definitions(): array
    {
        $services = [
            [
                'key' => 'mysql',
                'name' => 'MySQL 8.4 LTS',
                'kind' => 'mysql',
                'version' => '8.4',
                'unit' => 'mysql',
                'config' => <<<'CNF'
[mysqld]
bind-address = 127.0.0.1
mysqlx = 0
character-set-server = utf8mb4
collation-server = utf8mb4_general_ci
CNF,
            ],
            [
                'key' => 'redis',
                'name' => 'Redis',
                'kind' => 'redis',
                'version' => null,
                'unit' => 'redis-server',
                'config' => <<<'CONF'
bind 127.0.0.1
port 6379
supervised systemd
appendonly yes
CONF,
            ],
            [
                'key' => 'phpmyadmin',
                'name' => 'phpMyAdmin',
                'kind' => 'phpmyadmin',
                'version' => null,
                'unit' => 'nginx',
                'config' => self::phpMyAdminNginx(),
            ],
        ];

        foreach (config('laravelify.php_versions') as $version) {
            $services[] = [
                'key' => 'php-fpm-'.$version,
                'name' => 'PHP-FPM '.$version,
                'kind' => 'php-fpm',
                'version' => $version,
                'unit' => 'php'.$version.'-fpm',
                'config' => self::phpFpmPool($version),
            ];
        }

        return $services;
    }

    /**
     * @return array{domain: ?string, settings: ?array<string, string>}
     */
    public static function connectionDefaults(string $kind): array
    {
        return match ($kind) {
            'mysql' => [
                'domain' => '127.0.0.1',
                'settings' => ['bind' => '127.0.0.1', 'port' => '3306'],
            ],
            'redis' => [
                'domain' => '127.0.0.1',
                'settings' => ['bind' => '127.0.0.1', 'port' => '6379', 'password' => ''],
            ],
            'phpmyadmin' => [
                'domain' => 'phpmyadmin.internal',
                'settings' => ['php_version' => '8.4'],
            ],
            default => ['domain' => null, 'settings' => null],
        };
    }

    public static function phpMyAdminNginx(): string
    {
        return <<<'NGINX'
# phpMyAdmin, edited from the Laravelify server page.
# TLS stays at Cloudflare. This vhost listens on port 80.

server {
    listen 80;
    listen [::]:80;
    server_name phpmyadmin.internal;

    root /usr/share/phpmyadmin;
    index index.php;
    client_max_body_size 64M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    }

    /**
     * The nginx location that serves Laravelify's one-click sign-in script
     * (scripts/phpmyadmin-signon.php) on the phpMyAdmin domain.
     */
    public static function signonLocation(string $socket = 'unix:/run/php/php8.4-fpm.sock'): string
    {
        $script = base_path('scripts/phpmyadmin-signon.php');

        return <<<NGINX
    # Laravelify one-click sign-in (panel sidebar → phpMyAdmin)
    location = /laravelify-signon.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME {$script};
        fastcgi_param SCRIPT_NAME /laravelify-signon.php;
        fastcgi_pass {$socket};
    }

NGINX;
    }

    public static function hasSignonLocation(string $config): bool
    {
        return str_contains($config, 'location = /laravelify-signon.php');
    }

    /**
     * Add the sign-in location to an existing phpMyAdmin config, before the PHP location.
     */
    public static function withSignonLocation(string $config): string
    {
        if (self::hasSignonLocation($config)) {
            return $config;
        }

        $socket = preg_match('#unix:/run/php/php[0-9.]+-fpm\.sock#', $config, $match) === 1 ? $match[0] : 'unix:/run/php/php8.4-fpm.sock';
        $updated = preg_replace('/^[ \t]*(location ~ \\\\\.php\$ \{)/m', '    '.trim(self::signonLocation($socket))."\n\n    $1", $config, 1, $count);

        if (is_string($updated) && $count === 1) {
            return $updated;
        }

        // No PHP location found: add it before the closing brace of the server block.
        $position = strrpos($config, '}');

        return $position === false ? $config : substr($config, 0, $position).self::signonLocation($socket).substr($config, $position);
    }

    public static function phpFpmPool(string $version): string
    {
        return <<<INI
; PHP {$version} www pool. Apply replaces /etc/php/{$version}/fpm/pool.d/www.conf
; Sites use unix:/run/php/php{$version}-fpm.sock

[www]
user = www-data
group = www-data
listen = /run/php/php{$version}-fpm.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 20
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
INI;
    }
}
