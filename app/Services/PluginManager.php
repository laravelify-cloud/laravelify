<?php

namespace App\Services;

use App\Models\CloudflareTunnel;
use App\Models\DatabaseConnection;
use App\Models\Plugin;
use App\Models\Website;
use App\Plugins\VaultPlugin;
use App\Support\EnvFile;
use App\Support\Plugins;
use RuntimeException;
use Throwable;

/**
 * Installs and runs the Plugins page's services with Docker (host-apply.sh plugin-*), and links
 * them to websites by writing their variables into the website's .env. Credentials never reach a
 * log or a task message: every text shown is passed through redact() first.
 */
class PluginManager
{
    public function __construct(private HostWriter $host, private PortAllocator $ports) {}

    /**
     * Install a plugin (Docker first when missing), start it, and for Vault initialize and
     * unseal it. Installing again keeps its ports and credentials.
     *
     * @param  (callable(int, string): void)|null  $report
     */
    public function install(string $key, ?callable $report = null): Plugin
    {
        $definition = Plugins::find($key);
        $report ??= fn (int $percent, string $step) => null;
        $plugin = Plugin::query()->firstOrNew(['key' => $key]);

        if (! $plugin->exists) {
            $ports = [];

            foreach ($definition->ports() as $name => $preferred) {
                $ports[$name] = $this->ports->next($preferred);
            }

            $plugin->fill(['ports' => $ports, 'credentials' => $definition->credentials()]);
        }

        $plugin->fill(['status' => Plugin::INSTALLING, 'last_error' => null])->save();

        try {
            $report(10, 'Checking Docker…');
            $this->host->dockerInstall(fn (string $step) => $report(20, $step));

            $report(40, 'Starting '.$definition->label().'…');
            $this->host->pluginUp($key, $definition->compose($plugin->ports), $definition->containerEnv($plugin->credentials ?? []), fn (string $step) => $report(60, $step));

            if ($definition->sealable() && empty($plugin->credentials['root_token'])) {
                $report(80, 'Initializing Vault…');
                $plugin->credentials = [...($plugin->credentials ?? []), ...$this->host->vaultInit($key)];
                $plugin->credentials_shown_at = null;
                $plugin->save();
            }

            if ($definition->sealable()) {
                $report(90, 'Unsealing Vault…');
                $this->unseal($plugin);
            }

            $plugin->forceFill(['status' => Plugin::RUNNING])->save();

            // The Database page opens the plugin's databases with the panel's pdo_pgsql.
            if ($key === 'postgres' && ! extension_loaded('pdo_pgsql')) {
                $report(95, 'Turning on pdo_pgsql for the panel…');

                try {
                    $this->host->phpExtension(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, 'pgsql', true, 'pgsql');
                } catch (Throwable) {
                    // Websites still reach the plugin; only the Database page needs it.
                }
            }

            $this->syncConnection($plugin);
        } catch (Throwable $exception) {
            $plugin->forceFill(['status' => Plugin::ERROR, 'last_error' => $this->redact($plugin, $exception->getMessage())])->save();

            throw new RuntimeException($definition->label().' could not be installed: '.$this->redact($plugin, $exception->getMessage()));
        }

        return $plugin;
    }

    /** start, stop, or restart. */
    public function control(Plugin $plugin, string $action): void
    {
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new RuntimeException('Unknown plugin action.');
        }

        try {
            $this->host->pluginControl($plugin->key, $action);
        } catch (Throwable $exception) {
            $plugin->forceFill(['status' => Plugin::ERROR, 'last_error' => $this->redact($plugin, $exception->getMessage())])->save();

            throw new RuntimeException($this->redact($plugin, $exception->getMessage()));
        }

        $plugin->forceFill(['status' => $action === 'stop' ? Plugin::STOPPED : Plugin::RUNNING, 'last_error' => null])->save();

        // Vault comes back sealed after every start.
        if ($action !== 'stop' && $plugin->definition()->sealable()) {
            $plugin->auto_unseal ? $this->unseal($plugin) : $plugin->forceFill(['sealed' => true])->save();
        }
    }

    /** Remove the containers (and with $purge, the data); linked websites lose the variables. */
    /**
     * Open the plugin's web page (MinIO's console, Vault's UI) on a domain behind basic auth:
     * nginx on this server, and the tunnel's route and DNS when a tunnel is picked.
     *
     * @return array{configured: bool, lines: list<array{host: string, route: string, dns: string, ok: bool}>, note: ?string}
     */
    public function setDomain(Plugin $plugin, string $domain, ?CloudflareTunnel $tunnel, string $user, string $password): array
    {
        $port = $plugin->webPort() ?: throw new RuntimeException($plugin->definition()->label().' has no web page to open on a domain.');
        $domain = strtolower(trim($domain));

        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,62}\.)+[a-z]{2,63}$/', $domain) !== 1) {
            throw new RuntimeException('Enter a domain like minio.example.com.');
        }

        if (Website::query()->get()->contains(fn (Website $website): bool => in_array($domain, $website->hostnames(), true))) {
            throw new RuntimeException($domain.' is already used by a website.');
        }

        if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $user) !== 1 || strlen($password) < 12) {
            throw new RuntimeException('Pick a user name (letters, digits, . _ -) and a password of at least 12 characters.');
        }

        $previous = $plugin->domain;
        $previousTunnel = $plugin->tunnel;
        $hash = app(SiteSecurity::class)->hash($password);
        $this->host->pluginDomain($plugin->key, $domain, $port, $user.':'.$hash."\n");
        $plugin->update(['domain' => $domain, 'cloudflare_tunnel_id' => $tunnel?->id, 'basic_auth_user' => $user, 'basic_auth_hash' => $hash]);

        if ($previousTunnel && $previous && ($previousTunnel->isNot($tunnel) || $previous !== $domain)) {
            app(CloudflareRoutes::class)->syncHost($previousTunnel, null, $previous);
        }

        return $tunnel ? app(CloudflareRoutes::class)->syncHost($tunnel, $domain, $previous) : ['configured' => true, 'lines' => [], 'note' => null];
    }

    public function removeDomain(Plugin $plugin): void
    {
        if ($plugin->domain === null) {
            return;
        }

        $this->host->pluginDomainRemove($plugin->key);

        if ($plugin->tunnel) {
            app(CloudflareRoutes::class)->syncHost($plugin->tunnel, null, $plugin->domain);
        }

        $plugin->update(['domain' => null, 'cloudflare_tunnel_id' => null, 'basic_auth_user' => null, 'basic_auth_hash' => null]);
    }

    public function uninstall(Plugin $plugin, bool $purge): void
    {
        try {
            $this->removeDomain($plugin);
        } catch (Throwable) {
            // plugin-remove takes its nginx files off too; a Cloudflare error does not stop the removal.
        }

        foreach ($plugin->websites as $website) {
            $this->unlink($plugin, $website);
        }

        $this->host->pluginRemove($plugin->key, $purge);
        // Its connection, and those of databases made on it from the Database page (plugin-<key>-<name>).
        DatabaseConnection::query()->where('slug', self::CONNECTION_PREFIX.$plugin->key)->orWhere('slug', 'like', self::CONNECTION_PREFIX.$plugin->key.'-%')->delete();
        $plugin->delete();
    }

    /** Saved connections of the Database page for the PostgreSQL plugin, slug plugin-<key>. */
    public const CONNECTION_PREFIX = 'plugin-';

    /**
     * Keep a Database page connection to a database plugin (PostgreSQL), so its databases show
     * there. The password is the plugin's, stored encrypted like any connection.
     */
    public function syncConnection(Plugin $plugin): void
    {
        $driver = ['postgres' => 'pgsql', 'mysql' => 'mysql'][$plugin->key] ?? null;

        if ($driver === null || empty($plugin->credentials['password'])) {
            return;
        }

        $values = [
            'name' => $plugin->definition()->label().' plugin',
            'driver' => $driver,
            'host' => '127.0.0.1',
            'port' => (int) ($plugin->ports[$plugin->key] ?? 0),
            'database' => (string) $plugin->credentials['database'],
            // MySQL's root login, so the Database page can make databases there too (PostgreSQL's user already can).
            'username' => $driver === 'mysql' ? 'root' : (string) $plugin->credentials['username'],
            'password' => (string) ($driver === 'mysql' ? ($plugin->credentials['root_password'] ?? $plugin->credentials['password']) : $plugin->credentials['password']),
            'ssl_mode' => 'disable',
        ];

        $connection = DatabaseConnection::query()->firstOrNew(['slug' => self::CONNECTION_PREFIX.$plugin->key]);

        if (! $connection->exists || $connection->password !== $values['password'] || $connection->port !== $values['port'] || $connection->username !== $values['username']) {
            $connection->fill($values)->save();
        }
    }

    /** Connections for database plugins installed before syncConnection existed. */
    public function syncConnections(): void
    {
        foreach (Plugin::query()->whereIn('key', ['postgres', 'mysql'])->where('status', Plugin::RUNNING)->get() as $plugin) {
            $this->syncConnection($plugin);
        }
    }

    /** Ask Docker (and Vault) how the plugin is doing and save it. */
    public function refresh(Plugin $plugin): Plugin
    {
        if ($plugin->status === Plugin::INSTALLING) {
            return $plugin;
        }

        try {
            $state = $this->host->pluginStatus($plugin->key);
        } catch (Throwable) {
            return $plugin;
        }

        if (str_starts_with($state, '[skipped]')) {
            return $plugin;
        }

        $status = match ($state) {
            'running' => Plugin::RUNNING,
            'exited', 'created', 'paused' => Plugin::STOPPED,
            default => Plugin::ERROR,
        };

        $plugin->status = $status;

        if ($status === Plugin::RUNNING && $plugin->definition()->sealable()) {
            $plugin->sealed = $this->host->vaultSealed($plugin->key) ?? $plugin->sealed;
        }

        $plugin->save();

        return $plugin;
    }

    /** Unseal Vault with the saved keys (the threshold of them). */
    public function unseal(Plugin $plugin): bool
    {
        $keys = array_values((array) ($plugin->credentials['unseal_keys'] ?? []));

        if ($keys === []) {
            throw new RuntimeException('No unseal keys are saved for '.$plugin->definition()->label().'.');
        }

        $this->host->vaultUnseal($plugin->key, array_slice($keys, 0, VaultPlugin::KEY_THRESHOLD));
        $sealed = $this->host->vaultSealed($plugin->key);
        $plugin->forceFill(['sealed' => $sealed ?? false])->save();

        return $sealed === false || $sealed === null;
    }

    /**
     * Give a website the plugin's variables in its .env and write it to the server.
     *
     * @return list<string> the variable names set
     */
    public function link(Plugin $plugin, Website $website): array
    {
        if (! $plugin->definition()->linkable()) {
            throw new RuntimeException($plugin->definition()->label().' gives websites no variables; open its page instead.');
        }

        $values = $plugin->definition()->websiteEnv($plugin->ports, $plugin->credentials ?? []);
        $website->update(['env_content' => EnvFile::set((string) $website->env_content, $values)]);
        $plugin->websites()->syncWithoutDetaching([$website->id => ['env_keys' => json_encode(array_keys($values))]]);
        $this->host->writeWebsite($website);

        return array_keys($values);
    }

    /** Take the plugin's variables out of a website's .env. */
    public function unlink(Plugin $plugin, Website $website): void
    {
        $link = $plugin->websites()->whereKey($website->id)->first();

        if ($link === null) {
            return;
        }

        $keys = json_decode((string) $link->pivot->env_keys, true) ?: [];
        $website->update(['env_content' => EnvFile::set((string) $website->env_content, array_fill_keys($keys, null))]);
        $plugin->websites()->detach($website->id);
        $this->host->writeWebsite($website);
    }

    public function logs(Plugin $plugin, int $lines = 200): string
    {
        return $this->redact($plugin, $this->host->pluginLogs($plugin->key, $lines));
    }

    /** Text with the plugin's secrets replaced, for logs, errors, and task messages. */
    public function redact(Plugin $plugin, string $text): string
    {
        $secrets = [];

        $credentials = (array) ($plugin->credentials ?? []);

        array_walk_recursive($credentials, function ($value) use (&$secrets): void {
            if (is_string($value) && strlen($value) >= 8) {
                $secrets[] = $value;
            }
        });

        return $secrets === [] ? $text : str_replace($secrets, '••••••', $text);
    }
}
