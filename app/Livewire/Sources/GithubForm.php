<?php

namespace App\Livewire\Sources;

use App\Livewire\PanelComponent;
use App\Models\GithubApp;
use App\Support\Slugs;

class GithubForm extends PanelComponent
{
    public string $name = '';

    public string $organization = '';

    public string $html_url = 'https://github.com';

    public string $api_url = 'https://api.github.com';

    public string $git_user = 'git';

    public string $git_port = '22';

    public bool $pull_request_previews = true;

    public bool $github_runners = false;

    public function mount(): void
    {
        if ($this->name === '') {
            $this->name = $this->randomName();
        }
    }

    public function save(): void
    {
        $this->organization = trim($this->organization);

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'organization' => [$this->github_runners ? 'required' : 'nullable', 'string', 'max:120', 'regex:/^[^\s\/?#]+$/'],
            'html_url' => ['required', 'url', 'max:190'],
            'api_url' => ['required', 'url', 'max:190'],
            'git_user' => ['required', 'string', 'max:40'],
            'git_port' => ['required', 'integer', 'between:1,65535'],
            'pull_request_previews' => ['boolean'],
            'github_runners' => ['boolean'],
        ], [
            'organization.required' => 'GitHub Actions runners need an organization.',
        ]);

        $app = GithubApp::query()->create([
            'name' => $this->name,
            'slug' => Slugs::unique(GithubApp::class, $this->name),
            'organization' => $this->organization !== '' ? $this->organization : null,
            'html_url' => rtrim($this->html_url, '/'),
            'api_url' => rtrim($this->api_url, '/'),
            'git_user' => $this->git_user,
            'git_port' => (int) $this->git_port,
        ]);

        $this->toastAfterRedirect('GitHub App saved.');
        $this->redirect(route('sources.github.show', [
            'githubApp' => $app,
            'previews' => $this->pull_request_previews ? 1 : 0,
            'runners' => $this->github_runners ? 1 : 0,
        ]));
    }

    public function render()
    {
        return view('livewire.sources.github-form')
            ->layout('layouts.app')
            ->title('New GitHub App');
    }

    private function randomName(): string
    {
        $adjectives = ['brave', 'calm', 'lucky', 'quick', 'scary', 'silent', 'swift', 'wild'];
        $nouns = ['falcon', 'lynx', 'otter', 'panda', 'squirrel', 'tiger', 'wolf'];

        return $adjectives[array_rand($adjectives)].'-'.$nouns[array_rand($nouns)].'-'.substr(bin2hex(random_bytes(6)), 0, 12);
    }
}
