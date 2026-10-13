<?php

namespace App\Livewire;

use App\Models\CloudflareTunnel;
use App\Models\Deployment;
use App\Models\Group;
use App\Models\Website;

class Dashboard extends PanelComponent
{
    public function render()
    {
        return view('livewire.dashboard', [
            'groupCount' => Group::query()->count(),
            'websiteCount' => Website::query()->count(),
            'tunnelCount' => CloudflareTunnel::query()->count(),
            'deployments' => Deployment::query()->with('website')->latest()->limit(8)->get(),
        ])->layout('layouts.app')->title('Dashboard');
    }
}
