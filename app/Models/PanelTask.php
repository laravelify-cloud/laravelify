<?php

namespace App\Models;

use Database\Factories\PanelTaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

/**
 * Work that can take longer than a page request (zipping a folder, reading a backup, preparing
 * a download). The queue worker runs it, the page polls the status and the progress.
 *
 * Scopes group tasks by the page that shows them: "files:global", "files:website:5", "backups".
 */
class PanelTask extends Model
{
    /** @use HasFactory<PanelTaskFactory> */
    use HasFactory;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    protected $fillable = [
        'kind',
        'scope',
        'status',
        'label',
        'payload',
        'result',
        'progress',
        'message',
        'started_at',
        'finished_at',
    ];

    /** When the progress was last written, so a fast loop does not write on every file. */
    private float $reportedAt = 0;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<PanelTask>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [self::QUEUED, self::RUNNING]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::SUCCESS, self::FAILED], true);
    }

    /**
     * Waiting for the queue worker for a while: worth a hint on the page.
     */
    public function isStuck(): bool
    {
        return $this->status === self::QUEUED && $this->created_at !== null && $this->created_at->lt(now()->subSeconds(20));
    }

    /**
     * Save the progress (0 to 100) at most twice a second.
     */
    public function report(int $percent, ?string $message = null, bool $force = false): void
    {
        $now = microtime(true);

        if (! $force && $now - $this->reportedAt < 0.5) {
            return;
        }

        $this->reportedAt = $now;
        $this->forceFill(['progress' => max(0, min(100, $percent))] + ($message !== null ? ['message' => $message] : []))->save();
    }

    /**
     * Where tasks keep the files they produce, like a zip waiting to be downloaded.
     */
    public static function directory(): string
    {
        $directory = storage_path('app/panel-tasks');
        File::ensureDirectoryExists($directory);

        return $directory;
    }

    /**
     * The file this task produced, when it is still there.
     */
    public function resultFile(): ?string
    {
        $name = $this->result['file'] ?? null;

        if (! is_string($name) || $name === '' || basename($name) !== $name) {
            return null;
        }

        $path = self::directory().DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : null;
    }

    public function downloadName(): string
    {
        return (string) ($this->result['download_name'] ?? 'download');
    }
}
