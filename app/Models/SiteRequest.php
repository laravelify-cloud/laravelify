<?php

namespace App\Models;

use Database\Factories\SiteRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteRequest extends Model
{
    /** @use HasFactory<SiteRequestFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'website_id',
        'at',
        'method',
        'uri',
        'route',
        'status',
        'duration_ms',
        'bytes',
        'ip',
        'country',
        'user_agent',
        'referer',
        'threat',
        'risk',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'at' => 'datetime',
            'status' => 'integer',
            'duration_ms' => 'integer',
            'bytes' => 'integer',
            'risk' => 'integer',
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
