<?php

namespace App\Models;

use Database\Factories\BackupStorageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An S3-compatible bucket backups are sent to: AWS S3, DigitalOcean Spaces, Cloudflare R2,
 * Backblaze B2, Wasabi, MinIO. The keys are encrypted at rest.
 */
class BackupStorage extends Model
{
    /** @use HasFactory<BackupStorageFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'endpoint',
        'region',
        'bucket',
        'path',
        'access_key',
        'secret_key',
        'is_default',
        'checked_at',
        'last_error',
    ];

    protected $hidden = ['access_key', 'secret_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_key' => 'encrypted',
            'secret_key' => 'encrypted',
            'is_default' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(BackupSchedule::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    /**
     * The folder inside the bucket, without slashes at the ends.
     */
    public function prefix(): string
    {
        return trim((string) $this->path, '/');
    }

    public function usable(): bool
    {
        return $this->checked_at !== null && blank($this->last_error);
    }
}
