<?php

namespace App\Livewire\Sources;

use App\Livewire\PanelComponent;
use App\Models\GithubApp;

class Index extends PanelComponent
{
    /**
     * Register the source with GitHub again: its App ID, installation, keys and secrets are
     * cleared, and its page offers the registration (a new App on GitHub, with the permissions
     * the panel asks for now). Its websites keep using it once it is registered and installed.
     */
    public function reregister(int $id): void
    {
        $source = GithubApp::query()->findOrFail($id);
        $source->forceFill([
            'app_id' => null,
            'installation_id' => null,
            'client_id' => null,
            'client_secret' => null,
            'webhook_secret' => null,
            'private_key' => null,
            'private_key_id' => null,
        ])->save();

        $this->toastAfterRedirect('Register '.$source->name.' with GitHub again. Its websites keep using it once it is installed.');
        $this->redirect(route('sources.github.show', $source));
    }

    public function delete(int $id): void
    {
        $source = GithubApp::query()->findOrFail($id);

        if (! $this->confirmed($source->name)) {
            return;
        }

        $source->delete();
        $this->toast($source->name.' removed. The app on GitHub was not uninstalled.');
    }

    public function render()
    {
        return view('livewire.sources.index', [
            'sources' => GithubApp::query()->with('privateKey')->withCount('websites')->orderBy('name')->get(),
        ])->layout('layouts.app')->title('Sources');
    }
}
