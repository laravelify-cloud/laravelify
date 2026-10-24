<?php

namespace App\Models;

use Database\Factories\CodeWorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Projects opened together in VS Code. One project opens that website's own editor; several
 * open one editor of the workspace's own (see App\Services\CodeWorkspaces).
 */
class CodeWorkspace extends Model
{
    /** @use HasFactory<CodeWorkspaceFactory> */
    use HasFactory;

    /** The Unix user of a workspace's editor: lfy-ws--<key>. "--" is never in a website's slug. */
    public const USER_PREFIX = 'lfy-ws--';

    protected $fillable = [
        'key',
        'name',
        'code_server_port',
        'code_server_token',
        'share_token',
        'user_id',
        'opened_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Website, $this>
     */
    public function websites(): BelongsToMany
    {
        return $this->belongsToMany(Website::class)->orderBy('name');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Several projects in one editor of its own (one project uses that website's editor). */
    public function isMulti(): bool
    {
        return $this->websites->count() > 1;
    }

    public function unixUser(): string
    {
        return self::USER_PREFIX.$this->key;
    }
}
