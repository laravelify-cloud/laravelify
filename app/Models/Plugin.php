<?php

namespace App\Models;

use App\Plugins\PluginDefinition;
use App\Support\Plugins;
use Database\Factories\PluginFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A service installed from the Plugins page. Its definition (compose file, variables) is the
 * class in app/Plugins with the same key.
 *
 * @property array<string, int> $ports
 * @property array<string, mixed>|null $credentials
 */
class Plugin extends Model
{
    /** @use HasFactory<PluginFactory> */
    use HasFactory;

    public const INSTALLING = 'installing';

    public const RUNNING = 'running';

    public const STOPPED = 'stopped';

    public const ERROR = 'error';

    protected $fillable = [
        'key',
        'status',
        'ports',
        'credentials',
        'credentials_shown_at',
        'sealed',
        'auto_unseal',
        'last_error',
        'domain',
        'cloudflare_tunnel_id',
        'basic_auth_user',
        'basic_auth_hash',
        'api_domain',
        'api_cloudflare_tunnel_id',
    ];

    protected $hidden = ['credentials', 'basic_auth_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ports' => 'array',
            'credentials' => 'encrypted:array',
            'credentials_shown_at' => 'datetime',
            'sealed' => 'boolean',
            'auto_unseal' => 'boolean',
        ];
    }

    public function definition(): PluginDefinition
    {
        return Plugins::find($this->key);
    }

    public function websites(): BelongsToMany
    {
        return $this->belongsToMany(Website::class)->withPivot('env_keys')->withTimestamps();
    }

    /** The port a plugin's main service answers on. */
    public function mainPort(): int
    {
        return (int) (array_values($this->ports ?? [])[0] ?? 0);
    }

    public function tunnel(): BelongsTo
    {
        return $this->belongsTo(CloudflareTunnel::class, 'cloudflare_tunnel_id');
    }

    /** The tunnel of its public API domain. */
    public function apiTunnel(): BelongsTo
    {
        return $this->belongsTo(CloudflareTunnel::class, 'api_cloudflare_tunnel_id');
    }

    /** The port its web page answers on (MinIO's console, Vault's UI), or 0 when it has none. */
    public function webPort(): int
    {
        $name = $this->definition()->webPort();

        return $name === null ? 0 : (int) ($this->ports[$name] ?? 0);
    }

    /** The backup target name of this plugin, like "plugin:postgres". */
    public function backupTarget(): string
    {
        return 'plugin:'.$this->key;
    }
}
