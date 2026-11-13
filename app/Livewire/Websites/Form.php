<?php

namespace App\Livewire\Websites;

use App\Livewire\Concerns\FollowsTasks;
use App\Livewire\PanelComponent;
use App\Models\CloudflareTunnel;
use App\Models\GithubApp;
use App\Models\Group;
use App\Services\CloudDomains;
use App\Services\CloudflareApi;
use App\Services\CloudflareRoutes;
use App\Services\DatabaseServers;
use App\Services\DomainSuggestions;
use App\Services\GithubAppClient;
use App\Services\PortAllocator;
use App\Services\ServerProcesses;
use App\Services\WebsiteCreator;
use App\Support\DatabaseEngines;
use App\Support\Examples;
use App\Support\ProjectDetector;
use App\Support\SiteTypes;
use Livewire\Attributes\Renderless;
use Throwable;

class Form extends PanelComponent
{
    use FollowsTasks;

    /** What the browser keeps as a draft of the form, and puts back after a reload. */
    public const DRAFT_FIELDS = [
        'name', 'type', 'stack', 'domain', 'include_www', 'extra_domains', 'http_port', 'repository', 'branch',
        'use_example', 'create_repository', 'new_repository_name', 'new_repository_private', 'php_version', 'node_version', 'runtime_version',
        'install_command', 'build_command', 'start_command', 'public_dir', 'enable_reverb', 'provision_database', 'db_engine',
        'cloudflare_tunnel_id', 'github_app_id', 'picking', 'repositories', 'selectedRepository',
    ];

    /**
     * Put a saved draft back: only the form's own fields, with their own types.
     *
     * @param  array<string, mixed>  $draft
     */
    public function restoreDraft(array $draft): void
    {
        foreach (self::DRAFT_FIELDS as $field) {
            if (! array_key_exists($field, $draft)) {
                continue;
            }

            $value = $draft[$field];
            $this->{$field} = match (gettype($this->{$field})) {
                'boolean' => (bool) $value,
                'array' => is_array($value) ? array_values(array_filter($value, 'is_array')) : [],
                default => is_scalar($value) ? (string) $value : '',
            };
        }

        if (! SiteTypes::exists($this->type)) {
            $this->type = 'laravel';
            $this->stack = '';
        }

        $definition = SiteTypes::get($this->type);
        $this->dispatch('type-detected', group: SiteTypes::stacks()[$this->stack]['group'] ?? $definition['group']);
        $this->resetErrorBag();
    }

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

    /** Make a new GitHub repository from the example and deploy from it. */
    public bool $create_repository = false;

    public string $new_repository_name = '';

    public bool $new_repository_private = true;

    public string $php_version = '8.4';

    public string $node_version = '22';

    /** Python, Go, Bun or Hugo version, for types with their own toolchain. */
    public string $runtime_version = '';

    public string $install_command = '';

    public string $build_command = '';

    public string $start_command = '';

    public string $public_dir = '';

    public bool $enable_reverb = false;

    public bool $provision_database = false;

    /** The database engine, '' for the server's default (see DatabaseEngines). */
    public string $db_engine = '';

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

    /**
     * Where the code comes from: a Git repository, the built-in example, or a new GitHub repository made from it.
     */
    public function chooseSource(string $source): void
    {
        $this->use_example = $source === 'example';
        $this->create_repository = $source === 'new';
        $this->resetErrorBag(['repository', 'branch', 'github_app_id', 'new_repository_name']);
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

    public function loadRepository(GithubAppClient $client): void
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

        $this->detectProject($client, $match['name'], $match['branch']);
    }

    /**
     * Pick the framework and the PHP or Node version from the repository's own files.
     */
    private function detectProject(GithubAppClient $client, string $fullName, string $branch): void
    {
        $app = $this->github_app_id !== '' ? GithubApp::query()->find((int) $this->github_app_id) : null;

        if (! $app?->isConnected()) {
            return;
        }

        try {
            $files = $client->files($app, $fullName, $branch, [
                'composer.json', 'package.json', '.nvmrc', '.node-version', 'index.html',
                'requirements.txt', 'pyproject.toml', 'manage.py', '.python-version', 'go.mod', 'bun.lock', 'bun.lockb', 'hugo.toml', 'hugo.yaml',
                'pom.xml', 'build.gradle', 'build.gradle.kts', 'Program.cs', 'Dockerfile',
            ]);
            // .NET projects are named after the app (Shop.Api.csproj, Shop.sln): look at the top folder for one.
            $names = $client->rootNames($app, $fullName, $branch);
            $project = collect($names)->first(fn (string $name): bool => str_ends_with($name, '.csproj'))
                ?? collect($names)->first(fn (string $name): bool => str_ends_with($name, '.sln') || str_ends_with($name, '.slnx'));
            $csproj = $project !== null && str_ends_with($project, '.csproj') ? ($client->files($app, $fullName, $branch, [$project])[$project] ?? null) : null;
        } catch (Throwable) {
            return;
        }

        $found = ProjectDetector::detect([
            'dotnet_project' => $project,
            'csproj' => $csproj,
            'composer' => $files['composer.json'],
            'package' => $files['package.json'],
            'nvmrc' => $files['.nvmrc'] ?? $files['.node-version'],
            'index' => $files['index.html'] !== null,
            'requirements' => $files['requirements.txt'] ?? null,
            'pyproject' => $files['pyproject.toml'] ?? null,
            'manage' => ($files['manage.py'] ?? null) !== null,
            'python_version' => $files['.python-version'] ?? null,
            'gomod' => $files['go.mod'] ?? null,
            'bun' => ($files['bun.lock'] ?? $files['bun.lockb'] ?? null) !== null,
            'hugo' => ($files['hugo.toml'] ?? $files['hugo.yaml'] ?? null) !== null,
            'maven' => $files['pom.xml'] ?? null,
            'gradle' => $files['build.gradle'] ?? $files['build.gradle.kts'] ?? null,
            'dotnet' => ($files['Program.cs'] ?? null) !== null,
            'dockerfile' => ($files['Dockerfile'] ?? null) !== null,
        ]);

        if ($found['type'] === null) {
            $this->toast('Could not tell the framework from the repository. Choose it below.', 'warning');

            return;
        }

        $this->chooseType($found['type'], $found['stack']);
        $this->php_version = $found['php_version'] ?? $this->php_version;
        $this->node_version = $found['node_version'] ?? $this->node_version;
        $this->runtime_version = (string) ($found['runtime_version'] ?? '');

        $definition = SiteTypes::get($found['type']);
        $label = $found['stack'] !== '' ? (SiteTypes::stacks()[$found['stack']]['label'] ?? $definition['label']) : $definition['label'];
        $runtime = SiteTypes::runtime($found['type']);
        $versions = array_filter([
            $runtime === SiteTypes::RUNTIME_PHP ? 'PHP '.$this->php_version : null,
            $runtime !== SiteTypes::RUNTIME_PHP || $found['node_version'] !== null ? 'Node '.$this->node_version : null,
        ]);

        $this->dispatch('type-detected', group: SiteTypes::stacks()[$found['stack']]['group'] ?? $definition['group']);
        $this->toast('Detected '.$label.' ('.$found['reason'].')'.($versions !== [] ? ', '.implode(', ', $versions) : '').'.');
    }

    public function clearRepository(): void
    {
        $this->repository = '';
        $this->github_app_id = '';
        $this->repositories = [];
        $this->selectedRepository = '';
        $this->picking = true;
    }

    /** Install PostgreSQL or MongoDB in the background, so the new site can use it. */
    public function installDatabaseEngine(string $engine): void
    {
        if (! DatabaseServers::installable($engine)) {
            return;
        }

        $this->runInBackground('databases.install', 'databases', 'Installing '.DatabaseEngines::label($engine), ['engine' => $engine]);
        $this->toast('Installing '.DatabaseEngines::label($engine).'. This takes a few minutes; you can keep filling in the form.');
    }

    public function save(WebsiteCreator $creator): void
    {
        $this->resetErrorBag();

        $result = $creator->create($this->group, ['aliases' => $this->extra_domains] + $this->only([
            'name', 'type', 'domain', 'include_www', 'http_port', 'repository', 'branch', 'use_example', 'php_version', 'node_version',
            'install_command', 'build_command', 'start_command', 'public_dir', 'enable_reverb', 'provision_database', 'db_engine',
            'cloudflare_tunnel_id', 'github_app_id', 'create_repository', 'new_repository_name', 'new_repository_private',
        ]));

        $problems = CloudflareRoutes::problems($result['cloudflare']);

        match (true) {
            $result['error'] === null && $this->create_repository => $this->toastAfterRedirect('Repository '.$result['website']->repository.' created. The first deploy is running.'),
            $result['error'] !== null => $this->toastAfterRedirect('Website saved. Applying nginx or the process manager failed: '.$result['error'], 'error'),
            $problems !== [] => $this->toastAfterRedirect('Website created, but Cloudflare needs attention: '.implode(' ', $problems), 'warning'),
            $result['cloudflare']['lines'] !== [] => $this->toastAfterRedirect('Website created. Its domains were added to the Cloudflare tunnel and DNS. Review nginx and the process file, then deploy.'),
            default => $this->toastAfterRedirect('Website created. Review nginx and the process file, then deploy.'),
        };

        // The website exists now: the next page drops the browser's draft of this form.
        session()->flash('clear-draft', 'laravelify.website-draft.'.$this->group->id);
        $this->redirect(route('websites.show', $result['website']));
    }

    /**
     * Cloudflare zones and the host names already used in them, for the domain picker.
     *
     * @return array{configured: bool, error: ?string, zones: list<array{name: string, records: list<array{name: string, type: string, content: string, website: ?string}>}>}
     */
    #[Renderless]
    public function domainOptions(DomainSuggestions $domains, bool $fresh = false): array
    {
        return $domains->options($fresh);
    }

    public function randomDomain(string $zone, DomainSuggestions $domains): void
    {
        $this->domain = $domains->random($zone);
        $this->http_port = '';
        $this->resetErrorBag('domain');
    }

    public function randomPort(PortAllocator $ports): void
    {
        $this->http_port = (string) $ports->random();
        $this->domain = '';
        $this->resetErrorBag('http_port');
    }

    /**
     * Ports for the "Used ports" modal (loaded when it opens, without a re-render).
     *
     * @return array{rows: list<array<string, mixed>>, next_free: int, checked: bool}
     */
    #[Renderless]
    public function portMap(ServerProcesses $processes): array
    {
        return $processes->portMap();
    }

    /** Use <client>-<name>.<domain> from Laravelify Cloud as the website's domain. */
    public function useFreeDomain(string $name, CloudDomains $free): void
    {
        $name = strtolower(trim($name));
        $this->resetErrorBag('free_domain');

        if (! $free->configured() || ! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,28}[a-z0-9])?$/', $name) || str_contains($name, '--')) {
            $this->addError('free_domain', 'Use 1 to 30 letters, numbers and dashes, starting and ending with a letter or number.');

            return;
        }

        $this->domain = $free->hostFor($name);
        $this->include_www = false;
    }

    public function render()
    {
        $free = app(CloudDomains::class);

        return view('livewire.websites.form', [
            'freeDomains' => $free->configured() ? ['prefix' => $free->prefix(), 'domain' => $free->domain()] : null,
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
