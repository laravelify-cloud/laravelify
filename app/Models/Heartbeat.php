<?php

namespace App\Models;

use Database\Factories\HeartbeatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Heartbeat extends Model
{
    /** @use HasFactory<HeartbeatFactory> */
    use HasFactory;

    /**
     * Minutes between pings that the form offers.
     *
     * @var array<int, string>
     */
    public const INTERVALS = [
        1 => 'Every minute',
        5 => 'Every 5 minutes',
        15 => 'Every 15 minutes',
        30 => 'Every 30 minutes',
        60 => 'Hourly',
        360 => 'Every 6 hours',
        1440 => 'Daily',
        10080 => 'Weekly',
    ];

    protected $fillable = [
        'website_id',
        'name',
        'token',
        'interval_minutes',
        'grace_minutes',
        'last_ping_at',
        'late_since',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval_minutes' => 'integer',
            'grace_minutes' => 'integer',
            'last_ping_at' => 'datetime',
            'late_since' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Heartbeat $heartbeat): void {
            $heartbeat->token ??= Str::random(40);
        });
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function url(): string
    {
        return route('hooks.heartbeat', $this->token);
    }

    /**
     * When the next ping must arrive by. A new heartbeat counts from its creation.
     */
    public function dueAt(): Carbon
    {
        return ($this->last_ping_at ?? $this->created_at ?? now())->copy()->addMinutes($this->interval_minutes + $this->grace_minutes);
    }

    public function isLate(): bool
    {
        return $this->dueAt()->isPast();
    }

    /**
     * waiting (never pinged), ok, or late.
     */
    public function state(): string
    {
        return match (true) {
            $this->isLate() => 'late',
            $this->last_ping_at === null => 'waiting',
            default => 'ok',
        };
    }
}
