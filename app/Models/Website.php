<?php

namespace App\Models;

use App\Jobs\RunDeployment;
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
        'slug',
        'type',
        'domain',
        'repository',
        'branch',
        'php_version',
        'node_version',
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
        if ($this->hasActiveDeployment()) {
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

    public function publicUrl(): ?string
    {
        if ($this->http_port) {
            $host = $this->domain ?: '127.0.0.1';

            return 'http://'.$host.':'.$this->http_port;
        }

        if ($this->domain) {
            return ($this->cloudflare_tunnel_id ? 'https' : 'http').'://'.$this->domain;
        }

        return null;
    }

    public static function nextPort(string $column, int $start): int
    {
        $used = static::query()->whereNotNull($column)->pluck($column)->map(fn ($port) => (int) $port)->all();
        $port = $start;

        while (in_array($port, $used, true)) {
            $port++;
        }

        return $port;
    }
}
