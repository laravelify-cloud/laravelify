<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat with the panel's AI assistant (App\Services\AiAssistant).
 */
class AiChat extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'agent',
        'model',
        'effort',
        'session_id',
    ];

    /**
     * @return HasMany<AiChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isBusy(): bool
    {
        return $this->messages()->where('status', AiChatMessage::RUNNING)->exists();
    }
}
