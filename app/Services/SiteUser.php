<?php

namespace App\Services;

/**
 * The Unix user that owns the site folders and runs the websites (www-data). The panel runs
 * as its own user, so website code cannot read the panel's .env or reach host-apply.sh.
 * Everything the panel runs or changes inside a site folder goes through
 * `sudo -u www-data`: deploy commands execute repository code (Composer scripts, npm
 * postinstall, artisan, git hooks), and that code must never run as the panel.
 */
class SiteUser
{
    public function __construct(private CommandRunner $runner) {}

    public function name(): string
    {
        return (string) (config('laravelify.site_user') ?: 'www-data');
    }

    /**
     * Whether the panel runs as another user than the sites, so site work goes through sudo.
     * False on development machines, in tests, and on servers not migrated yet (panel as www-data).
     */
    public function separate(): bool
    {
        if (! $this->runner->supportsHostCommands() || ! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
            return false;
        }

        $current = posix_getpwuid(posix_geteuid());

        return is_array($current) && ($current['name'] ?? null) !== $this->name();
    }

    /**
     * HOME and the Composer, npm, and Corepack caches for site commands.
     */
    public function home(): string
    {
        return $this->separate()
            ? (string) config('laravelify.site_home')
            : storage_path('app/process-home');
    }

    /**
     * Where the file manager keeps deleted files. The site user moves them there.
     */
    public function trashRoot(): string
    {
        return $this->separate()
            ? (string) config('laravelify.trash_root')
            : storage_path('app/trash');
    }

    /**
     * A command as the site user, with exactly the given environment (sudo drops the rest).
     *
     * @param  list<string>  $command
     * @param  array<string, string|false>  $env
     * @return list<string>
     */
    public function wrap(array $command, array $env = []): array
    {
        $variables = [];

        foreach ($env as $key => $value) {
            if (is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) === 1) {
                $variables[] = $key.'='.$value;
            }
        }

        return ['sudo', '-n', '-u', $this->name(), '--', '/usr/bin/env', '-i', ...$variables, ...$command];
    }
}
