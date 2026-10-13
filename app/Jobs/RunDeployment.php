<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\Deployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunDeployment implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(public int $deploymentId) {}

    public function handle(Deployer $deployer): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);

        if (! $deployment || in_array($deployment->status, ['success', 'cancelled'], true)) {
            return;
        }

        $deployer->run($deployment);
    }
}
