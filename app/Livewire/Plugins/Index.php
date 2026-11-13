<?php

namespace App\Livewire\Plugins;

use App\Livewire\PanelComponent;
use App\Models\CloudflareTunnel;
use App\Models\PanelTask;
use App\Models\Plugin;
use App\Models\Website;
use App\Plugins\VaultPlugin;
use App\Services\BackupManager;
use App\Services\Glitchtip;
use App\Services\PanelTaskRunner;
use App\Services\PluginManager;
use App\Support\Plugins;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * One-click services (PostgreSQL, Redis, MinIO, Vault…) run with Docker. Installing, starting,
 * stopping, and removing run as background tasks with their steps on the page.
 */
class Index extends PanelComponent
{
    /** The plugin whose new credentials are shown, once. */
    public ?string $credentialsFor = null;

    /** Whether the credentials are shown again (after an authenticator code), not new ones. */
    public bool $reviewing = false;

    /** The plugin whose logs are open. */
    public ?string $logsFor = null;

    public string $logs = '';

    /** @var array<string, string> The website picked to link, per plugin. */
    public array $linkWebsite = [];

    /** The plugin whose domain form is open. */
    public ?string $domainFor = null;

    public string $domain = '';

    /** page: its web page behind basic auth; api: its API on a public domain (MinIO's S3). */
    public string $domainKind = 'page';

    public string $domainTunnel = '';

    public string $domainUser = 'admin';

    public string $domainPassword = '';

    /** @var array<string, mixed> What Cloudflare said about the last save. */
    public array $domainReport = [];

    /** @var list<int> Finished tasks already announced. */
    public array $announced = [];

    public function install(string $key): void
    {
        $definition = Plugins::find($key);

        if (PanelTaskRunner::running('plugins.install', 'plugin:'.$key)) {
            return;
        }

        app(PanelTaskRunner::class)->start('plugins.install', 'plugin:'.$key, 'Installing '.$definition->label(), ['key' => $key]);
        $this->toast('Installing '.$definition->label().'. Follow it below.', 'info');
    }

    /**
     * GlitchTip is the panel's built-in error tracking (the same one each website's GlitchTip tab
     * turns on), not a Docker plugin: one GlitchTip per server, joined to every website.
     */
    public function installGlitchtip(): void
    {
        if (app(Glitchtip::class)->installed() || PanelTaskRunner::running('glitchtip.install', 'glitchtip')) {
            return;
        }

        app(PanelTaskRunner::class)->start('glitchtip.install', 'glitchtip', 'Turning on error tracking (GlitchTip)', ['user_id' => Auth::id()]);
        $this->toast('Installing GlitchTip for every website. It takes a few minutes.', 'info');
    }

    public function control(string $key, string $action): void
    {
        $plugin = Plugin::query()->where('key', $key)->firstOrFail();
        abort_unless(in_array($action, ['start', 'stop', 'restart'], true), 422);

        app(PanelTaskRunner::class)->start('plugins.control', 'plugin:'.$key, ucfirst($action === 'stop' ? 'stopping' : ($action === 'start' ? 'starting' : 'restarting')).' '.$plugin->definition()->label(), ['key' => $key, 'action' => $action]);
    }

    /** Remove a plugin; $purge also deletes its data volumes. */
    public function uninstall(string $key, bool $purge = false): void
    {
        $plugin = Plugin::query()->where('key', $key)->firstOrFail();

        if (! $this->confirmed($plugin->key)) {
            return;
        }

        app(PanelTaskRunner::class)->start('plugins.uninstall', 'plugin:'.$plugin->key, 'Removing '.$plugin->definition()->label(), ['key' => $plugin->key, 'purge' => $purge]);
    }

    public function unseal(string $key): void
    {
        $plugin = Plugin::query()->where('key', $key)->firstOrFail();

        try {
            $open = app(PluginManager::class)->unseal($plugin);
            $this->toast($open ? 'Vault is unsealed.' : 'Vault is still sealed. Check its logs.', $open ? 'success' : 'warning');
        } catch (Throwable $exception) {
            $this->toast(app(PluginManager::class)->redact($plugin, $exception->getMessage()), 'error');
        }
    }

    /** Back up a plugin's data now, to the default backup storage (listed under Backups). */
    public function backup(string $key): void
    {
        $plugin = Plugin::query()->where('key', $key)->firstOrFail();

        try {
            app(BackupManager::class)->backupDatabases([$plugin->backupTarget()]);
            $this->toast('Backing up '.$plugin->definition()->label().'. Follow it in Backups.', 'info');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    /** Open the domain form of a plugin. */
    public function editDomain(string $key, string $kind = 'page'): void
    {
        $plugin = Plugin::query()->where('key', $key)->firstOrFail();
        abort_unless(in_array($kind, ['page', 'api'], true), 422);
        $api = $kind === 'api';
        $this->domainFor = $key;
        $this->domainKind = $kind;
        $this->domain = (string) ($api ? $plugin->api_domain : $plugin->domain);
        $this->domainTunnel = (string) (($api ? $plugin->api_cloudflare_tunnel_id : $plugin->cloudflare_tunnel_id) ?? '');
        $this->domainUser = (string) ($plugin->basic_auth_user ?: 'admin');
        $this->domainPassword = '';
        $this->domainReport = [];
    }

    public function closeDomain(): void
    {
        $this->reset('domainFor', 'domainKind', 'domain', 'domainTunnel', 'domainUser', 'domainPassword', 'domainReport');
    }

    public function saveDomain(): void
    {
        $this->resetErrorBag('domain');
        $plugin = Plugin::query()->where('key', (string) $this->domainFor)->firstOrFail();
        $tunnel = $this->domainTunnel === '' ? null : CloudflareTunnel::query()->findOrFail((int) $this->domainTunnel);

        try {
            $report = $this->domainKind === 'api'
                ? app(PluginManager::class)->setApiDomain($plugin, $this->domain, $tunnel)
                : app(PluginManager::class)->setDomain($plugin, $this->domain, $tunnel, $this->domainUser, $this->domainPassword);
        } catch (Throwable $exception) {
            $this->addError('domain', $exception->getMessage());

            return;
        }

        $this->domainPassword = '';
        $this->domainReport = $report;
        $this->toast($plugin->definition()->label().($this->domainKind === 'api' ? "'s S3 API opens on ".$plugin->api_domain : ' opens on '.$plugin->domain).'.', 'success');

        if ($report['lines'] === [] && $report['note'] === null) {
            $this->closeDomain();
        }
    }

    public function removeDomain(string $key): void
    {
        $plugin = Plugin::query()->where('key', $key)->firstOrFail();

        try {
            $this->domainKind === 'api' ? app(PluginManager::class)->removeApiDomain($plugin) : app(PluginManager::class)->removeDomain($plugin);
            $this->toast('The domain is removed.', 'success');
            $this->closeDomain();
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function setAutoUnseal(string $key, bool $on): void
    {
        Plugin::query()->where('key', $key)->firstOrFail()->update(['auto_unseal' => $on]);
        $this->toast($on ? 'Vault unseals itself after restarts now.' : 'Vault stays sealed after restarts until you unseal it.');
    }

    public function showLogs(string $key): void
    {
        $plugin = Plugin::query()->where('key', $key)->firstOrFail();
        $this->logsFor = $key;

        try {
            $this->logs = app(PluginManager::class)->logs($plugin, 300) ?: 'No logs yet.';
        } catch (Throwable $exception) {
            $this->logs = app(PluginManager::class)->redact($plugin, $exception->getMessage());
        }
    }

    public function closeLogs(): void
    {
        $this->logsFor = null;
        $this->logs = '';
    }

    /** The credentials shown once, as a .txt file to keep in a password manager. */
    public function downloadCredentials(): ?StreamedResponse
    {
        $plugin = Plugin::query()->where('key', (string) $this->credentialsFor)
            ->when(! ($this->reviewing && self::secretsUnlocked()), fn ($query) => $query->whereNull('credentials_shown_at'))
            ->first();

        if (! $plugin) {
            return null;
        }

        $text = $this->credentialsText($plugin);

        return response()->streamDownload(function () use ($text): void {
            echo $text;
        }, 'laravelify-'.$plugin->key.'-credentials.txt', ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Every credential of a plugin in one text, for Copy all and the download. */
    private function credentialsText(Plugin $plugin): string
    {
        $definition = $plugin->definition();
        $lines = ['Laravelify: '.$definition->label().' credentials', 'Saved '.now()->toDateTimeString().' from '.request()->getHost(), ''];
        $keys = [];

        foreach ($definition->shownCredentials($plugin->ports, $plugin->credentials ?? []) as $label => $value) {
            if (str_starts_with($label, 'Unseal key')) {
                $keys[] = $value;

                continue;
            }

            $lines[] = $label.': '.$value;
        }

        if ($keys !== []) {
            $lines[] = '';
            $lines[] = 'Unseal keys (any '.VaultPlugin::KEY_THRESHOLD.' of these '.count($keys).' unseal Vault):';

            foreach ($keys as $index => $key) {
                $lines[] = '  '.($index + 1).'. '.$key;
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Show a plugin's credentials again. Only while saved secrets are unlocked with an
     * authenticator code (PanelComponent::unlockSecrets, the secret gate on the page).
     */
    public function showCredentials(string $key): void
    {
        $plugin = Plugin::query()->where('key', $key)->firstOrFail();

        if (! self::secretsUnlocked()) {
            $this->toast('Enter your authenticator code first.', 'error');

            return;
        }

        $this->credentialsFor = $plugin->key;
        $this->reviewing = true;
    }

    public function closeCredentials(): void
    {
        $this->credentialsFor = null;
        $this->reviewing = false;
    }

    public function savedCredentials(): void
    {
        Plugin::query()->where('key', (string) $this->credentialsFor)->update(['credentials_shown_at' => now()]);
        $this->credentialsFor = null;
    }

    public function link(string $key): void
    {
        $plugin = Plugin::query()->where('key', $key)->firstOrFail();
        $website = Website::query()->find((int) ($this->linkWebsite[$key] ?? 0));

        if ($website === null) {
            $this->toast('Choose a website to link.', 'error');

            return;
        }

        try {
            $keys = app(PluginManager::class)->link($plugin, $website);
            $this->toast($website->name.' has '.implode(', ', $keys).' now. Deploy it again to use them.');
        } catch (Throwable $exception) {
            $this->toast(app(PluginManager::class)->redact($plugin, $exception->getMessage()), 'error');
        }

        unset($this->linkWebsite[$key]);
    }

    public function unlink(string $key, int $websiteId): void
    {
        $plugin = Plugin::query()->where('key', $key)->firstOrFail();
        $website = Website::query()->findOrFail($websiteId);
        app(PluginManager::class)->unlink($plugin, $website);
        $this->toast($website->name.' no longer has '.$plugin->definition()->label().'\'s variables.');
    }

    public function pollTasks(): void
    {
        $finished = PanelTask::query()->where('scope', 'like', 'plugin:%')
            ->whereIn('status', [PanelTask::SUCCESS, PanelTask::FAILED])
            ->whereNotIn('id', $this->announced ?: [0])
            ->where('finished_at', '>=', now()->subMinutes(10))
            ->get();

        foreach ($finished as $task) {
            $this->announced[] = $task->id;
        }
    }

    public function dismissTask(int $id): void
    {
        $task = PanelTask::query()->where('scope', 'like', 'plugin:%')->find($id);

        if ($task && ! $task->isActive()) {
            $task->delete();
        }
    }

    public function render()
    {
        $tasks = PanelTask::query()->where('scope', 'like', 'plugin:%')->latest('id')->limit(6)->get();
        $active = $tasks->contains(fn (PanelTask $task) => $task->isActive());
        $installed = Plugin::query()->with('websites:id,name')->get()->keyBy('key');

        // Docker is asked only when nothing is running, so polling stays cheap.
        if (! $active) {
            foreach ($installed as $plugin) {
                app(PluginManager::class)->refresh($plugin);
            }
        }

        // Shown again only while unlocked: when the code's minutes are up, they are hidden.
        if ($this->reviewing && ! self::secretsUnlocked()) {
            $this->closeCredentials();
        }

        // New credentials are shown once, right after an install.
        if ($this->credentialsFor === null) {
            $this->credentialsFor = $installed->first(fn (Plugin $plugin) => $plugin->status === Plugin::RUNNING && $plugin->credentials_shown_at === null)?->key;
        }

        $shown = $this->credentialsFor ? $installed->get($this->credentialsFor) : null;

        return view('livewire.plugins.index', [
            'definitions' => Plugins::all(),
            'installed' => $installed,
            'tasks' => $tasks,
            'tasksActive' => $active,
            'busy' => $tasks->filter(fn (PanelTask $task) => $task->isActive())->map(fn (PanelTask $task) => substr($task->scope, 7))->values()->all(),
            'websites' => Website::query()->orderBy('name')->get(['id', 'name']),
            'tunnels' => CloudflareTunnel::query()->orderBy('name')->get(['id', 'name']),
            'shownCredentials' => $shown ? $shown->definition()->shownCredentials($shown->ports, $shown->credentials ?? []) : [],
            'credentialsText' => $shown ? $this->credentialsText($shown) : '',
            'glitchtip' => app(Glitchtip::class),
            'glitchtipBusy' => PanelTaskRunner::running('glitchtip.install', 'glitchtip'),
        ])->layout('layouts.app')->title('Plugins');
    }
}
