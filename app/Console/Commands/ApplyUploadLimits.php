<?php

namespace App\Console\Commands;

use App\Models\ServerService;
use App\Services\CommandRunner;
use App\Services\HostWriter;
use App\Services\UploadLimits;
use Illuminate\Console\Command;
use Throwable;

/**
 * Raise the upload and time limits of the panel's and phpMyAdmin's nginx files on servers
 * installed before they were part of the defaults. Run by every update; changes nothing twice.
 */
class ApplyUploadLimits extends Command
{
    protected $signature = 'laravelify:upload-limits
        {--panel-file=/etc/nginx/sites-available/laravelify-panel.conf : The panel\'s nginx file}';

    protected $description = 'Let the panel and phpMyAdmin accept uploads up to 256 MB (database dumps, archives)';

    public function handle(HostWriter $host, CommandRunner $runner): int
    {
        $ok = $this->phpMyAdmin($host);

        return $this->panel($runner, (string) $this->option('panel-file')) && $ok ? self::SUCCESS : self::FAILURE;
    }

    private function phpMyAdmin(HostWriter $host): bool
    {
        $service = ServerService::query()->where('kind', 'phpmyadmin')->first();

        if (! $service) {
            return true;
        }

        $previous = (string) $service->config;
        $config = UploadLimits::nginx($previous);

        if ($config === $previous) {
            $this->line('phpMyAdmin: upload limits already set.');

            return true;
        }

        $service->update(['config' => $config]);

        try {
            $host->writeService($service);
            $this->info('phpMyAdmin: uploads up to '.UploadLimits::MEGABYTES.' MB.');

            return true;
        } catch (Throwable $exception) {
            $service->update(['config' => $previous]);
            $this->warn('phpMyAdmin: nginx did not accept the new limits, so nothing changed: '.$exception->getMessage());

            return false;
        }
    }

    private function panel(CommandRunner $runner, string $path): bool
    {
        if (! is_file($path)) {
            return true;
        }

        $previous = (string) file_get_contents($path);
        $config = UploadLimits::nginx($previous);

        if ($config === $previous) {
            $this->line('Panel: upload limits already set.');

            return true;
        }

        if (@file_put_contents($path, $config) === false) {
            $this->warn('Panel: could not write '.$path.'. Run the update as root.');

            return false;
        }

        $test = $runner->run(['nginx', '-t']);

        if (! $test->ok) {
            file_put_contents($path, $previous);
            $this->warn('Panel: nginx did not accept the new limits, so the file was put back: '.trim($test->output));

            return false;
        }

        $runner->run(['systemctl', 'reload', 'nginx']);
        $this->info('Panel: uploads up to '.UploadLimits::MEGABYTES.' MB.');

        return true;
    }
}
