<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\CloudflareTunnel;
use App\Models\GithubApp;
use App\Models\Group;
use App\Models\Website;
use App\Services\ConfigRenderer;
use App\Services\DatabaseProvisioner;
use App\Services\GithubAppClient;
use App\Services\HostWriter;
use App\Support\BuildOutput;
use App\Support\Examples;
use App\Support\SiteTypes;
use App\Support\Slugs;
use Illuminate\Validation\Rule;
use Throwable;

class Form extends PanelComponent
{
    public Group $group;

    public string $name = '';

    public string $type = 'laravel';

    /** A ready-made stack the picker highlights (it still creates a normal website of $type). */
    public string $stack = '';

    public string $domain = '';

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

    public function save(ConfigRenderer $renderer, DatabaseProvisioner $databases, HostWriter $hostWriter): void
    {
        $phpVersions = config('laravelify.php_versions');
        $nodeVersions = config('laravelify.node_versions');
        $runtime = SiteTypes::runtime($this->type);
        $usesNode = $runtime === SiteTypes::RUNTIME_NODE || ($runtime === SiteTypes::RUNTIME_STATIC && $this->type !== 'static');
        $command = ['nullable', 'string', 'max:500', 'regex:/^[^\r\n\0]*$/'];

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(SiteTypes::keys())],
            'domain' => $this->domain === ''
                ? ['nullable']
                : ['string', 'max:190', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', Rule::unique('websites', 'domain')],
            'http_port' => $this->http_port === ''
                ? ['nullable']
                : ['integer', 'between:80,65535', Rule::unique('websites', 'http_port'), Rule::notIn([8000, 3306, 6379])],
            'repository' => ['nullable', 'string', 'max:500'],
            'branch' => $this->use_example ? ['nullable'] : ['required', 'string', 'max:120'],
            'php_version' => $runtime === SiteTypes::RUNTIME_PHP ? ['required', Rule::in($phpVersions)] : ['nullable'],
            'node_version' => $usesNode ? ['required', Rule::in($nodeVersions)] : ['nullable'],
            'install_command' => $command,
            'build_command' => $command,
            'start_command' => $command,
            'public_dir' => ['nullable', 'string', 'max:190', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && $value !== '' && ! BuildOutput::safe(trim((string) $value, '/'))) {
                    $fail('Use a folder inside the repository, like dist or public (no spaces or ..).');
                }
            }],
            'enable_reverb' => ['boolean'],
            'provision_database' => ['boolean'],
            'cloudflare_tunnel_id' => $this->cloudflare_tunnel_id === ''
                ? ['nullable']
                : ['integer', 'exists:cloudflare_tunnels,id'],
            'github_app_id' => $this->github_app_id === ''
                ? ['nullable']
                : ['integer', 'exists:github_apps,id'],
        ]);

        $domain = $this->domain !== '' ? strtolower($this->domain) : null;

        if ($this->cloudflare_tunnel_id !== '' && $domain === null) {
            $this->addError('domain', 'A Cloudflare tunnel needs a domain.');

            return;
        }

        $httpPort = $this->http_port !== '' ? (int) $this->http_port : null;

        if ($domain === null && $httpPort === null) {
            $httpPort = Website::nextPort('http_port', (int) config('laravelify.http_port_start'));
        }

        $slug = Slugs::unique(Website::class, $this->name);

        $useExample = $this->use_example && Examples::has($validated['type']);
        $root = rtrim((string) config('laravelify.sites_root'), '/');
        $isLaravel = $validated['type'] === 'laravel';
        $isPhp = $runtime === SiteTypes::RUNTIME_PHP;
        $isNode = $runtime === SiteTypes::RUNTIME_NODE;

        $website = new Website([
            'group_id' => $this->group->id,
            'cloudflare_tunnel_id' => $this->cloudflare_tunnel_id !== '' ? (int) $this->cloudflare_tunnel_id : null,
            'github_app_id' => $this->github_app_id !== '' ? (int) $this->github_app_id : null,
            'name' => $this->name,
            'slug' => $slug,
            'type' => $validated['type'],
            'domain' => $domain,
            'http_port' => $httpPort,
            'repository' => $useExample ? Examples::prepare($validated['type'], $slug) : ($this->repository !== '' ? $this->repository : null),
            'branch' => $useExample ? 'main' : $this->branch,
            'php_version' => $isPhp ? $this->php_version : null,
            'node_version' => $usesNode ? $this->node_version : null,
            'install_command' => $usesNode && $this->install_command !== '' ? $this->install_command : null,
            'build_command' => $usesNode && $this->build_command !== '' ? $this->build_command : null,
            'start_command' => $isNode && $this->start_command !== '' ? $this->start_command : null,
            'public_dir' => $this->publicDirectory($validated['type']),
            'port' => $isNode ? Website::nextPort('port', (int) config('laravelify.node_port_start')) : null,
            'enable_reverb' => $isLaravel && $this->enable_reverb,
            'reverb_port' => $isLaravel && $this->enable_reverb
                ? Website::nextPort('reverb_port', (int) config('laravelify.reverb_port_start'))
                : null,
            'provision_database' => $isPhp && $this->provision_database,
            'deploy_path' => $root.'/'.$slug,
            'webhook_secret' => bin2hex(random_bytes(20)),
        ]);

        if ($website->provision_database) {
            $website->fill($databases->credentials($slug));
        }

        $renderer->fillNewWebsite($website);
        $website->save();

        try {
            $hostWriter->writeWebsite($website);
            $renderer->refreshTunnel($website->tunnel);
            $this->toastAfterRedirect('Website created. Review nginx and the process file, then deploy.');
        } catch (Throwable $exception) {
            $this->toastAfterRedirect('Website saved. Applying nginx or the process manager failed: '.$exception->getMessage(), 'error');
        }

        $this->redirect(route('websites.show', $website));
    }

    /**
     * The folder saved on the site: the web root for plain PHP, the output folder for
     * built front-ends and static sites, nothing for the rest.
     */
    private function publicDirectory(string $type): ?string
    {
        if (! in_array(SiteTypes::runtime($type), [SiteTypes::RUNTIME_PHP, SiteTypes::RUNTIME_STATIC], true) || $type === 'laravel') {
            return null;
        }

        $folder = trim($this->public_dir, '/');

        return $folder !== '' ? $folder : ($type === 'php' ? 'public' : null);
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
            'sources' => GithubApp::query()->with('privateKey')->orderBy('name')->get()->filter->isConnected()->values(),
        ])->layout('layouts.app')->title('Add website');
    }
}
