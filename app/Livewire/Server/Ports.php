<?php

namespace App\Livewire\Server;

use App\Livewire\PanelComponent;
use App\Services\ServerProcesses;

class Ports extends PanelComponent
{
    public function render(ServerProcesses $processes)
    {
        return view('livewire.server.ports', [
            'ports' => $processes->reservedPorts(),
        ])->layout('layouts.app')->title('Reserved ports');
    }
}
