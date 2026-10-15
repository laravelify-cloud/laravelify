<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\CloudflareTunnel;
use App\Models\GithubApp;
use App\Models\Group;
use App\Services\CloudflareApi;
use App\Services\CloudflareRoutes;
use App\Services\GithubAppClient;
use App\Services\WebsiteCreator;
use App\Support\Examples;
use App\Support\SiteTypes;

class Form extends PanelComponent
{
    public Group $group;

    public string $name = '';

    public string $type = 'laravel';

    /** A ready-made stack the picker highlights (it still creates a normal website of $type). */
    public string $stack = '';

    public string $domain = '';

    /** Also answer on www.<domain>. */
    public bool $include_www = false;

    /** Other domains that open the same website, one per line. */
    public string $extra_domains = '';

    public string $http_port = '';

    public string $repository = '';

    public string $branch = 'main';

    public bool $use_example = false;

    public string $php_version = '8.4';

    public string $node_version = '22';

    public string $install_command = '';

    public string $build_command = '';

    public string $start_command = '';

    public string $public_dir = '';

    public bool $enable_reverb = false;

    public bool $provision_database = false;

    public string $cloudflare_tunnel_id = '';

    public string $github_app_id = '';

    public bool $picking = false;

    /** @var list<array{name: string, clone_url: string, branch: string}> */
    public array $repositories = [];

    public string $selectedRepository = '';

    /**
     * Pick a framework (or a ready-made stack that creates one).
     */
    public function chooseType(string $type, string $stack = ''): void
    {
        if (! SiteTypes::exists($type)) {
            return;
        }

        $this->type = $type;
        $this->stack = isset(SiteTypes::stacks()[$stack]) && SiteTypes::stacks()[$stack]['type'] === $type ? $stack : '';
        $this->install_command = '';
        $this->build_command = '';
        $this->start_command = '';
        $this->public_dir = $type === 'php' ? 'public' : '';
        $this->resetErrorBag(['type', 'php_version', 'node_version', 'public_dir']);
    }

    public function selectGithubApp(int $id, GithubAppClient $client): void
    {
        $app = GithubApp::query()->findOrFail($id);

        if (! $app->isConnected()) {
            $this->addError('github_app_id', 'Finish connecting this GitHub App before loading repositories.');

            return;
        }

        $this->github_app_id = (string) $app->id;
        $this->repositories = $client->repositories($app);
        $this->selectedRepository = $this->repositories[0]['clone_url'] ?? '';
        $this->repository = '';
    }

    public function loadRepository(): void
    {
        $match = collect($this->repositories)->firstWhere('clone_url', $this->selectedRepository);

        if (! is_array($match)) {
            $this->addError('repository', 'Choose a repository.');

            return;
        }

        $this->repository = $match['clone_url'];
        $this->branch = $match['branch'];

        if ($this->name === '') {
            $this->name = (string) str($match['name'])->after('/');
        }
    }

    public function clearRepository(): void
    {
        $this->repository = '';
        $this->github_app_id = '';
        $this->repositories = [];
        $this->selectedRepository = '';
        $this->picking = true;
    }

    public function save(WebsiteCreator $creator): void
    {
        $this->resetErrorBag();

        $result = $creator->create($this->group, ['aliases' => $this->extra_domains] + $this->only([
            'name', 'type', 'domain', 'include_www', 'http_port', 'repository', 'branch', 'use_example', 'php_version', 'node_version',
            'install_command', 'build_command', 'start_command', 'public_dir', 'enable_reverb', 'provision_database',
            'cloudflare_tunnel_id', 'github_app_id',
        ]));

        $problems = CloudflareRoutes::problems($result['cloudflare']);

        match (true) {
            $result['error'] !== null => $this->toastAfterRedirect('Website saved. Applying nginx or the process manager failed: '.$result['error'], 'error'),
            $problems !== [] => $this->toastAfterRedirect('Website created, but Cloudflare needs attention: '.implode(' ', $problems), 'warning'),
            $result['cloudflare']['lines'] !== [] => $this->toastAfterRedirect('Website created. Its domains were added to the Cloudflare tunnel and DNS. Review nginx and the process file, then deploy.'),
            default => $this->toastAfterRedirect('Website created. Review nginx and the process file, then deploy.'),
        };

        $this->redirect(route('websites.show', $result['website']));
    }

    public function render()
    {
        return view('livewire.websites.form', [
            'groups' => SiteTypes::GROUPS,
            'definition' => SiteTypes::get($this->type),
            'hasExample' => Examples::has($this->type),
            'runtime' => SiteTypes::runtime($this->type),
            'phpVersions' => config('laravelify.php_versions'),
            'nodeVersions' => config('laravelify.node_versions'),
            'tunnels' => CloudflareTunnel::query()->orderBy('name')->get(),
            'cloudflareApi' => app(CloudflareApi::class)->configured(),
            'sources' => GithubApp::query()->with('privateKey')->orderBy('name')->get()->filter->isConnected()->values(),
        ])->layout('layouts.app')->title('Add website');
    }
}
