<?php

namespace App\Livewire\Sources;

use App\Livewire\PanelComponent;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Services\GithubAppClient;
use Throwable;

class GithubShow extends PanelComponent
{
    public GithubApp $githubApp;

    public string $name = '';

    public string $organization = '';

    public string $html_url = '';

    public string $api_url = '';

    public string $git_user = 'git';

    public string $git_port = '22';

    public string $app_id = '';

    public string $installation_id = '';

    public string $client_id = '';

    public string $client_secret = '';

    public string $webhook_secret = '';

    public string $private_key_id = '';

    public bool $manual = false;

    public string $webhook_endpoint = '';

    public string $notice = '';

    public bool $noticeOk = true;

    public function mount(GithubApp $githubApp): void
    {
        $this->githubApp = $githubApp;
        $this->name = $githubApp->name;
        $this->organization = $githubApp->organization ?? '';
        $this->html_url = $githubApp->html_url;
        $this->api_url = $githubApp->api_url;
        $this->git_user = $githubApp->git_user;
        $this->git_port = (string) $githubApp->git_port;
        $this->app_id = (string) $githubApp->app_id;
        $this->installation_id = (string) $githubApp->installation_id;
        $this->client_id = (string) $githubApp->client_id;
        $this->client_secret = (string) $githubApp->client_secret;
        $this->webhook_secret = (string) $githubApp->webhook_secret;
        $this->private_key_id = (string) $githubApp->private_key_id;
        $this->manual = request()->boolean('manual');
        $this->webhook_endpoint = rtrim(request()->getSchemeAndHttpHost(), '/');
    }

    public function createGithubAppManually(): void
    {
        $this->manual = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'organization' => ['nullable', 'string', 'max:120'],
            'html_url' => ['required', 'url', 'max:190'],
            'api_url' => ['required', 'url', 'max:190'],
            'git_user' => ['required', 'string', 'max:40'],
            'git_port' => ['required', 'integer', 'between:1,65535'],
            'app_id' => ['required', 'string', 'max:40'],
            'installation_id' => ['required', 'string', 'max:40'],
            'client_id' => ['nullable', 'string', 'max:120'],
            'client_secret' => ['nullable', 'string', 'max:500'],
            'webhook_secret' => ['nullable', 'string', 'max:500'],
            'private_key_id' => ['required', 'exists:private_keys,id'],
        ], [
            'private_key_id.required' => 'Select a private key.',
        ]);

        $this->githubApp->fill([
            'name' => $validated['name'],
            'organization' => $this->organization !== '' ? $this->organization : null,
            'html_url' => $validated['html_url'],
            'api_url' => $validated['api_url'],
            'git_user' => $validated['git_user'],
            'git_port' => (int) $validated['git_port'],
            'app_id' => $validated['app_id'],
            'installation_id' => $validated['installation_id'],
            'client_id' => $this->client_id !== '' ? $this->client_id : null,
            'private_key_id' => $validated['private_key_id'],
            'private_key' => null,
        ]);

        if ($this->client_secret !== '') {
            $this->githubApp->client_secret = $this->client_secret;
        }

        if ($this->webhook_secret !== '') {
            $this->githubApp->webhook_secret = $this->webhook_secret;
        }

        $this->githubApp->save();
        $this->githubApp->refresh();
        $this->client_secret = (string) $this->githubApp->client_secret;
        $this->webhook_secret = (string) $this->githubApp->webhook_secret;
        $this->notice = 'GitHub App saved.';
        $this->noticeOk = true;
        $this->toast('GitHub App saved.');
    }

    public function testConnection(GithubAppClient $client): void
    {
        try {
            $this->notice = $client->testConnection($this->githubApp->fresh());
            $this->noticeOk = true;
            $this->toast($this->notice);
        } catch (Throwable $exception) {
            $this->notice = $exception->getMessage();
            $this->noticeOk = false;
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function delete(): void
    {
        if (! $this->confirmed($this->githubApp->name)) {
            return;
        }

        $this->githubApp->delete();
        $this->toastAfterRedirect('GitHub App removed. The app on GitHub was not uninstalled.');
        $this->redirect(route('sources.index'));
    }

    public function render()
    {
        return view('livewire.sources.github-show', [
            'webhookUrl' => url('/hooks/github-app/'.$this->githubApp->slug),
            'privateKeys' => PrivateKey::query()->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app')->title($this->githubApp->name);
    }
}
