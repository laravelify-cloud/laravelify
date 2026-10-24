<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message of a panel AI chat. The assistant's answer is written as it streams in.
 */
class AiChatMessage extends Model
{
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const STOPPED = 'stopped';

    public const ERROR = 'error';

    protected $fillable = [
        'ai_chat_id',
        'role',
        'text',
        'tools',
        'attachments',
        'agent',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'attachments' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AiChat, $this>
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(AiChat::class, 'ai_chat_id');
    }
}
