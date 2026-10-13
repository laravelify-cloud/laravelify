<?php

namespace App\Livewire\Groups;

use App\Livewire\PanelComponent;
use App\Models\Group;

class Index extends PanelComponent
{
    public function render()
    {
        return view('livewire.groups.index', [
            'groups' => Group::query()->withCount('websites')->orderBy('name')->get(),
        ])->layout('layouts.app')->title('Groups');
    }
}
