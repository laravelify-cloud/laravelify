<?php

namespace App\Console\Commands;

use App\Models\ServerService;
use App\Services\HostWriter;
use App\Services\ServerCatalog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Servers installed when phpMyAdmin had a domain of its own (https://phpmyadmin.example.com) move
 * to a path of the panel's address (https://panel.example.com/phpmyadmin). Run by every update;
 * a phpMyAdmin that is already a path is left as it is.
 */
class PhpMyAdminPath extends Command
{
    protected $signature = 'laravelify:phpmyadmin-path {--path=/phpmyadmin}';

    protected $description = 'Serve phpMyAdmin at /phpmyadmin of the panel address instead of a domain of its own';

    public function handle(HostWriter $host): int
    {
        $service = ServerService::query()->where('kind', 'phpmyadmin')->first();

        if (! $service || in_array(trim((string) $service->domain), ['', 'phpmyadmin.internal'], true)) {
            $this->line('phpMyAdmin is not installed.');

            return self::SUCCESS;
        }

        if (filled($service->settings['path'] ?? null) && ServerCatalog::isPathConfig((string) $service->config)) {
            $this->line('phpMyAdmin is already at '.$service->settings['path'].'.');

            return self::SUCCESS;
        }

        $panel = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $path = '/'.trim((string) $this->option('path'), '/');

        if ($panel === '' || preg_match('#^/[A-Za-z0-9_-]+$#', $path) !== 1) {
            $this->warn('phpMyAdmin was not moved: the panel address (APP_URL) or the path is not valid.');

            return self::FAILURE;
        }

        $php = (string) ($service->settings['php_version'] ?? '');
        $before = $service->only(['domain', 'settings', 'config']);

        $service->update([
            'domain' => $panel.$path,
            'settings' => array_filter(['php_version' => $php, 'path' => $path]),
            'config' => ServerCatalog::phpMyAdminPathNginx($path, ServerCatalog::panelSocket($php !== '' ? $php : null)),
        ]);

        try {
            $host->writeService($service);
        } catch (Throwable $exception) {
            $service->update($before);
            $this->warn('phpMyAdmin stays on its own domain: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("phpMyAdmin is now at https://{$panel}{$path}");

        return self::SUCCESS;
    }
}
