<?php

namespace App\Models;

use Database\Factories\CloudflareTunnelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CloudflareTunnel extends Model
{
    /** @use HasFactory<CloudflareTunnelFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'token',
        'ingress',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<Website, $this>
     */
    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }
}
