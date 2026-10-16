<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\HostWriter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Apply every turned-on site's Supervisor programs or PM2 app again. Run by update.sh after
 * the panel moved to its own user: programs then run as the site user with their logs in
 * /var/log/laravelify/sites, and Node apps move to the site user's PM2 daemon. A site's .env
 * and nginx files are not touched.
 */
class ReapplyProcesses extends Command
{
    protected $signature = 'laravelify:reapply-processes';

    protected $description = 'Apply the Supervisor programs and PM2 apps of every turned-on site again';

    public function handle(HostWriter $hostWriter): int
    {
        $failed = 0;

        foreach (Website::query()->where('enabled', true)->orderBy('slug')->get() as $website) {
            if (! $website->isLaravel() && ! $website->isNode()) {
                continue;
            }

            try {
                $output = trim($hostWriter->writeProcesses($website));
                $this->line($website->slug.': '.($output !== '' ? $output : 'applied'));
            } catch (Throwable $exception) {
                $failed++;
                $this->warn($website->slug.': not applied, it keeps running as before. '.$exception->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
