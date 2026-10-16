<?php

namespace App\Models;

use App\Jobs\RunDeployment;
use App\Services\PortAllocator;
use App\Support\ServerAddress;
use App\Support\SiteTypes;
use Database\Factories\WebsiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Website extends Model
{
    /** @use HasFactory<WebsiteFactory> */
    use HasFactory;

    protected $fillable = [
        'group_id',
        'cloudflare_tunnel_id',
        'github_app_id',
        'name',
        'enabled',
        'slug',
        'type',
        'domain',
        'aliases',
        'redirects',
        'repository',
        'branch',
        'php_version',
        'node_version',
        'install_command',
        'build_command',
        'start_command',
        'public_dir',
        'port',
        'http_port',
        'reverb_port',
        'enable_reverb',
        'provision_database',
        'db_name',
        'db_username',
        'db_password',
        'deploy_path',
        'env_content',
        'nginx_config',
        'process_config',
        'webhook_secret',
        'auto_deploy',
        'last_webhook_at',
        'last_webhook_result',
        'hide_from_search',
        'block_crawlers',
        'basic_auth_enabled',
        'basic_auth_user',
        'basic_auth_hash',
        'basic_auth_password',
        'basic_auth_except',
        'monitor_retention_days',
        'monitor_slow_ms',
        'monitor_error_threshold',
        'blocked_ips',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'basic_auth_hash',
        'basic_auth_password',
        'db_password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'aliases' => 'array',
            'redirects' => 'array',
            'enable_reverb' => 'boolean',
            'provision_database' => 'boolean',
            'db_password' => 'encrypted',
            'basic_auth_password' => 'encrypted',
            'port' => 'integer',
            'http_port' => 'integer',
            'reverb_port' => 'integer',
            'auto_deploy' => 'boolean',
            'last_webhook_at' => 'datetime',
            'hide_from_search' => 'boolean',
            'block_crawlers' => 'boolean',
            'basic_auth_enabled' => 'boolean',
            'monitor_retention_days' => 'integer',
            'monitor_slow_ms' => 'integer',
            'monitor_error_threshold' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isLaravel(): bool
    {
        return $this->type === 'laravel';
    }

    public function isNextjs(): bool
    {
        return $this->type === 'nextjs';
    }

    /**
     * The framework's definition (label, group, defaults) from the type catalog.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return SiteTypes::get($this->type);
    }

    public function typeLabel(): string
    {
        return SiteTypes::label($this->type);
    }

    /**
     * php (nginx → PHP-FPM), node (a server under PM2), or static (nginx serves files).
     */
    public function runtime(): string
    {
        return SiteTypes::runtime($this->type);
    }

    public function isPhp(): bool
    {
        return $this->runtime() === SiteTypes::RUNTIME_PHP;
    }

    public function isNode(): bool
    {
        return $this->runtime() === SiteTypes::RUNTIME_NODE;
    }

    public function isStatic(): bool
    {
        return $this->runtime() === SiteTypes::RUNTIME_STATIC;
    }

    /**
     * Whether the site needs Node.js to build or run (everything except PHP and plain HTML).
     */
    public function usesNode(): bool
    {
        return $this->isNode() || ($this->isStatic() && $this->type !== 'static');
    }

    /**
     * Only Laravel has Supervisor workers; Node sites have a PM2 file; the rest have none.
     */
    public function hasProcessFile(): bool
    {
        return $this->isLaravel() || $this->isNode();
    }

    public function installCommand(): ?string
    {
        return filled($this->install_command) ? (string) $this->install_command : null;
    }

    /**
     * The build command: the one saved on the site, else the type's default.
     */
    public function buildCommand(): ?string
    {
        return filled($this->build_command) ? (string) $this->build_command : ($this->definition()['build'] ?? null);
    }

    /**
     * The command PM2 runs: the one saved on the site, else the type's default.
     */
    public function startCommand(): ?string
    {
        return filled($this->start_command) ? (string) $this->start_command : ($this->definition()['start'] ?? null);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<CloudflareTunnel, $this>
     */
    public function tunnel(): BelongsTo
    {
        return $this->belongsTo(CloudflareTunnel::class, 'cloudflare_tunnel_id');
    }

    /**
     * @return BelongsTo<GithubApp, $this>
     */
    public function githubApp(): BelongsTo
    {
        return $this->belongsTo(GithubApp::class);
    }

    /**
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class)->latest();
    }

    /**
     * @return HasMany<SiteRequest, $this>
     */
    public function siteRequests(): HasMany
    {
        return $this->hasMany(SiteRequest::class);
    }

    /**
     * @return HasMany<SiteIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(SiteIssue::class);
    }

    /**
     * @return HasMany<UptimeCheck, $this>
     */
    public function uptimeChecks(): HasMany
    {
        return $this->hasMany(UptimeCheck::class);
    }

    /**
     * @return HasMany<Heartbeat, $this>
     */
    public function heartbeats(): HasMany
    {
        return $this->hasMany(Heartbeat::class);
    }

    public function hasActiveDeployment(): bool
    {
        return $this->deployments()->whereIn('status', ['queued', 'running'])->exists();
    }

    /**
     * @param  array{commit_message?: ?string, commit_author?: ?string}  $commit
     */
    public function queueDeployment(string $trigger, ?string $commitSha = null, array $commit = []): ?Deployment
    {
        // A turned-off site keeps its code as it is until it is turned on again.
        if (! $this->enabled || $this->hasActiveDeployment()) {
            return null;
        }

        $deployment = $this->deployments()->create([
            'status' => 'queued',
            'trigger' => $trigger,
            'commit_sha' => $commitSha,
            'branch' => $this->branch,
            'commit_message' => isset($commit['commit_message']) ? mb_substr((string) $commit['commit_message'], 0, 2000) : null,
            'commit_author' => isset($commit['commit_author']) ? mb_substr((string) $commit['commit_author'], 0, 190) : null,
            'log' => '',
        ]);

        RunDeployment::dispatch($deployment->id);

        return $deployment;
    }

    public function recordWebhook(string $result): void
    {
        $this->forceFill([
            'last_webhook_at' => now(),
            'last_webhook_result' => mb_substr($result, 0, 190),
        ])->save();
    }

    /**
     * Every domain that opens this website: the main one first, then the extra ones.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        return array_values(array_unique(array_filter([$this->domain, ...(array) ($this->aliases ?? [])])));
    }

    /**
     * Domains that only redirect somewhere else (apply.coupons → supercell.apply.coupons).
     *
     * @return list<string>
     */
    public function redirectHosts(?string $via = null): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($rule): ?string => is_array($rule) && isset($rule['from']) && ! str_starts_with((string) $rule['from'], '/') && ($via === null || ($rule['via'] ?? 'tunnel') === $via) ? (string) $rule['from'] : null,
            (array) ($this->redirects ?? []),
        ))));
    }

    /**
     * Every hostname that belongs to this website, whichever way it reaches the server: no other website may take one.
     *
     * @return list<string>
     */
    public function claimedHostnames(): array
    {
        return array_values(array_unique([...$this->domains(), ...$this->redirectHosts()]));
    }

    /**
     * Every hostname that arrives through the Cloudflare tunnel: its domains and the domains that redirect
     * through the tunnel. These need a tunnel route and a DNS record. (Redirects served on the server's
     * own IP, port 80, are not here.)
     *
     * @return list<string>
     */
    public function hostnames(): array
    {
        return array_values(array_unique([...$this->domains(), ...$this->redirectHosts('tunnel')]));
    }

    /**
     * The address the server itself checks the site on: a port site on 127.0.0.1 (a provider's
     * firewall may block its public IP from inside), anything else on its public address.
     */
    public function checkUrl(): ?string
    {
        if ($this->http_port && ! $this->domain) {
            return 'http://127.0.0.1:'.$this->http_port;
        }

        return $this->publicUrl();
    }

    public function publicUrl(): ?string
    {
        if ($this->http_port) {
            $host = $this->domain ?: (ServerAddress::publicIp() ?? '127.0.0.1');

            return 'http://'.$host.':'.$this->http_port;
        }

        if ($this->domain) {
            return ($this->cloudflare_tunnel_id ? 'https' : 'http').'://'.$this->domain;
        }

        return null;
    }

    /**
     * A port for a new website that nothing else uses (other websites, services, or anything
     * listening on the server). The column is kept for the callers; every port field counts.
     */
    public static function nextPort(string $column, int $start): int
    {
        return app(PortAllocator::class)->next($start);
    }
}
