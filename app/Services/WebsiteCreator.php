<?php

namespace App\Services;

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
            'branch' => $input['use_example'] ? ['nullable'] : ['required', 'string', 'max:120'],
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
        $useExample = $input['use_example'] && Examples::has($validated['type']);
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
            'repository' => $useExample ? $this->examples->pathFor($slug) : ($input['repository'] !== '' ? $input['repository'] : null),
            'branch' => $useExample ? 'main' : $input['branch'],
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
            'provision_database' => $isPhp && $input['provision_database'],
            'deploy_path' => $root.'/'.$slug,
            'webhook_secret' => bin2hex(random_bytes(20)),
        ]);

        if ($website->provision_database) {
            $website->fill($this->databases->credentials($slug));
        }

        $this->renderer->fillNewWebsite($website);
        $website->save();

        try {
            $this->host->writeWebsite($website);
            $this->renderer->refreshTunnel($website->tunnel);
            $error = null;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        $cloudflare = $error === null ? $this->cloudflare->sync($website) : ['configured' => false, 'lines' => [], 'note' => null];

        return ['website' => $website, 'error' => $error, 'cloudflare' => $cloudflare];
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
            'public_dir' => '', 'cloudflare_tunnel_id' => '', 'github_app_id' => ''];

        foreach ($strings as $key => $default) {
            $value = $input[$key] ?? null;
            $input[$key] = $value === null ? $default : trim((string) $value);
        }

        foreach (['use_example', 'enable_reverb', 'provision_database', 'include_www'] as $key) {
            $input[$key] = (bool) ($input[$key] ?? false);
        }

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
