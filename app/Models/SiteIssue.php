<?php

namespace App\Models;

use Database\Factories\SiteIssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteIssue extends Model
{
    /** @use HasFactory<SiteIssueFactory> */
    use HasFactory;

    public const STATUSES = ['open', 'resolved', 'ignored'];

    protected $fillable = [
        'website_id',
        'fingerprint',
        'level',
        'exception',
        'message',
        'file',
        'line',
        'trace',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'status',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'occurrences' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * The short class name, like "QueryException", or the log level.
     */
    public function title(): string
    {
        return $this->exception ? class_basename($this->exception) : ucfirst(strtolower($this->level));
    }
}
