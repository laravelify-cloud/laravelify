<?php

namespace App\Models;

use Database\Factories\BackupStorageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where backups are sent: an S3-compatible bucket (AWS S3, DigitalOcean Spaces, Cloudflare R2,
 * Backblaze B2, Wasabi, MinIO; the keys are encrypted at rest), or a folder on this server.
 */
class BackupStorage extends Model
{
    /** @use HasFactory<BackupStorageFactory> */
    use HasFactory;

    public const DRIVER_S3 = 's3';

    public const DRIVER_LOCAL = 'local';

    protected $fillable = [
        'name',
        'driver',
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

    /**
     * A folder on this server instead of a bucket. Its path is absolute, like /backups.
     */
    public function isLocal(): bool
    {
        return $this->driver === self::DRIVER_LOCAL;
    }

    /**
     * Where the backups go, for people: "/backups/laravelify" or "bucket/folder".
     */
    public function location(): string
    {
        if ($this->isLocal()) {
            return '/'.$this->prefix();
        }

        return $this->bucket.($this->prefix() !== '' ? '/'.$this->prefix() : '');
    }

    public function usable(): bool
    {
        return $this->checked_at !== null && blank($this->last_error);
    }
}
