<?php

namespace App\Services;

use App\Models\CloudflareTunnel;
use App\Models\GithubApp;
use App\Models\Group;
use App\Models\Website;
use App\Support\BuildOutput;
use App\Support\Examples;
use App\Support\SiteTypes;
use App\Support\Slugs;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Creates a website the same way from the "Add website" form and from the MCP server:
 * checks the input, picks free ports, writes nginx and the process file, and joins the tunnel.
 */
class WebsiteCreator
{
    public function __construct(
        private ConfigRenderer $renderer,
        private DatabaseProvisioner $databases,
        private HostWriter $host,
        private PortAllocator $ports,
        private WebsiteDomains $domains,
        private CloudflareRoutes $cloudflare,
        private ExampleRepositories $examples,
        private GithubAppClient $github,
    ) {}

    /**
     * @param  array<string, mixed>  $input  name, type, domain, aliases (extra domains), include_www, http_port, repository, branch, use_example,
     *                                       php_version, node_version, install_command, build_command, start_command,
     *                                       public_dir, enable_reverb, provision_database, cloudflare_tunnel_id, github_app_id
     * @return array{website: Website, error: ?string, cloudflare: array{configured: bool, lines: list<array{host: string, route: string, dns: string, ok: bool}>, note: ?string}}
     *                                                                                                                                                                             The error is set when the website was saved but nginx or
     *                                                                                                                                                                             the process manager could not be applied.
     *
     * @throws ValidationException
     */
    public function create(Group $group, array $input): array
    {
        $input = $this->withDefaults($input);
        $type = (string) $input['type'];
        $runtime = SiteTypes::runtime($type);
        $usesNode = $runtime === SiteTypes::RUNTIME_NODE || ($runtime === SiteTypes::RUNTIME_STATIC && $type !== 'static');
        $command = ['nullable', 'string', 'max:500', 'regex:/^[^\r\n\0]*$/'];

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(SiteTypes::keys())],
            'domain' => $input['domain'] === ''
                ? ['nullable']
                : ['string', 'max:190', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', Rule::unique('websites', 'domain')],
            'http_port' => $input['http_port'] === ''
                ? ['nullable']
                : ['integer', 'between:80,65535', Rule::unique('websites', 'http_port'), Rule::notIn([8000, 3306, 6379])],
            'repository' => ['nullable', 'string', 'max:500'],
            'branch' => $input['use_example'] || $input['create_repository'] ? ['nullable'] : ['required', 'string', 'max:120'],
            'php_version' => $runtime === SiteTypes::RUNTIME_PHP ? ['required', Rule::in(config('laravelify.php_versions'))] : ['nullable'],
            'node_version' => $usesNode ? ['required', Rule::in(config('laravelify.node_versions'))] : ['nullable'],
            'install_command' => $command,
            'build_command' => $command,
            'start_command' => $command,
            'public_dir' => ['nullable', 'string', 'max:190', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && $value !== '' && ! BuildOutput::safe(trim((string) $value, '/'))) {
                    $fail('Use a folder inside the repository, like dist or public (no spaces or ..).');
                }
            }],
            'enable_reverb' => ['boolean'],
            'provision_database' => ['boolean'],
            'cloudflare_tunnel_id' => $input['cloudflare_tunnel_id'] === '' ? ['nullable'] : ['integer', 'exists:cloudflare_tunnels,id'],
            'github_app_id' => $input['github_app_id'] === '' ? ['nullable'] : ['integer', 'exists:github_apps,id'],
        ])->validate();

        $domain = $input['domain'] !== '' ? strtolower((string) $input['domain']) : null;

        // A domain without a tunnel gets no route and no DNS record: when none was chosen, the
        // domain goes through the server's own tunnel (the one the panel is reached through).
        if ($domain !== null && $input['cloudflare_tunnel_id'] === '' && ($tunnel = CloudflareTunnel::query()->oldest('id')->first())) {
            $input['cloudflare_tunnel_id'] = (string) $tunnel->id;
        }
        $aliases = $this->aliasesFor($domain, $input);
        $this->domains->validate(null, $domain, $aliases);

        if ($input['cloudflare_tunnel_id'] !== '' && $domain === null) {
            throw ValidationException::withMessages(['domain' => 'A Cloudflare tunnel needs a domain.']);
        }

        $httpPort = $input['http_port'] !== '' ? (int) $input['http_port'] : null;

        if ($httpPort !== null && ($holder = $this->ports->holder($httpPort)) !== null) {
            throw ValidationException::withMessages(['http_port' => 'Port '.$httpPort.' is used by '.$holder.'. Leave it empty to get a free port.']);
        }

        if ($domain === null && $httpPort === null) {
            $httpPort = Website::nextPort('http_port', (int) config('laravelify.http_port_start'));
        }

        $slug = Slugs::unique(Website::class, (string) $input['name']);
        $created = $input['create_repository'] ? $this->createRepository($validated['type'], $slug, $input) : null;
        $useExample = $created === null && $input['use_example'] && Examples::has($validated['type']);
        $root = rtrim((string) config('laravelify.sites_root'), '/');
        $isLaravel = $validated['type'] === 'laravel';
        $isPhp = $runtime === SiteTypes::RUNTIME_PHP;
        $isNode = $runtime === SiteTypes::RUNTIME_NODE;

        $website = new Website([
            'group_id' => $group->id,
            'cloudflare_tunnel_id' => $input['cloudflare_tunnel_id'] !== '' ? (int) $input['cloudflare_tunnel_id'] : null,
            'github_app_id' => $input['github_app_id'] !== '' ? (int) $input['github_app_id'] : null,
            'name' => $input['name'],
            'slug' => $slug,
            'type' => $validated['type'],
            'domain' => $domain,
            'aliases' => $aliases === [] ? null : $aliases,
            'http_port' => $httpPort,
            'repository' => match (true) {
                $created !== null => $created['clone_url'],
                $useExample => $this->examples->pathFor($slug),
                default => $input['repository'] !== '' ? $input['repository'] : null,
            },
            'branch' => $useExample || $created !== null ? 'main' : $input['branch'],
            'seed_example' => $created !== null,
            'php_version' => $isPhp ? $input['php_version'] : null,
            'node_version' => $usesNode ? $input['node_version'] : null,
            'install_command' => $usesNode && $input['install_command'] !== '' ? $input['install_command'] : null,
            'build_command' => $usesNode && $input['build_command'] !== '' ? $input['build_command'] : null,
            'start_command' => $isNode && $input['start_command'] !== '' ? $input['start_command'] : null,
            'public_dir' => $this->publicDirectory($validated['type'], (string) $input['public_dir']),
            'port' => $isNode ? Website::nextPort('port', (int) config('laravelify.node_port_start')) : null,
            'enable_reverb' => $isLaravel && $input['enable_reverb'],
            'reverb_port' => $isLaravel && $input['enable_reverb']
                ? Website::nextPort('reverb_port', (int) config('laravelify.reverb_port_start'))
                : null,
            'provision_database' => ($isPhp || $isNode) && $input['provision_database'],
            'deploy_path' => $root.'/'.$slug,
            'webhook_secret' => bin2hex(random_bytes(20)),
        ]);

        if ($website->provision_database) {
            $website->fill($this->databases->credentials($slug));
        }

        $this->renderer->fillNewWebsite($website);
        $website->save();

        // With error tracking on, the new website gets its GlitchTip project and key right away.
        if (app(Glitchtip::class)->installed()) {
            try {
                app(Glitchtip::class)->connect($website);
            } catch (Throwable) {
                // The Errors section of the website still offers to connect it.
            }
        }

        try {
            // A folder left by a deleted website of the same name belongs to the shared user again;
            // a new website has no visitors yet, so it takes the folder over with its own user now.
            $sites = app(SiteUser::class);

            if ($sites->perSiteUsers() && is_dir((string) $website->deploy_path) && ! $sites->for($website)->isOwn()) {
                $this->host->separateSite($website);
            }

            $this->host->writeWebsite($website);
            $this->renderer->refreshTunnel($website->tunnel);
            $error = null;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        $cloudflare = $error === null ? $this->cloudflare->sync($website) : ['configured' => false, 'lines' => [], 'note' => null];

        // The new repository is still empty: the first deploy pushes the example to it.
        if ($created !== null && $error === null) {
            // Fresh from the database: columns left to their defaults (enabled) are not on the model yet.
            $website->refresh()->queueDeployment('create');
        }

        return ['website' => $website, 'error' => $error, 'cloudflare' => $cloudflare];
    }

    /**
     * Make the new, empty GitHub repository a website made from an example deploys from.
     *
     * @param  array<string, mixed>  $input
     * @return array{name: string, clone_url: string, branch: string}
     *
     * @throws ValidationException
     */
    private function createRepository(string $type, string $slug, array $input): array
    {
        if (! Examples::has($type)) {
            throw ValidationException::withMessages(['type' => 'There is no example project for this framework.']);
        }

        $app = $input['github_app_id'] !== '' ? GithubApp::query()->find((int) $input['github_app_id']) : null;

        if (! $app?->isConnected()) {
            throw ValidationException::withMessages(['github_app_id' => 'Choose a connected GitHub App.']);
        }

        $name = $input['new_repository_name'] !== '' ? $input['new_repository_name'] : $slug;

        if (! preg_match('/^[A-Za-z0-9._-]{1,100}$/', $name) || in_array($name, ['.', '..'], true)) {
            throw ValidationException::withMessages(['new_repository_name' => 'Use letters, numbers, dots, dashes and underscores.']);
        }

        try {
            return $this->github->createRepository($app, $name, $input['new_repository_private']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['new_repository_name' => $exception->getMessage()]);
        }
    }

    /**
     * The extra domains from the input (a list, or text with one per line), plus www.<domain> when asked.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function aliasesFor(?string $domain, array $input): array
    {
        $aliases = WebsiteDomains::normalize($input['aliases'] ?? []);

        if ($domain !== null && $input['include_www'] && WebsiteDomains::isApex($domain)) {
            $aliases[] = WebsiteDomains::wwwOf($domain);
        }

        return array_values(array_diff(array_unique($aliases), [$domain]));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function withDefaults(array $input): array
    {
        $strings = ['name' => '', 'type' => 'laravel', 'domain' => '', 'http_port' => '', 'repository' => '', 'branch' => 'main',
            'php_version' => '8.4', 'node_version' => '22', 'install_command' => '', 'build_command' => '', 'start_command' => '',
            'public_dir' => '', 'cloudflare_tunnel_id' => '', 'github_app_id' => '', 'new_repository_name' => ''];

        foreach ($strings as $key => $default) {
            $value = $input[$key] ?? null;
            $input[$key] = $value === null ? $default : trim((string) $value);
        }

        foreach (['use_example', 'enable_reverb', 'provision_database', 'include_www', 'create_repository'] as $key) {
            $input[$key] = (bool) ($input[$key] ?? false);
        }

        $input['new_repository_private'] = (bool) ($input['new_repository_private'] ?? true);

        if ($input['public_dir'] === '' && $input['type'] === 'php') {
            $input['public_dir'] = 'public';
        }

        return $input;
    }

    /**
     * The folder saved on the site: the web root for plain PHP, the output folder for
     * built front-ends and static sites, nothing for the rest.
     */
    private function publicDirectory(string $type, string $folder): ?string
    {
        if (! in_array(SiteTypes::runtime($type), [SiteTypes::RUNTIME_PHP, SiteTypes::RUNTIME_STATIC], true) || $type === 'laravel') {
            return null;
        }

        $folder = trim($folder, '/');

        return $folder !== '' ? $folder : ($type === 'php' ? 'public' : null);
    }
}
