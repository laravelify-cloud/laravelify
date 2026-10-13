<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UptimeCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'website_id',
        'ok',
        'status',
        'duration_ms',
        'error',
        'checked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'status' => 'integer',
            'duration_ms' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
