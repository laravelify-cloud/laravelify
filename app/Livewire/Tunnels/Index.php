<?php

namespace App\Livewire\Tunnels;

use App\Livewire\PanelComponent;
use App\Models\CloudflareTunnel;

class Index extends PanelComponent
{
    public function render()
    {
        return view('livewire.tunnels.index', [
            'tunnels' => CloudflareTunnel::query()->withCount('websites')->orderBy('name')->get(),
        ])->layout('layouts.app')->title('Cloudflare tunnels');
    }
}
