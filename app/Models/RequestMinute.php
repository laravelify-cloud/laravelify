<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestMinute extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'website_id',
        'minute',
        'requests',
        'client_errors',
        'server_errors',
        'duration_sum',
        'duration_max',
        'histogram',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minute' => 'datetime',
            'requests' => 'integer',
            'client_errors' => 'integer',
            'server_errors' => 'integer',
            'duration_sum' => 'integer',
            'duration_max' => 'integer',
            'histogram' => 'array',
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
