<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GithubApp extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'organization',
        'html_url',
        'api_url',
        'git_user',
        'git_port',
        'app_id',
        'installation_id',
        'client_id',
        'client_secret',
        'webhook_secret',
        'private_key',
        'private_key_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'git_port' => 'integer',
            'client_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'private_key' => 'encrypted',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<PrivateKey, $this>
     */
    public function privateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function signingKey(): ?string
    {
        $this->loadMissing('privateKey');
        $stored = $this->privateKey?->private_key;

        if (filled($stored)) {
            return $stored;
        }

        return filled($this->private_key) ? $this->private_key : null;
    }

    public function isConnected(): bool
    {
        return filled($this->app_id) && filled($this->installation_id) && filled($this->signingKey());
    }

    /**
     * @return HasMany<Website, $this>
     */
    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }
}
