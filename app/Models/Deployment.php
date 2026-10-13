<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    protected $fillable = [
        'website_id',
        'status',
        'trigger',
        'commit_sha',
        'branch',
        'commit_message',
        'commit_author',
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
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * How long the deploy took, like "1m 32s", once it has started.
     */
    public function durationLabel(): ?string
    {
        if (! $this->started_at) {
            return null;
        }

        $seconds = (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), true);

        return $seconds >= 60 ? intdiv($seconds, 60).'m '.($seconds % 60).'s' : $seconds.'s';
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
