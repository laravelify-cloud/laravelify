<?php

namespace App\Livewire\Tools;

use App\Livewire\PanelComponent;

class FileManager extends PanelComponent
{
    public function render()
    {
        return view('livewire.tools.file-manager', [
            'root' => (string) (config('laravelify.files_root') ?: config('laravelify.sites_root')),
        ])->layout('layouts.app')->title('File manager');
    }
}
