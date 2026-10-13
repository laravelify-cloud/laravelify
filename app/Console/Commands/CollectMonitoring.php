<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\HeartbeatMonitor;
use App\Services\IssueCollector;
use App\Services\MonitorAlerts;
use App\Services\RequestCollector;
use Illuminate\Console\Command;
use Throwable;

class CollectMonitoring extends Command
{
    /**
     * @var string
     */
    protected $signature = 'laravelify:collect';

    /**
     * @var string
     */
    protected $description = 'Read new requests and Laravel errors of every website, and send monitoring alerts';

    public function handle(RequestCollector $requests, IssueCollector $issues, MonitorAlerts $alerts, HeartbeatMonitor $heartbeats): int
    {
        foreach (Website::query()->orderBy('id')->get() as $website) {
            // One broken site (unreadable log, odd line) never stops the others.
            try {
                $stored = $requests->collect($website);
                $changes = $issues->collect($website);
                $lines = [...$alerts->issues($website, $changes), ...$alerts->traffic($website), ...$alerts->threats($website)];

                if ($stored > 0 || $changes !== [] || $lines !== []) {
                    $this->line($website->slug.': '.$stored.' requests, '.count($changes).' issue changes');
                }

                foreach ($lines as $line) {
                    $this->line('  '.$line);
                }
            } catch (Throwable $exception) {
                $this->error($website->slug.': '.$exception->getMessage());
            }
        }

        foreach ($heartbeats->check() as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
