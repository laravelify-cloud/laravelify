<?php

namespace App\Services;

/**
 * Upload and time limits for the panel and phpMyAdmin, so big files (a database dump, a site
 * archive) get through: nginx's request size, PHP's upload and post size, memory, and time.
 * Ubuntu's PHP allows only 2 MB uploads by default.
 */
class UploadLimits
{
    public const MEGABYTES = 256;

    public const SECONDS = 600;

    /**
     * The PHP settings sent with each request (one per line, as PHP-FPM reads PHP_VALUE).
     */
    public static function phpValue(): string
    {
        return 'upload_max_filesize='.self::MEGABYTES.'M'."\n"
            .'post_max_size='.self::MEGABYTES.'M'."\n"
            .'memory_limit=512M'."\n"
            .'max_execution_time='.self::SECONDS;
    }

    /**
     * The nginx config with the limits: client_max_body_size raised (added when missing), and
     * every `location ~ \.php$` block given the PHP settings and a longer read timeout. Running
     * it again changes nothing.
     */
    public static function nginx(string $config): string
    {
        $body = 'client_max_body_size '.self::MEGABYTES.'M;';

        if (preg_match('/^\s*client_max_body_size\s+[^;]+;/m', $config) === 1) {
            $config = (string) preg_replace('/^(\s*)client_max_body_size\s+[^;]+;/m', '${1}'.$body, $config);
        } else {
            $config = (string) preg_replace('/^(\s*)(server_name\s+[^;]+;)/m', '${1}${2}'."\n".'${1}'.$body, $config, 1);
        }

        return (string) preg_replace_callback('/^(\s*)location\s+~\s+\\\\\.php\$\s*\{.*?^\1\}/ms', function (array $match): string {
            $block = $match[0];
            $indent = $match[1].'    ';

            if (preg_match('/^\s*fastcgi_read_timeout\s+[^;]+;/m', $block) === 1) {
                $block = (string) preg_replace('/^(\s*)fastcgi_read_timeout\s+[^;]+;/m', '${1}fastcgi_read_timeout '.self::SECONDS.';', $block);
            } else {
                $block = (string) preg_replace('/^(\s*fastcgi_pass\s+[^;]+;)/m', '${1}'."\n".$indent.'fastcgi_read_timeout '.self::SECONDS.';', $block, 1);
            }

            if (! str_contains($block, 'PHP_VALUE')) {
                $param = $indent.'fastcgi_param PHP_VALUE "'.self::phpValue().'";';
                $block = (string) preg_replace('/^(\s*fastcgi_read_timeout\s+[^;]+;)/m', '${1}'."\n".str_replace(['\\', '$'], ['\\\\', '\\$'], $param), $block, 1);
            }

            return $block;
        }, $config);
    }
}
