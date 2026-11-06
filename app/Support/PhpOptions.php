<?php

namespace App\Support;

/**
 * The PHP options a website can set for itself. Each goes into the site's own PHP-FPM pool as a
 * php_admin_value, so the site's code cannot change it back. scripts/host-apply.sh checks the
 * same keys and patterns again before writing (the pool is read as root).
 */
class PhpOptions
{
    /**
     * On/off options: their values are on or off, shown as checkboxes.
     *
     * @return array<string, array{label: string, help: string, default: string, pattern: string, example: string}>
     */
    public static function flags(): array
    {
        $flag = fn (string $label, string $help, string $default) => ['label' => $label, 'help' => $help, 'default' => $default, 'pattern' => '/^(on|off)$/', 'example' => $default];

        return [
            'allow_url_fopen' => $flag('Open URLs as files', 'file_get_contents() and fopen() on http:// addresses.', 'on'),
            'display_errors' => $flag('Show errors in pages', 'Only while debugging: errors appear to visitors.', 'off'),
            'expose_php' => $flag('Expose PHP', 'Sends the X-Powered-By: PHP header.', 'off'),
            'file_uploads' => $flag('File uploads', 'Forms may upload files.', 'on'),
            'log_errors' => $flag('Log errors', 'Errors go to the log.', 'on'),
            'session.cookie_secure' => $flag('Secure session cookie', 'The session cookie is sent only over HTTPS.', 'off'),
            'session.use_strict_mode' => $flag('Strict session ids', 'Refuses session ids the server did not make.', 'off'),
            'session.cookie_httponly' => $flag('HttpOnly session cookie', 'JavaScript cannot read the session cookie.', 'off'),
            'short_open_tag' => $flag('Short open tag', 'Allows <? as well as <?php.', 'off'),
            'zlib.output_compression' => $flag('Compress output', 'Gzips pages in PHP (nginx usually does it).', 'off'),
            'opcache.enable' => $flag('OPcache', 'Keeps compiled PHP in memory: much faster.', 'on'),
        ];
    }

    /**
     * Choices shown as a list, value => label.
     *
     * @return array<string, array<string, string>>
     */
    public static function choices(): array
    {
        return [
            'error_reporting' => [
                '22527' => 'E_ALL & ~E_DEPRECATED & ~E_STRICT (production)',
                '32767' => 'E_ALL (every error, warning and notice)',
                '24575' => 'E_ALL & ~E_DEPRECATED',
                '341' => 'Errors only (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR)',
            ],
            'memory_limit' => ['128M' => '128M', '256M' => '256M', '512M' => '512M', '1G' => '1G', '2G' => '2G', '4G' => '4G', '-1' => 'No limit'],
        ];
    }

    /**
     * Every option a website can set, flags first.
     *
     * @return array<string, array{label: string, help: string, default: string, pattern: string, example: string}>
     */
    public static function all(): array
    {
        return [...self::flags(), ...self::values()];
    }

    /**
     * Options with a value: a size, a number, a time zone, a list.
     *
     * @return array<string, array{label: string, help: string, default: string, pattern: string, example: string}>
     */
    public static function values(): array
    {
        return [
            'memory_limit' => ['label' => 'Memory limit', 'help' => 'Most memory one request may use. -1 is no limit.', 'default' => '128M', 'pattern' => '/^([1-9][0-9]{1,4}M|[1-9][0-9]?G|-1)$/', 'example' => '256M'],
            'upload_max_filesize' => ['label' => 'Largest upload', 'help' => 'Biggest single file a form may upload.', 'default' => '2M', 'pattern' => '/^([1-9][0-9]{0,4}M|[1-9][0-9]?G)$/', 'example' => '64M'],
            'post_max_size' => ['label' => 'Largest form post', 'help' => 'Whole form, files included. Keep it at least the upload size.', 'default' => '8M', 'pattern' => '/^([1-9][0-9]{0,4}M|[1-9][0-9]?G)$/', 'example' => '64M'],
            'max_execution_time' => ['label' => 'Max execution time (s)', 'help' => 'Seconds a request may run. 0 is no limit.', 'default' => '30', 'pattern' => '/^(0|[1-9][0-9]{0,4})$/', 'example' => '60'],
            'max_input_time' => ['label' => 'Max input time (s)', 'help' => 'Seconds to read a request’s data, like a slow upload.', 'default' => '60', 'pattern' => '/^(0|[1-9][0-9]{0,4})$/', 'example' => '120'],
            'max_input_vars' => ['label' => 'Max input vars', 'help' => 'Form fields one request may send. Big admin forms need more.', 'default' => '1000', 'pattern' => '/^[1-9][0-9]{2,5}$/', 'example' => '5000'],
            'date.timezone' => ['label' => 'Time zone', 'help' => 'Like Europe/Istanbul or UTC.', 'default' => 'UTC', 'pattern' => '/^([A-Za-z]+(\/[A-Za-z_+-]+){0,2}|UTC)$/', 'example' => 'Asia/Baku'],
            'max_file_uploads' => ['label' => 'Max file uploads', 'help' => 'Files one request may upload at once.', 'default' => '20', 'pattern' => '/^[1-9][0-9]{0,3}$/', 'example' => '50'],
            'error_reporting' => ['label' => 'Error reporting', 'help' => 'Which errors PHP reports.', 'default' => '22527', 'pattern' => '/^(22527|32767|24575|341)$/', 'example' => '22527'],
            'disable_functions' => ['label' => 'Disabled functions', 'help' => 'PHP functions this site may not call, comma-separated, like exec,shell_exec,system,passthru.', 'default' => '', 'pattern' => '/^[a-z_][a-z0-9_]*(,[a-z_][a-z0-9_]*){0,199}$/', 'example' => 'exec,shell_exec,system,passthru'],
            'include_path' => ['label' => 'Include path', 'help' => 'Folders include() looks in, separated by colons.', 'default' => '.:/usr/share/php', 'pattern' => '/^[A-Za-z0-9_.\/-]+(:[A-Za-z0-9_.\/-]+){0,19}$/', 'example' => '.:/usr/share/php'],
        ];
    }

    /**
     * Only known keys with valid values; empty values are left to PHP's defaults.
     *
     * @param  array<string, mixed>|null  $options
     * @return array<string, string>
     */
    public static function clean(?array $options): array
    {
        $clean = [];

        foreach (self::all() as $key => $option) {
            $value = trim((string) ($options[$key] ?? ''));

            if ($value !== '' && preg_match($option['pattern'], $value)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** The options as host-apply.sh reads them: key=value;key=value. */
    public static function encode(?array $options): string
    {
        $clean = self::clean($options);

        return implode(';', array_map(fn (string $key, string $value) => $key.'='.$value, array_keys($clean), $clean));
    }

    /** The option's key in a form: Livewire reads dots as nesting, so date.timezone is date__timezone. */
    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** "1G" or "512M" in megabytes, for comparing sizes. */
    public static function megabytes(string $size): int
    {
        return str_ends_with($size, 'G') ? (int) $size * 1024 : (int) $size;
    }
}
