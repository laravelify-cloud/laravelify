<?php

namespace App\Livewire\Server;

use App\Livewire\PanelComponent;
use App\Services\ServerProcesses;
use Throwable;

class Processes extends PanelComponent
{
    public string $inspection = '';

    public string $inspectionTitle = '';

    public function restart(string $manager, string $name, ServerProcesses $processes): void
    {
        $this->run(fn () => $processes->restart($manager, $name));
    }

    public function delete(string $manager, string $name, ServerProcesses $processes): void
    {
        if (! $this->confirmed($name)) {
            return;
        }

        $this->run(fn () => $processes->delete($manager, $name));
    }

    public function logs(string $manager, string $name, ServerProcesses $processes): void
    {
        $this->inspectionTitle = $name.' logs';

        try {
            $this->inspection = $processes->logs($manager, $name);
        } catch (Throwable $exception) {
            $this->inspection = $exception->getMessage();
        }
    }

    public function env(string $name, ServerProcesses $processes): void
    {
        $this->inspectionTitle = $name.' .env';

        try {
            $this->inspection = $processes->env($name);
        } catch (Throwable $exception) {
            $this->inspection = $exception->getMessage();
        }
    }

    public function render(ServerProcesses $processes)
    {
        return view('livewire.server.processes', [
            'rows' => $processes->list(),
        ])->layout('layouts.app')->title('Processes');
    }

    private function run(callable $action): void
    {
        try {
            $this->toast($action());
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }
}
