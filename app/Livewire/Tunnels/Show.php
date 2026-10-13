<?php

namespace App\Livewire\Tunnels;

use App\Livewire\PanelComponent;
use App\Models\CloudflareTunnel;
use App\Services\ConfigRenderer;
use App\Services\HostWriter;
use Throwable;

class Show extends PanelComponent
{
    public CloudflareTunnel $tunnel;

    public string $name = '';

    public string $token = '';

    public function mount(CloudflareTunnel $tunnel): void
    {
        $this->tunnel = $tunnel;
        $this->name = $tunnel->name;
    }

    public function save(ConfigRenderer $renderer): void
    {
        $this->token = trim($this->token);

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'token' => ['nullable', 'string', 'max:4000', 'not_regex:/["\s]/'],
        ]);

        $this->tunnel->name = $this->name;

        if ($this->token !== '') {
            $this->tunnel->token = $this->token;
            $this->token = '';
        }

        $this->tunnel->save();
        $renderer->refreshTunnel($this->tunnel);
        $this->toast('Tunnel updated.');
    }

    public function destroy(HostWriter $hostWriter): void
    {
        if (! $this->confirmed($this->tunnel->name)) {
            return;
        }

        $this->tunnel->websites()->update(['cloudflare_tunnel_id' => null]);

        try {
            $hostWriter->removeTunnel($this->tunnel);
        } catch (Throwable $exception) {
            $this->addError('tunnel', $exception->getMessage());
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->tunnel->delete();
        $this->toastAfterRedirect('Tunnel deleted.');

        $this->redirect(route('tunnels.index'));
    }

    public function render()
    {
        return view('livewire.tunnels.show', [
            'websites' => $this->tunnel->websites()->orderBy('domain')->get(),
        ])->layout('layouts.app')->title($this->tunnel->name);
    }
}
