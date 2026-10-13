<?php

namespace App\Console\Commands;

use App\Models\RequestMinute;
use App\Models\ServerSample;
use App\Models\SiteRequest;
use App\Models\UptimeCheck;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class PruneMonitoring extends Command
{
    /**
     * Charts and uptime keep at least this long, whatever the request retention is.
     */
    private const MIN_HISTORY_DAYS = 30;

    /**
     * @var string
     */
    protected $signature = 'laravelify:prune-monitoring';

    /**
     * @var string
     */
    protected $description = 'Delete monitoring data older than each website\'s retention';

    public function handle(): int
    {
        foreach (Website::query()->get() as $website) {
            $days = max(1, (int) $website->monitor_retention_days);

            $requests = $this->chunkedDelete(SiteRequest::query()->where('website_id', $website->id)->where('at', '<', now()->subDays($days)));
            $minutes = RequestMinute::query()->where('website_id', $website->id)->where('minute', '<', now()->subDays(max($days, self::MIN_HISTORY_DAYS)))->delete();
            $checks = UptimeCheck::query()->where('website_id', $website->id)->where('checked_at', '<', now()->subDays(max($days, self::MIN_HISTORY_DAYS)))->delete();

            $this->line($website->slug.': removed '.$requests.' requests, '.$minutes.' minutes, '.$checks.' uptime checks');
        }

        $samples = ServerSample::query()->where('at', '<', now()->subDays(self::MIN_HISTORY_DAYS))->delete();
        $this->line('server: removed '.$samples.' samples');

        return self::SUCCESS;
    }

    /**
     * Big deletes in small batches, so the site tables are never locked for long.
     *
     * @param  Builder<SiteRequest>  $query
     */
    private function chunkedDelete(Builder $query): int
    {
        $total = 0;

        do {
            $ids = (clone $query)->limit(5000)->pluck('id');
            $deleted = $ids->isEmpty() ? 0 : SiteRequest::query()->whereIn('id', $ids)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }
}
