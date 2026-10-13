<?php

namespace App\Livewire\Server;

use App\Livewire\PanelComponent;
use App\Models\ServerService;
use App\Services\CommandRunner;

class Index extends PanelComponent
{
    public function render()
    {
        $services = ServerService::query()->orderBy('kind')->orderBy('version')->get();
        $runner = app(CommandRunner::class);

        return view('livewire.server.index', [
            'services' => $services,
            'statuses' => $services->mapWithKeys(fn (ServerService $service) => [
                $service->id => $this->status($service, $runner),
            ]),
        ])->layout('layouts.app')->title('Server');
    }

    private function status(ServerService $service, CommandRunner $runner): string
    {
        if (! $runner->supportsHostCommands() || ! $service->unit) {
            return 'unknown';
        }

        $result = $runner->run(['systemctl', 'is-active', $service->unit]);
        $state = trim($result->output);

        return $state === 'active' ? 'running' : ($state !== '' ? $state : 'stopped');
    }
}
