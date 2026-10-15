<?php

namespace App\Livewire\Settings;

use App\Livewire\PanelComponent;
use App\Models\Setting;
use App\Services\CloudflareApi;
use Throwable;

/**
 * Settings → Cloudflare: the API token Laravelify uses to add a website's domains to its tunnel
 * and to Cloudflare DNS by itself.
 */
class Cloudflare extends PanelComponent
{
    public string $token = '';

    public bool $hasToken = false;

    /** @var array{ok: bool, message: string, zones: list<string>}|null */
    public ?array $result = null;

    public function mount(CloudflareApi $api): void
    {
        $this->hasToken = $api->configured();
    }

    protected function revealableSecret(string $key): ?string
    {
        return $key === CloudflareApi::SETTING ? Setting::getValue(CloudflareApi::SETTING) : null;
    }

    public function save(CloudflareApi $api): void
    {
        $this->validate(['token' => ['nullable', 'string', 'max:500', 'regex:/^[A-Za-z0-9_-]+$/']], [
            'token.regex' => 'This does not look like a Cloudflare API token: letters, numbers, - and _ only.',
        ]);

        if ($this->token === '') {
            $this->toast('Leave the field empty to keep the current token.', 'warning');

            return;
        }

        Setting::putValue(CloudflareApi::SETTING, trim($this->token));
        $this->token = '';
        $this->hasToken = true;
        $this->test($api);
    }

    public function test(CloudflareApi $api): void
    {
        try {
            $check = $api->verify();
            $ok = $check['status'] === 'active';
            $count = count($check['zones']);
            $this->result = [
                'ok' => $ok,
                'message' => match (true) {
                    ! $ok => 'Cloudflare says the token is '.$check['status'].'.',
                    $count === 0 => 'The token works, but it sees no domains. Give it Zone → DNS → Edit on your domains.',
                    default => 'The token works and can see '.$count.' '.($count === 1 ? 'domain' : 'domains').'.',
                },
                'zones' => $check['zones'],
            ];
            $this->toast($this->result['message'], $ok ? 'success' : 'error');
        } catch (Throwable $exception) {
            $this->result = ['ok' => false, 'message' => $exception->getMessage(), 'zones' => []];
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function clear(): void
    {
        if (! $this->confirmed('cloudflare-token')) {
            return;
        }

        Setting::query()->where('key', CloudflareApi::SETTING)->delete();
        $this->hasToken = false;
        $this->result = null;
        $this->toast('Cloudflare token removed. Domains are no longer added to Cloudflare by Laravelify.');
    }

    public function render()
    {
        return view('livewire.settings.cloudflare')
            ->layout('layouts.app')
            ->title('Cloudflare');
    }
}
