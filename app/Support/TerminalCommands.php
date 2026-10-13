<?php

namespace App\Support;

use App\Models\Website;
use App\Services\SiteLogs;
use Illuminate\Support\Collection;

/**
 * Suggested commands for the terminal's "/" command menu.
 */
class TerminalCommands
{
    /**
     * @return list<array{group: string, command: string, description: string, danger: bool}>
     */
    public static function for(?Website $website): array
    {
        if ($website && $website->isPhp() && ! $website->isLaravel()) {
            return [...self::php(), ...self::tools()];
        }

        if ($website && ! $website->isLaravel()) {
            return [...self::node(), ...($website->isNode() ? self::processes(collect([$website])) : [])];
        }

        if ($website) {
            return [...self::laravel(), ...self::processes(collect([$website])), ...self::tools()];
        }

        return [...self::server(), ...self::processes(Website::query()->orderBy('name')->get(), true), ...self::laravel(), ...self::tools()];
    }

    /**
     * PM2 and Supervisor commands for these websites' own process names.
     * The terminal runs them through the panel as root (see ProcessCommands).
     *
     * @param  Collection<int, Website>  $websites
     * @return list<array{group: string, command: string, description: string, danger: bool}>
     */
    private static function processes(Collection $websites, bool $both = false): array
    {
        $pm2 = [['pm2 list', 'Every PM2 app with status, CPU, and memory']];
        $supervisor = [['supervisorctl status', 'Every Supervisor program and its state']];
        $logs = app(SiteLogs::class);

        foreach ($websites as $website) {
            if (! $website->isLaravel()) {
                if (! $website->isNode()) {
                    continue;
                }

                $pm2[] = ['pm2 logs '.$website->slug, 'Recent output of '.$website->name];
                $pm2[] = ['pm2 restart '.$website->slug, 'Restart '.$website->name];

                continue;
            }

            foreach (array_keys($logs->programs($website)) as $program) {
                $supervisor[] = ['supervisorctl tail '.$program, 'Recent output of '.$program];
                $supervisor[] = ['supervisorctl restart '.$program, 'Restart '.$program];
            }
        }

        $hasNext = $both || $websites->contains(fn (Website $website): bool => $website->isNode());
        $hasLaravel = $both || $websites->contains(fn (Website $website): bool => $website->isLaravel());

        return [
            ...($hasNext ? self::group('PM2 (run as root by the panel)', $pm2)->all() : []),
            ...($hasLaravel ? self::group('Supervisor (run as root by the panel)', $supervisor)->all() : []),
        ];
    }

    /**
     * @return list<array{group: string, command: string, description: string, danger: bool}>
     */
    private static function server(): array
    {
        return self::group('Server', [
            ['df -h', 'Free disk space per drive'],
            ['du -sh /var/www/* | sort -h', 'Size of every site folder'],
            ['free -h', 'Memory and swap use'],
            ['uptime', 'Uptime and load average'],
            ['top -bn1 | head -20', 'Busiest processes right now'],
            ['ps aux --sort=-%mem | head -15', 'Processes using the most memory'],
            ['ss -tln', 'Ports that are listening'],
            ['php -v && node -v && composer --version', 'PHP, Node.js, and Composer versions'],
            ['cat /etc/os-release', 'Operating system version'],
            ['ls -la /var/www', 'Every site folder'],
        ])->all();
    }

    /**
     * @return list<array{group: string, command: string, description: string, danger: bool}>
     */
    private static function laravel(): array
    {
        return self::group('Cache and optimize', [
            ['php artisan optimize', 'Cache config, routes, events, and views'],
            ['php artisan optimize:clear', 'Clear every cached file'],
            ['php artisan cache:clear', 'Flush the application cache'],
            ['php artisan config:cache', 'Cache the configuration'],
            ['php artisan config:clear', 'Remove the configuration cache'],
            ['php artisan route:cache', 'Cache the routes'],
            ['php artisan route:clear', 'Remove the route cache'],
            ['php artisan view:cache', 'Compile all Blade views'],
            ['php artisan view:clear', 'Clear compiled Blade views'],
            ['php artisan event:cache', 'Cache events and listeners'],
            ['php artisan event:clear', 'Clear cached events'],
        ])->merge(self::group('Database', [
            ['php artisan migrate --force', 'Run pending migrations'],
            ['php artisan migrate:status', 'Show which migrations ran'],
            ['php artisan db:seed --force', 'Seed the database'],
            ['php artisan db:show', 'Database overview'],
            ['php artisan migrate:rollback --force', 'Roll back the last batch', true],
            ['php artisan migrate:fresh --force', 'Drop every table and migrate again', true],
        ]))->merge(self::group('Queue and schedule', [
            ['php artisan queue:restart', 'Restart workers after a deploy'],
            ['php artisan queue:failed', 'List failed jobs'],
            ['php artisan queue:retry all', 'Retry every failed job'],
            ['php artisan queue:work --stop-when-empty', 'Process queued jobs, then exit'],
            ['php artisan queue:flush', 'Delete every failed job', true],
            ['php artisan schedule:list', 'Show scheduled tasks'],
            ['php artisan schedule:run', 'Run due scheduled tasks now'],
        ]))->merge(self::group('Application', [
            ['php artisan about', 'Environment, drivers, and cache status'],
            ['php artisan route:list --except-vendor', 'List the app routes'],
            ['php artisan storage:link', 'Link public/storage'],
            ['php artisan down --retry=60', 'Put the site in maintenance mode', true],
            ['php artisan up', 'Bring the site out of maintenance mode'],
            ['php artisan key:generate --force', 'Replace APP_KEY. Logs everyone out', true],
            ['php artisan list', 'Every artisan command'],
        ]))->all();
    }

    /**
     * @return list<array{group: string, command: string, description: string, danger: bool}>
     */
    private static function tools(): array
    {
        return self::group('Composer and npm', [
            ['composer install --no-dev --optimize-autoloader', 'Install PHP dependencies for production'],
            ['composer dump-autoload -o', 'Rebuild the optimized autoloader'],
            ['npm ci', 'Install JavaScript dependencies from the lock file'],
            ['npm run build', 'Build front-end assets'],
        ])->merge(self::group('Files', [
            ['ls -la', 'List this folder'],
            ['du -sh *', 'Size of each entry'],
            ['tail -n 100 storage/logs/laravel.log', 'Last 100 lines of the Laravel log'],
            ['df -h', 'Disk space'],
        ]))->all();
    }

    /**
     * @return list<array{group: string, command: string, description: string, danger: bool}>
     */
    private static function php(): array
    {
        return self::group('PHP', [
            ['php -v', 'PHP version'],
            ['php -m', 'Loaded PHP extensions'],
            ['php -l index.php', 'Check a file for syntax errors'],
            ['composer install --no-dev --optimize-autoloader', 'Install PHP dependencies for production'],
            ['composer dump-autoload -o', 'Rebuild the optimized autoloader'],
            ['composer outdated', 'Show outdated packages'],
        ])->all();
    }

    /**
     * @return list<array{group: string, command: string, description: string, danger: bool}>
     */
    private static function node(): array
    {
        return self::group('npm', [
            ['npm ci', 'Install dependencies from the lock file'],
            ['npm install', 'Install dependencies'],
            ['npm run build', 'Build the app'],
            ['npm run lint', 'Run the linter'],
            ['npm outdated', 'Show outdated packages'],
            ['rm -rf .next/cache', 'Clear the Next.js build cache', true],
        ])->merge(self::group('Files', [
            ['ls -la', 'List this folder'],
            ['du -sh *', 'Size of each entry'],
            ['node -v && npm -v', 'Node.js and npm versions'],
            ['df -h', 'Disk space'],
        ]))->all();
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: bool}>  $rows
     * @return Collection<int, array{group: string, command: string, description: string, danger: bool}>
     */
    private static function group(string $name, array $rows): Collection
    {
        return collect($rows)->map(fn (array $row): array => [
            'group' => $name,
            'command' => $row[0],
            'description' => $row[1],
            'danger' => $row[2] ?? false,
        ]);
    }
}
