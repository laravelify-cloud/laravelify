<?php

namespace App\Models;

use Database\Factories\BackupRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

class BackupRun extends Model
{
    /** @use HasFactory<BackupRunFactory> */
    use HasFactory;

    public const TYPE_BACKUP = 'backup';

    public const TYPE_RESTORE = 'restore';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    protected $fillable = [
        'backup_schedule_id',
        'backup_storage_id',
        'source_run_id',
        'type',
        'kind',
        'target',
        'status',
        'trigger',
        'object_key',
        'size_bytes',
        'meta',
        'log',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BackupSchedule::class, 'backup_schedule_id');
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(BackupStorage::class, 'backup_storage_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_run_id');
    }

    public function isBackup(): bool
    {
        return $this->type === self::TYPE_BACKUP;
    }

    public function isDatabase(): bool
    {
        return $this->kind === BackupSchedule::KIND_DATABASE;
    }

    /**
     * A finished backup whose file is still in the bucket, so it can be restored.
     */
    public function restorable(): bool
    {
        return $this->isBackup() && $this->status === self::SUCCESS && filled($this->object_key) && $this->storage !== null;
    }

    public function sizeLabel(): string
    {
        return $this->size_bytes === null ? '—' : Number::fileSize($this->size_bytes, precision: 1);
    }

    public function durationLabel(): ?string
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        $seconds = max(0, (int) $this->started_at->diffInSeconds($this->finished_at));

        return $seconds >= 60 ? intdiv($seconds, 60).'m '.($seconds % 60).'s' : $seconds.'s';
    }
}
