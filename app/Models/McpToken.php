<?php

namespace App\Models;

use Database\Factories\McpTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A token an AI client (Claude Code, Cursor, …) uses to reach the panel's MCP server.
 * Only a SHA-256 hash is stored; the token itself is shown once, when it is made.
 */
class McpToken extends Model
{
    /** @use HasFactory<McpTokenFactory> */
    use HasFactory;

    public const PREFIX = 'lvf_';

    protected $fillable = [
        'user_id',
        'name',
        'token_hash',
        'prefix',
        'last_used_at',
        'last_used_ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Make a token for a user. The plain token is returned once and never stored.
     *
     * @return array{token: McpToken, plain: string}
     */
    public static function issue(User $user, string $name): array
    {
        $plain = self::PREFIX.Str::random(48);

        $token = self::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 10),
        ]);

        return ['token' => $token, 'plain' => $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        return self::query()->with('user')->where('token_hash', hash('sha256', $plain))->first();
    }
}
