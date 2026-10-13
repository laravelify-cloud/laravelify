<?php

namespace App\Livewire\Tools;

use App\Livewire\PanelComponent;

class Terminal extends PanelComponent
{
    public function render()
    {
        return view('livewire.tools.terminal', [
            'root' => (string) config('laravelify.sites_root'),
        ])->layout('layouts.app')->title('Terminal');
    }
}
