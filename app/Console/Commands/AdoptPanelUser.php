<?php

namespace App\Console\Commands;

use App\Models\BackupStorage;
use App\Models\ServerService;
use App\Services\HostWriter;
use App\Services\ServerCatalog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Bring saved settings in line with the panel's own user, after scripts/privileges.sh:
 * the saved phpMyAdmin nginx config uses the panel's PHP-FPM pool (as the live file does, so the
 * next save on the server page keeps it there), and backup folders on this server belong to
 * the panel user, which writes the backups.
 */
class AdoptPanelUser extends Command
{
    protected $signature = 'laravelify:adopt-panel-user';

    protected $description = 'Point phpMyAdmin at the panel\'s PHP-FPM pool and give local backup folders to the panel user';

    public function handle(HostWriter $hostWriter): int
    {
        $service = ServerService::query()->where('kind', 'phpmyadmin')->first();

        if ($service) {
            $socket = ServerCatalog::panelSocket((string) ($service->settings['php_version'] ?? '8.4'));
            $config = (string) preg_replace(ServerCatalog::SOCKET_PATTERN, $socket, (string) $service->config);

            if ($config !== $service->config) {
                $service->update(['config' => $config]);
            }

            $this->line('phpMyAdmin uses '.$socket.'.');
        }

        foreach (BackupStorage::query()->where('driver', BackupStorage::DRIVER_LOCAL)->get() as $storage) {
            $folder = '/'.trim((string) $storage->path, '/');

            try {
                $this->line(trim($hostWriter->backupFolder($folder)));
            } catch (Throwable $exception) {
                $this->warn('Backup folder '.$folder.': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
