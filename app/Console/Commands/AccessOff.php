<?php

namespace App\Console\Commands;

use App\Services\AccessGate;
use App\Services\PhpMyAdminAccess;
use Illuminate\Console\Command;
use Throwable;

/**
 * The way back in when the access prompt locks you out: turns it off from the server.
 */
class AccessOff extends Command
{
    protected $signature = 'laravelify:access-off {target? : panel or phpmyadmin (both when left out)}';

    protected $description = 'Turn off the browser sign-in prompt (Security → Access) for the panel, phpMyAdmin, or both';

    public function handle(AccessGate $gate, PhpMyAdminAccess $phpMyAdmin): int
    {
        $targets = $this->argument('target') ? [(string) $this->argument('target')] : AccessGate::TARGETS;

        foreach ($targets as $target) {
            if (! in_array($target, AccessGate::TARGETS, true)) {
                $this->error('Choose panel or phpmyadmin.');

                return self::FAILURE;
            }

            $gate->saveSettings($target, ['enabled' => false] + $gate->settings($target));

            if ($target === 'phpmyadmin') {
                try {
                    $phpMyAdmin->apply(false);
                } catch (Throwable $exception) {
                    $this->warn('phpMyAdmin\'s nginx file was not changed: '.$exception->getMessage());
                }
            }

            $this->info('The sign-in prompt is off for '.$target.'.');
        }

        return self::SUCCESS;
    }
}
