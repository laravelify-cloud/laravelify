<?php

namespace App\Livewire\Tunnels;

use App\Livewire\PanelComponent;
use App\Models\CloudflareTunnel;
use App\Services\ConfigRenderer;
use App\Support\Slugs;

class Form extends PanelComponent
{
    public string $name = '';

    public string $token = '';

    public function save(ConfigRenderer $renderer): void
    {
        $this->token = trim($this->token);

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'token' => ['required', 'string', 'max:4000', 'not_regex:/["\s]/'],
        ]);

        $tunnel = CloudflareTunnel::query()->create([
            'name' => $this->name,
            'slug' => Slugs::unique(CloudflareTunnel::class, $this->name),
            'token' => $this->token,
            'ingress' => "ingress:\n  - service: http_status:404\n",
        ]);

        $renderer->refreshTunnel($tunnel);

        $this->toastAfterRedirect('Tunnel saved. Add websites and select this tunnel.');

        $this->redirect(route('tunnels.show', $tunnel));
    }

    public function render()
    {
        return view('livewire.tunnels.form')
            ->layout('layouts.app')
            ->title('New Cloudflare tunnel');
    }
}
