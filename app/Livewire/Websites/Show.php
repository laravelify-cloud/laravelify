<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\CloudflareTunnel;
use App\Models\Deployment;
use App\Models\GithubApp;
use App\Models\Website;
use App\Services\CloudflareRoutes;
use App\Services\ConfigRenderer;
use App\Services\DatabaseProvisioner;
use App\Services\HostWriter;
use App\Services\PanelPreview;
use App\Services\PanelTaskRunner;
use App\Services\ServerProcesses;
use App\Services\SiteSecurity;
use App\Services\WebsiteDomains;
use App\Services\WebsiteRedirects;
use App\Services\WebsiteSwitch;
use App\Support\BuildOutput;
use App\Support\EnvFile;
use App\Support\GitInput;
use App\Support\Redirects;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Throwable;

class Show extends PanelComponent
{
    public Website $website;

    public string $name = '';

    public string $domain = '';

    /** Other domains that open this website, one per line. */
    public string $aliases_text = '';

    /**
     * Redirect rows of the Redirects tab.
     *
     * @var list<array{from: string, to: string, code: int, keep_path: bool, match: string}>
     */
    public array $redirect_rows = [];

    public string $http_port = '';

    public string $repository = '';

    public string $branch = 'main';

    public string $cloudflare_tunnel_id = '';

    public string $github_app_id = '';

    public string $php_version = '8.4';

    public string $node_version = '22';

    public string $install_command = '';

    public string $build_command = '';

    public string $start_command = '';

    public string $public_dir = '';

    public ?int $port = null;

    public bool $enable_reverb = false;

    public string $env_content = '';

    public string $nginx_config = '';

    public string $process_config = '';

    public bool $hide_from_search = false;

    public bool $block_crawlers = false;

    public bool $basic_auth_enabled = false;

    public string $basic_auth_user = '';

    /** Only sent when changing it; the saved password is never loaded back. */
    public string $basic_auth_password = '';

    public string $basic_auth_except = '';

    public string $blocked_ips = '';

    public ?int $deploymentId = null;

    /** @var list<string> */
    public array $selectedDeployments = [];

    public function mount(Website $website): void
    {
        $this->website = $website;
        $this->fillFromWebsite();
        $this->deploymentId = $website->deployments()->value('id');
    }

    public function saveGeneral(ConfigRenderer $renderer, WebsiteDomains $domains, CloudflareRoutes $cloudflare): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'http_port' => $this->http_port === ''
                ? ['nullable']
                : ['integer', 'between:80,65535', Rule::unique('websites', 'http_port')->ignore($this->website->id), Rule::notIn([8000, 3306, 6379])],
            'repository' => ['nullable', 'string', 'max:500', fn (string $attribute, mixed $value, \Closure $fail) => GitInput::validRepository((string) $value) ? null : $fail('Use a Git URL like https://github.com/owner/repo.git, owner/repo, or a folder path.')],
            'branch' => [fn (string $attribute, mixed $value, \Closure $fail) => GitInput::validBranch((string) $value) ? null : $fail('That is not a branch name.'), 'required', 'string', 'max:120'],
            'cloudflare_tunnel_id' => $this->cloudflare_tunnel_id === ''
                ? ['nullable']
                : ['integer', 'exists:cloudflare_tunnels,id'],
            'github_app_id' => $this->github_app_id === ''
                ? ['nullable']
                : ['integer', 'exists:github_apps,id'],
        ]);

        $domain = trim($this->domain) !== '' ? WebsiteDomains::normalize($this->domain)[0] ?? null : null;
        $aliases = WebsiteDomains::normalize($this->aliases_text);

        if ($this->cloudflare_tunnel_id !== '' && $domain === null) {
            $this->addError('domain', 'A Cloudflare tunnel needs a domain.');

            return;
        }

        $domains->validate($this->website, $domain, array_values(array_diff($aliases, [$domain])));

        $httpPort = $this->http_port !== '' ? (int) $this->http_port : null;

        if ($domain === null && $httpPort === null) {
            $httpPort = Website::nextPort('http_port', (int) config('laravelify.http_port_start'));
        }

        $previousTunnel = $this->website->tunnel;
        $previousDomains = $this->website->hostnames();

        $this->website->update([
            'name' => $this->name,
            'http_port' => $httpPort,
            'repository' => $this->repository !== '' ? $this->repository : null,
            'branch' => $this->branch,
            'cloudflare_tunnel_id' => $this->cloudflare_tunnel_id !== '' ? (int) $this->cloudflare_tunnel_id : null,
            'github_app_id' => $this->github_app_id !== '' ? (int) $this->github_app_id : null,
        ]);

        $this->website->refresh();

        // A site that moved to another tunnel (or none) leaves its routes on the old one.
        $left = ['configured' => false, 'lines' => [], 'note' => null];

        if ($previousTunnel && $previousTunnel->id !== $this->website->cloudflare_tunnel_id) {
            $renderer->refreshTunnel($previousTunnel);
            $left = $cloudflare->forget($previousTunnel, $previousDomains);
        }

        $report = $domains->apply($this->website, $domain, array_values(array_diff($aliases, [$domain])));
        $problems = [...CloudflareRoutes::problems($left), ...CloudflareRoutes::problems($report)];
        $this->fillFromWebsite();

        $problems === []
            ? $this->toast($report['lines'] !== [] ? 'General settings saved. Domains are set in nginx, the tunnel, and Cloudflare DNS.' : 'General settings saved.')
            : $this->toast('General settings saved, but Cloudflare needs attention: '.implode(' ', $problems), 'warning');
        $this->saved();
    }

    public function saveRuntime(): void
    {
        $website = $this->website;
        $command = ['nullable', 'string', 'max:500', 'regex:/^[^\r\n\0]*$/'];
        $folder = ['nullable', 'string', 'max:190', function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== null && $value !== '' && ! BuildOutput::safe(trim((string) $value, '/'))) {
                $fail('Use a folder inside the repository, like dist or public (no spaces or ..).');
            }
        }];

        $rules = match (true) {
            $website->isPhp() => [
                'php_version' => ['required', Rule::in(config('laravelify.php_versions'))],
                'enable_reverb' => ['boolean'],
                'public_dir' => $folder,
            ],
            $website->isNode() => [
                'node_version' => ['required', Rule::in(config('laravelify.node_versions'))],
                'port' => ['required', 'integer', 'min:1024', 'max:65535', Rule::unique('websites', 'port')->ignore($website->id)],
                'install_command' => $command,
                'build_command' => $command,
                'start_command' => $command,
            ],
            default => [
                'node_version' => $website->usesNode() ? ['required', Rule::in(config('laravelify.node_versions'))] : ['nullable'],
                'install_command' => $command,
                'build_command' => $command,
                'public_dir' => $folder,
            ],
        };

        $this->validate($rules);
        $blank = fn (string $value): ?string => trim($value) !== '' ? trim($value) : null;

        if ($website->isLaravel()) {
            $reverbPort = $website->reverb_port;

            if ($this->enable_reverb && ! $reverbPort) {
                $reverbPort = Website::nextPort('reverb_port', (int) config('laravelify.reverb_port_start'));
            }

            $website->update([
                'php_version' => $this->php_version,
                'enable_reverb' => $this->enable_reverb,
                'reverb_port' => $this->enable_reverb ? $reverbPort : null,
            ]);
        } elseif ($website->isPhp()) {
            $website->update([
                'php_version' => $this->php_version,
                'public_dir' => $blank(trim($this->public_dir, '/')) ?? 'public',
            ]);
        } elseif ($website->isNode()) {
            $website->update([
                'node_version' => $this->node_version,
                'port' => $this->port,
                'install_command' => $blank($this->install_command),
                'build_command' => $blank($this->build_command),
                'start_command' => $website->isNextjs() ? null : $blank($this->start_command),
            ]);
        } else {
            $website->update([
                'node_version' => $website->usesNode() ? $this->node_version : null,
                'install_command' => $website->usesNode() ? $blank($this->install_command) : null,
                'build_command' => $website->usesNode() ? $blank($this->build_command) : null,
                'public_dir' => $blank(trim($this->public_dir, '/')),
            ]);
        }

        $website->refresh();
        $this->toast($website->hasProcessFile()
            ? 'Runtime saved. Regenerate nginx and the process file to apply it, then deploy.'
            : 'Runtime saved. Regenerate nginx to apply it, then deploy.');
        $this->saved();
    }

    public function saveEnvironment(HostWriter $hostWriter): void
    {
        $this->validate([
            'env_content' => ['nullable', 'string'],
        ]);

        $this->website->update(['env_content' => $this->env_content]);

        try {
            $hostWriter->writeWebsite($this->website);
            $this->toast($this->website->isPhp() || $this->website->isLaravel()
                ? 'Environment saved.'
                : 'Environment saved. Values that the build reads (NEXT_PUBLIC_, VITE_, and similar) are built into the app, so deploy to apply them.');
        } catch (Throwable $exception) {
            $this->addError('env_content', 'Environment saved. Applying it on the server failed: '.$exception->getMessage());
            $this->toast('Environment saved. Applying it on the server failed: '.$exception->getMessage(), 'error');
        }

        $this->saved();
    }

    public function addRedirect(string $kind = 'domain'): void
    {
        $this->redirect_rows[] = ['from' => $kind === 'path' ? '/' : '', 'to' => '', 'code' => 301, 'keep_path' => true, 'match' => 'exact', 'via' => 'tunnel'];
    }

    public function removeRedirect(int $index): void
    {
        unset($this->redirect_rows[$index]);
        $this->redirect_rows = array_values($this->redirect_rows);
    }

    public function saveRedirects(WebsiteRedirects $redirects): void
    {
        $this->resetErrorBag();

        try {
            $report = $redirects->apply($this->website, $this->redirect_rows);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }

            $this->toast(collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->website->refresh();
        $this->fillFromWebsite();
        $problems = CloudflareRoutes::problems($report);

        $problems === []
            ? $this->toast('Redirects saved and applied.')
            : $this->toast('Redirects saved, but Cloudflare needs attention: '.implode(' ', $problems), 'warning');
        $this->saved();
    }

    public function saveNginx(HostWriter $hostWriter): void
    {
        $this->validate(['nginx_config' => ['required', 'string']]);
        $this->website->update(['nginx_config' => $this->nginx_config]);

        try {
            $hostWriter->writeWebsite($this->website);
            $this->toast('Nginx saved and applied.');
            $this->saved();
        } catch (Throwable $exception) {
            $this->addError('nginx_config', $exception->getMessage());
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function saveProcess(HostWriter $hostWriter): void
    {
        $this->validate(['process_config' => ['required', 'string']]);
        $this->website->update(['process_config' => $this->process_config]);
        $typed = $this->process_config;

        try {
            $hostWriter->writeWebsite($this->website);
            $this->toast(match (true) {
                ! $this->website->isLaravel() => 'PM2 file saved and applied.',
                trim((string) $this->website->process_config) !== trim($typed) => 'Supervisor file saved and applied. Program names now start with the site name, and each runs as the site user with its log in the site log folder.',
                default => 'Supervisor file saved and applied.',
            });
            $this->saved();
        } catch (Throwable $exception) {
            $this->addError('process_config', $exception->getMessage());
            $this->toast($exception->getMessage(), 'error');
        }

        // What was applied: program names, user, and log paths may have been brought up to date.
        $this->process_config = (string) $this->website->fresh()?->process_config;
    }

    public function regenerate(ConfigRenderer $renderer, HostWriter $hostWriter): void
    {
        $this->website->nginx_config = $renderer->withRedirects($this->website, $renderer->nginx($this->website));
        $this->website->process_config = $renderer->process($this->website);
        $this->website->save();
        $this->nginx_config = $this->website->nginx_config;
        $this->process_config = $this->website->process_config;

        try {
            $hostWriter->writeWebsite($this->website);
            $this->toast('Nginx and process files were regenerated from the template.');
            $this->saved();
        } catch (Throwable $exception) {
            $this->addError('nginx_config', $exception->getMessage());
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function provisionDatabase(DatabaseProvisioner $databases): void
    {
        if (! $this->website->isPhp() && ! $this->website->isNode()) {
            return;
        }

        if (! $this->website->db_name) {
            $this->website->fill($databases->credentials($this->website->slug));
            $this->website->provision_database = true;
            // PHP gets its usual template. A Node app already has its own .env, so only the
            // database variables are added (DATABASE_URL for Prisma and the other drivers).
            $this->website->env_content = $this->website->isNode()
                ? EnvFile::set((string) $this->website->env_content, $databases->envValues($this->website))
                : app(ConfigRenderer::class)->env($this->website);
            $this->website->save();
            $this->env_content = (string) $this->website->env_content;
        }

        try {
            $this->toast($databases->create($this->website));
        } catch (Throwable $exception) {
            $this->addError('database', $exception->getMessage());
            $this->toast($exception->getMessage(), 'error');
        }
    }

    /**
     * Search-engine blocking and basic auth, applied to nginx straight away.
     */
    public function saveSecurity(HostWriter $hostWriter, SiteSecurity $security): void
    {
        $needsPassword = $this->basic_auth_enabled && blank($this->website->basic_auth_hash);

        $this->validate([
            'basic_auth_user' => [$this->basic_auth_enabled ? 'required' : 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'basic_auth_password' => [$needsPassword ? 'required' : 'nullable', 'string', 'min:8', 'max:128', 'regex:/^[^\r\n]+$/'],
            'basic_auth_except' => ['nullable', 'string', 'max:2000'],
        ], [
            'basic_auth_user.regex' => 'Use letters, numbers, dots, dashes, underscores, or @.',
            'basic_auth_password.required' => 'Set a password to turn basic auth on.',
        ]);

        $invalid = collect(preg_split('/\R/', $this->basic_auth_except) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '' && ! in_array($line, SiteSecurity::parseExceptions($line), true));

        if ($invalid->isNotEmpty()) {
            $this->addError('basic_auth_except', 'Each line must be a path starting with / using letters, numbers, / _ . - only. Check: '.$invalid->first());

            return;
        }

        $badIp = collect(preg_split('/[\s,]+/', $this->blocked_ips) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->first(fn (string $line): bool => $line !== '' && (! SiteSecurity::validIp($line) || in_array($line, ['127.0.0.1', '::1'], true)));

        if ($badIp !== null) {
            $this->addError('blocked_ips', 'Not a valid IP or range (or it is this server): '.$badIp);

            return;
        }

        $this->website->fill([
            'hide_from_search' => $this->hide_from_search,
            'block_crawlers' => $this->block_crawlers,
            'basic_auth_enabled' => $this->basic_auth_enabled,
            'basic_auth_user' => $this->basic_auth_user !== '' ? $this->basic_auth_user : null,
            'basic_auth_except' => implode("\n", SiteSecurity::parseExceptions($this->basic_auth_except)) ?: null,
            'blocked_ips' => implode("\n", SiteSecurity::parseIps($this->blocked_ips)) ?: null,
        ]);

        if ($this->basic_auth_password !== '') {
            $this->website->basic_auth_hash = $security->hash($this->basic_auth_password);
            $this->website->basic_auth_password = $this->basic_auth_password;
        }

        if ($security->active($this->website) && ! $security->hasInclude($this->website) && ! $security->ensureInclude($this->website)) {
            $this->addError('security', 'Could not find a server_name line in the Nginx tab to attach the rules to. Add this line inside each server block: '.trim($security->includeLine($this->website)));

            return;
        }

        $this->website->save();
        $this->basic_auth_password = '';
        $this->nginx_config = (string) $this->website->nginx_config;

        $this->blocked_ips = (string) $this->website->blocked_ips;

        try {
            $hostWriter->writeNginx($this->website);
            $this->toast('Security settings applied.');
        } catch (Throwable $exception) {
            $this->addError('security', 'Saved, but applying nginx failed: '.$exception->getMessage());
            $this->toast('Saved, but applying nginx failed.', 'error');
        }

        $this->saved();
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

    /**
     * Keeps the Security tab in step when an IP is blocked from the Monitoring tab.
     */
    #[On('blocked-ips-changed')]
    public function syncBlockedIps(string $ips): void
    {
        $this->blocked_ips = $ips;
    }

    /**
     * Saved secrets of this website, for the eye buttons (after the authenticator code).
     */
    protected function revealableSecret(string $key): ?string
    {
        return match ($key) {
            'basic_auth_password' => $this->website->basic_auth_password,
            'webhook_secret' => $this->website->webhook_secret,
            'db_password' => $this->website->db_password,
            default => null,
        };
    }

    /**
     * Open the site at <panel>/p/<token>/, or give the preview a new address.
     */
    public function enablePreview(PanelPreview $preview, bool $newAddress = false, ?string $mode = null): void
    {
        try {
            $url = $preview->enable($this->website, $newAddress, in_array($mode, ['subdomain', 'path'], true) ? $mode : null);
            $this->toast('Preview ready at '.$url.($preview->mode($this->website) === 'subdomain' ? ' A new DNS name can take a minute to answer.' : ''));
        } catch (Throwable $exception) {
            $this->toast('Could not turn on the preview: '.$exception->getMessage(), 'error');
        }
    }

    public function disablePreview(PanelPreview $preview): void
    {
        try {
            $preview->disable($this->website);
            $this->toast('Preview turned off.');
        } catch (Throwable $exception) {
            $this->toast('Could not turn off the preview: '.$exception->getMessage(), 'error');
        }
    }

    public function toggleAutoDeploy(): void
    {
        $this->website->update(['auto_deploy' => ! $this->website->auto_deploy]);
        $this->toast($this->website->auto_deploy ? 'Pushes to '.$this->website->branch.' now deploy automatically.' : 'Auto deploy is off. Pushes are recorded but not deployed.');
    }

    public function deploy(): void
    {
        if (! $this->website->enabled) {
            $this->toast('This website is turned off. Turn it on in the Danger zone to deploy.', 'error');

            return;
        }

        $deployment = $this->website->queueDeployment('manual');

        if (! $deployment) {
            $this->addError('deploy', 'A deployment is already running.');
            $this->toast('A deployment is already running.', 'error');

            return;
        }

        $this->deploymentId = $deployment->id;
        $this->toast('Deployment queued.');
    }

    public function stopDeploy(): void
    {
        $active = $this->website->deployments()->whereIn('status', ['queued', 'running'])->latest()->first();

        if (! $active) {
            return;
        }

        $active->update([
            'status' => 'cancelled',
            'log' => trim($active->log."\nDeploy stopped."),
            'finished_at' => $active->status === 'queued' ? now() : $active->finished_at,
        ]);
        $this->deploymentId = $active->id;
    }

    public function toggleDeploymentSelection(): void
    {
        $ids = $this->website->deployments()->limit(20)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->selectedDeployments = count($this->selectedDeployments) === count($ids) ? [] : $ids;
    }

    public function deleteDeployments(): void
    {
        if (! $this->confirmed('delete')) {
            return;
        }

        $ids = array_map(intval(...), $this->selectedDeployments);
        $active = $this->website->deployments()->whereIn('id', $ids)->whereIn('status', ['queued', 'running'])->exists();

        if ($active) {
            $this->addError('deployments', 'Stop the running deployment before deleting it.');
            $this->toast('Stop the running deployment before deleting it.', 'error');

            return;
        }

        $this->website->deployments()->whereIn('id', $ids)->delete();
        $this->selectedDeployments = [];

        if ($this->deploymentId && ! $this->website->deployments()->whereKey($this->deploymentId)->exists()) {
            $this->deploymentId = $this->website->deployments()->value('id');
        }
    }

    public function setEnabled(WebsiteSwitch $switch, bool $enabled): void
    {
        try {
            $enabled ? $switch->enable($this->website) : $switch->disable($this->website);
            $this->website->refresh();
            $this->toast($enabled ? $this->website->name.' is on again.' : $this->website->name.' is turned off.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function destroy(PanelTaskRunner $tasks): void
    {
        if (! $this->confirmed($this->website->name)) {
            return;
        }

        $group = $this->website->group;
        $name = $this->website->name;

        // The queue removes nginx, PM2, and the tunnel. Doing that in this request drops the
        // panel's own Cloudflare connection and the browser shows a 502.
        $tasks->start('websites.delete', 'websites', 'Deleting '.$name, [
            'website_ids' => [$this->website->id],
        ]);

        $this->toastAfterRedirect($name.' is being deleted. Its folder in /var/www is kept.');
        $this->redirect(route('groups.show', $group), navigate: true);
    }

    public function render()
    {
        return view('livewire.websites.show', [
            'phpVersions' => config('laravelify.php_versions'),
            'nodeVersions' => config('laravelify.node_versions'),
            'tunnels' => CloudflareTunnel::query()->orderBy('name')->get(),
            'sources' => GithubApp::query()->orderBy('name')->get(),
            'deployments' => $deployments = $this->website->deployments()->limit(20)->get(),
            'deploying' => $deployments->contains(fn ($deployment) => in_array($deployment->status, ['queued', 'running'], true)),
            'selectedDeployment' => $this->deploymentId
                ? Deployment::query()->find($this->deploymentId)
                : $this->website->deployments()->first(),
            'webhookUrl' => url('/hooks/github/'.$this->website->slug),
            'deployApp' => $this->website->githubApp,
        ])->layout('layouts.app')->title($this->website->name);
    }

    private function saved(): void
    {
        $this->dispatch('saved');
    }

    private function fillFromWebsite(): void
    {
        $this->name = $this->website->name;
        $this->domain = $this->website->domain ?? '';
        $this->aliases_text = implode('
', (array) ($this->website->aliases ?? []));
        $this->redirect_rows = Redirects::normalize((array) ($this->website->redirects ?? []));
        $this->http_port = $this->website->http_port ? (string) $this->website->http_port : '';
        $this->repository = $this->website->repository ?? '';
        $this->branch = $this->website->branch;
        $this->cloudflare_tunnel_id = (string) ($this->website->cloudflare_tunnel_id ?? '');
        $this->github_app_id = (string) ($this->website->github_app_id ?? '');
        $this->php_version = $this->website->php_version ?: '8.4';
        $this->node_version = $this->website->node_version ?: '22';
        $this->install_command = (string) ($this->website->install_command ?? '');
        $this->build_command = (string) ($this->website->build_command ?? '');
        $this->start_command = (string) ($this->website->start_command ?? '');
        $this->public_dir = (string) ($this->website->public_dir ?? '');
        $this->port = $this->website->port;
        $this->enable_reverb = $this->website->enable_reverb;
        $this->env_content = $this->website->env_content ?? '';
        $this->nginx_config = $this->website->nginx_config ?? '';
        $this->process_config = $this->website->process_config ?? '';
        $this->hide_from_search = (bool) $this->website->hide_from_search;
        $this->block_crawlers = (bool) $this->website->block_crawlers;
        $this->basic_auth_enabled = (bool) $this->website->basic_auth_enabled;
        $this->basic_auth_user = (string) ($this->website->basic_auth_user ?? '');
        $this->basic_auth_except = (string) ($this->website->basic_auth_except ?? '');
        $this->blocked_ips = (string) ($this->website->blocked_ips ?? '');
        $this->basic_auth_password = '';
    }
}
