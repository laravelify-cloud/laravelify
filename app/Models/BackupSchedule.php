<?php

namespace App\Models;

use App\Support\BackupCron;
use App\Support\SkippedTables;
use Database\Factories\BackupScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupSchedule extends Model
{
    /** @use HasFactory<BackupScheduleFactory> */
    use HasFactory;

    public const KIND_DATABASE = 'database';

    public const KIND_FILES = 'files';

    protected $fillable = [
        'name',
        'kind',
        'backup_storage_id',
        'enabled',
        'frequency',
        'interval',
        'run_time',
        'weekday',
        'cron',
        'targets',
        'retention_count',
        'retention_days',
        'last_run_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'targets' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(BackupStorage::class, 'backup_storage_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    /**
     * Tables a database schedule leaves out: "database.table" or "*.table".
     *
     * @return list<string>
     */
    public function skippedTables(): array
    {
        return SkippedTables::normalize((array) (($this->targets ?? [])['skip_tables'] ?? []));
    }

    public function isDatabase(): bool
    {
        return $this->kind === self::KIND_DATABASE;
    }

    public function describeFrequency(): string
    {
        return BackupCron::describe($this->frequency, $this->interval, $this->run_time, $this->weekday, $this->cron);
    }

    /**
     * Short text for the list: "All databases", "app_db, shop_db", or the folders.
     */
    public function describeTargets(): string
    {
        $targets = $this->targets ?? [];

        if ($this->isDatabase()) {
            $skipped = count($this->skippedTables());

            return (! empty($targets['all']) ? 'All databases' : implode(', ', $targets['databases'] ?? []))
                .($skipped > 0 ? ' · skips '.$skipped.' '.($skipped === 1 ? 'table' : 'tables') : '');
        }

        if (! empty($targets['websites'])) {
            return 'Every website';
        }

        return implode(', ', $targets['paths'] ?? []);
    }
}
