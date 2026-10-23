<?php

namespace App\Livewire;

use App\Services\HostWriter;
use App\Services\PanelWorker;
use Throwable;

/**
 * A banner on every panel page while the panel's queue worker is not running: deploys and
 * background tasks wait for it. It checks again every minute and can start the worker.
 */
class PanelWorkerAlert extends PanelComponent
{
    public bool $starting = false;

    public function start(HostWriter $host): void
    {
        try {
            $host->startPanelWorker();
            $this->starting = true;
            $this->toast('Starting the panel worker. Waiting deploys go on in a few seconds.');
        } catch (Throwable $exception) {
            $this->toast('Could not start the panel worker: '.$exception->getMessage(), 'error');
        }
    }

    public function render(PanelWorker $worker)
    {
        $running = $worker->running();

        if ($running) {
            $this->starting = false;
        }

        return view('livewire.panel-worker-alert', [
            'running' => $running,
            'since' => $worker->downSince() ?? $worker->lastBeat(),
        ]);
    }
}
