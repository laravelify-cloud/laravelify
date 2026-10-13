<?php

namespace App\Livewire\Settings;

use App\Livewire\PanelComponent;
use App\Models\Setting;

class Github extends PanelComponent
{
    public string $token = '';

    public bool $hasToken = false;

    public function mount(): void
    {
        $this->hasToken = filled(Setting::getValue('github_token'));
    }

    /**
     * The saved token, for the eye button (after the authenticator code).
     */
    protected function revealableSecret(string $key): ?string
    {
        return $key === 'github_token' ? Setting::getValue('github_token') : null;
    }

    public function save(): void
    {
        $this->validate([
            'token' => ['nullable', 'string', 'max:500'],
        ]);

        if ($this->token === '') {
            $this->toast('Leave the field empty to keep the current token.', 'warning');

            return;
        }

        Setting::putValue('github_token', $this->token);
        $this->token = '';
        $this->hasToken = true;
        $this->toast('GitHub token saved.');
    }

    public function clear(): void
    {
        if (! $this->confirmed('github-token')) {
            return;
        }

        Setting::query()->where('key', 'github_token')->delete();
        $this->hasToken = false;
        $this->token = '';
        $this->toast('GitHub token removed.');
    }

    public function render()
    {
        return view('livewire.settings.github')
            ->layout('layouts.app')
            ->title('GitHub');
    }
}
