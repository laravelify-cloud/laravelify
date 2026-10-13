<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\ServerMonitor;
use Illuminate\Console\Command;

class MonitorServer extends Command
{
    /**
     * @var string
     */
    protected $signature = 'laravelify:monitor {--patches : Only check apt for available server patches}';

    /**
     * @var string
     */
    protected $description = 'Check websites, workers, disk, and patches, and send Telegram alerts';

    public function handle(ServerMonitor $monitor): int
    {
        // Website and process checks also feed the uptime history, so they always run.
        // The apt patch check is only useful for the Telegram alert.
        if ($this->option('patches') && Setting::getValue('telegram_enabled') !== '1') {
            $this->line('Telegram notifications are off. Skipping the patch check.');

            return self::SUCCESS;
        }

        foreach ($monitor->run((bool) $this->option('patches')) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
