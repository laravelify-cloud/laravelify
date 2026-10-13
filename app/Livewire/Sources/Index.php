<?php

namespace App\Livewire\Sources;

use App\Livewire\PanelComponent;
use App\Models\GithubApp;

class Index extends PanelComponent
{
    public function render()
    {
        return view('livewire.sources.index', [
            'sources' => GithubApp::query()->with('privateKey')->orderBy('name')->get(),
        ])->layout('layouts.app')->title('Sources');
    }
}
