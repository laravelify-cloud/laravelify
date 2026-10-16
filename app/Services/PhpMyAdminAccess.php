<?php

namespace App\Services;

use App\Models\ServerService;
use RuntimeException;

/**
 * Puts the access prompt in front of phpMyAdmin. phpMyAdmin is its own app, so nginx asks
 * the panel first (auth_request). nginx passes the prompt of a 401 on by itself, and the pass
 * cookie through add_header.
 * The block sits between two marker lines in phpMyAdmin's nginx file, so it can be taken out again.
 */
class PhpMyAdminAccess
{
    public const START = '    # laravelify-access:start (Security > Access in the panel)';

    public const END = '    # laravelify-access:end';

    public function __construct(private HostWriter $host) {}

    public function service(): ?ServerService
    {
        return ServerService::query()->where('kind', 'phpmyadmin')->first();
    }

    /**
     * Write phpMyAdmin's nginx file with or without the prompt, then reload nginx.
     */
    public function apply(bool $enabled): void
    {
        $service = $this->service() ?? throw new RuntimeException('phpMyAdmin is not set up on this server.');
        $config = $this->withGate($service->config, $enabled, self::panelPhpVersion(), base_path());

        if ($config === $service->config) {
            return;
        }

        $previous = $service->config;
        $service->update(['config' => $config]);

        try {
            $this->host->writeService($service);
        } catch (RuntimeException $exception) {
            $service->update(['config' => $previous]);

            throw $exception;
        }
    }

    public function withGate(string $config, bool $enabled, string $phpVersion, string $panelRoot): string
    {
        $config = $this->withoutGate($config);

        if (! $enabled) {
            return $config;
        }

        $block = $this->block($phpVersion, $panelRoot);
        $placed = preg_replace('/^(\s*server_name\s+[^;]+;[^\n]*\n)/m', '$1'."\n".str_replace(['\\', '$'], ['\\\\', '\\$'], $block), $config, 1, $count);

        if ($count !== 1) {
            throw new RuntimeException('phpMyAdmin\'s nginx file has no server_name line, so the prompt cannot be added. Check it under Server → phpMyAdmin.');
        }

        return (string) $placed;
    }

    public function withoutGate(string $config): string
    {
        return (string) preg_replace('/\n'.preg_quote(self::START, '/').'.*?'.preg_quote(self::END, '/').'\n/s', '', $config);
    }

    /**
     * The check runs the panel's code, so it uses the panel's PHP, not phpMyAdmin's.
     */
    public static function panelPhpVersion(): string
    {
        return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    }

    public function hasGate(string $config): bool
    {
        return str_contains($config, self::START);
    }

    private function block(string $phpVersion, string $panelRoot): string
    {
        $root = rtrim(str_replace('\\', '/', $panelRoot), '/');

        return self::START."\n"
            ."    auth_request /__laravelify_access;\n"
            ."    auth_request_set \$laravelify_pass \$upstream_http_set_cookie;\n"
            ."    add_header Set-Cookie \$laravelify_pass always;\n"
            ."\n"
            ."    location = /__laravelify_access {\n"
            ."        internal;\n"
            ."        auth_request off;\n"
            .'        fastcgi_pass '.ServerCatalog::panelSocket($phpVersion).";\n"
            ."        fastcgi_pass_request_body off;\n"
            ."        fastcgi_param CONTENT_LENGTH \"\";\n"
            ."        fastcgi_param CONTENT_TYPE \"\";\n"
            ."        fastcgi_param REQUEST_METHOD GET;\n"
            ."        fastcgi_param REQUEST_URI /_access/phpmyadmin;\n"
            ."        fastcgi_param QUERY_STRING \"\";\n"
            ."        fastcgi_param SCRIPT_FILENAME {$root}/public/index.php;\n"
            ."        fastcgi_param SCRIPT_NAME /index.php;\n"
            ."        fastcgi_param DOCUMENT_ROOT {$root}/public;\n"
            ."        fastcgi_param SERVER_NAME \$host;\n"
            ."        fastcgi_param SERVER_PORT \$server_port;\n"
            ."        fastcgi_param SERVER_PROTOCOL \$server_protocol;\n"
            ."        fastcgi_param REMOTE_ADDR \$remote_addr;\n"
            ."        fastcgi_param HTTPS \$https if_not_empty;\n"
            ."    }\n"
            .self::END."\n";
    }
}
