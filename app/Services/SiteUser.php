<?php

namespace App\Services;

use App\Models\Website;

/**
 * The Unix user that owns a site folder and runs that website. The panel runs as its own user,
 * so website code cannot read the panel's .env or reach host-apply.sh. Everything the panel runs
 * or changes inside a site folder goes through `sudo -u <site user>`: deploy commands execute
 * repository code (Composer scripts, npm postinstall, artisan, git hooks), and that code must
 * never run as the panel.
 *
 * Each website has its own user (lfy-<slug>) once scripts/privileges.sh set the server up for it,
 * so a hacked site cannot change other sites' files. Sites from before that keep the shared user
 * (www-data) until they are moved (`artisan laravelify:separate-sites`). Which one a site uses is
 * read from the owner of its folder, the same way host-apply.sh decides it as root.
 *
 * The instance from the container is the shared user; for() and forPath() give a site's.
 */
class SiteUser
{
    public const PREFIX = 'lfy-';

    public function __construct(private CommandRunner $runner, private ?string $slug = null) {}

    /**
     * The user of a website: its own user once its folder belongs to it, the shared one before.
     */
    public function for(Website $website): static
    {
        return $this->forSlug((string) $website->slug);
    }

    public function forSlug(string $slug): static
    {
        if (! self::validSlug($slug) || $this->ownerOf($this->sitesRoot().'/'.$slug) !== self::userName($slug)) {
            return $this->shared();
        }

        $site = clone $this;
        $site->slug = $slug;

        return $site;
    }

    /**
     * The user that may change a path: the site's user for anything inside /var/www/<slug> or the
     * site's own folder in /var/lib/laravelify/sites, the shared user for everything else.
     */
    public function forPath(string $path): static
    {
        $slug = $this->slugOf($path);

        return $slug === null ? $this->shared() : $this->forSlug($slug);
    }

    /**
     * The site a path belongs to, from /var/www/<slug>/... or /var/lib/laravelify/sites/<slug>/...
     */
    public function slugOf(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);

        foreach ([$this->sitesRoot(), $this->siteUsersRoot()] as $root) {
            if (str_starts_with($path, $root.'/')) {
                $slug = explode('/', substr($path, strlen($root) + 1), 2)[0];

                return self::validSlug($slug) ? $slug : null;
            }
        }

        return null;
    }

    public function shared(): static
    {
        $shared = clone $this;
        $shared->slug = null;

        return $shared;
    }

    /**
     * Whether this is a website's own user (not the shared www-data).
     */
    public function isOwn(): bool
    {
        return $this->slug !== null;
    }

    public function slug(): ?string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->slug !== null
            ? self::userName($this->slug)
            : (string) (config('laravelify.site_user') ?: 'www-data');
    }

    /**
     * The Unix name of a site's own user: lfy-<slug>, shortened with a hash past 32 characters
     * (host-apply.sh builds the same name).
     */
    public static function userName(string $slug): string
    {
        $name = self::PREFIX.$slug;

        return strlen($name) <= 32 ? $name : self::PREFIX.substr($slug, 0, 21).'-'.substr(sha1($slug), 0, 6);
    }

    public static function validSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1;
    }

    /**
     * Whether this server gives websites their own users (scripts/privileges.sh made the groups,
     * and scripts/rollback-site-users.sh did not turn that off). host-apply.sh decides the same way.
     */
    public function perSiteUsers(): bool
    {
        return $this->separate()
            && function_exists('posix_getgrnam')
            && posix_getgrnam('laravelify-sites') !== false
            && ! file_exists(dirname((string) config('laravelify.site_users_root')).'/.no-site-users');
    }

    /**
     * Who runs the terminal's commands in a folder: the user of the site it is in. With per-site
     * users, the sites folder itself is nobody's (read-only).
     */
    public function terminalUserFor(string $folder): string
    {
        $user = $this->forPath($folder);

        if ($this->perSiteUsers() && $this->slugOf($folder) === null) {
            return 'nobody (read-only)';
        }

        return $user->name();
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
     * HOME and the Composer, npm, and Corepack caches for site commands. A site's own user has
     * its own, so one site cannot plant a package in the cache another site's deploy installs.
     */
    public function home(): string
    {
        if ($this->slug !== null) {
            return $this->siteUsersRoot().'/'.$this->slug.'/home';
        }

        return $this->separate()
            ? (string) config('laravelify.site_home')
            : storage_path('app/process-home');
    }

    /**
     * Where the file manager keeps deleted files. The site user moves them there.
     */
    public function trashRoot(): string
    {
        if ($this->slug !== null) {
            return $this->siteUsersRoot().'/'.$this->slug.'/trash';
        }

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

    /**
     * The owner of a folder, or null when it does not exist (or on machines without POSIX).
     */
    protected function ownerOf(string $path): ?string
    {
        if (! function_exists('posix_getpwuid') || is_link($path) || ! is_dir($path)) {
            return null;
        }

        $owner = @fileowner($path);
        $user = $owner === false ? false : posix_getpwuid($owner);

        return is_array($user) ? (string) $user['name'] : null;
    }

    private function sitesRoot(): string
    {
        return rtrim(str_replace('\\', '/', (string) config('laravelify.sites_root')), '/');
    }

    private function siteUsersRoot(): string
    {
        return rtrim(str_replace('\\', '/', (string) config('laravelify.site_users_root')), '/');
    }
}
