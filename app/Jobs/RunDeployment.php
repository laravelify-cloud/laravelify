<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\Deployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One deploy, on the "deploys" queue: several workers take it, so different websites deploy at
 * the same time. Two deploys of one website never do: the second waits (released every 15
 * seconds) until the first is done.
 */
class RunDeployment implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'deploys';

    public int $timeout = 3600;

    /** Waiting for another deploy of the website is not a failure: only a crash counts. */
    public int $tries = 0;

    public int $maxExceptions = 1;

    public function __construct(public int $deploymentId)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        $websiteId = Deployment::query()->whereKey($this->deploymentId)->value('website_id');

        return $websiteId === null ? [] : [
            (new WithoutOverlapping('deploy-website-'.$websiteId))->releaseAfter(15)->expireAfter($this->timeout + 300),
        ];
    }

    /** Stop waiting after a day (a deploy stuck behind another that never ends). */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(Deployer $deployer): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);

        if (! $deployment || in_array($deployment->status, ['success', 'cancelled'], true)) {
            return;
        }

        $deployer->run($deployment);
    }
}
