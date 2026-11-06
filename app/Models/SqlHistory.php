<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the SQL tab in one database. kind: read (rows came back), write (rows changed), or
 * other. changes: for each UPDATE or DELETE, the table, its key columns, and the rows before and
 * after (up to 200 rows).
 *
 * @property array<int, array{table: string, action: string, keys: list<string>, before: list<array<string, mixed>>, after: list<array<string, mixed>>, truncated: bool}>|null $changes
 */
class SqlHistory extends Model
{
    /** How many runs each database keeps. */
    public const KEEP = 500;

    protected $fillable = ['user_id', 'engine', 'database', 'sql', 'kind', 'ok', 'rows', 'affected', 'error', 'duration_ms', 'changes'];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'changes' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Save a run and drop the oldest runs of that database past KEEP.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function record(string $engine, string $database, array $attributes): self
    {
        $entry = self::query()->create(['engine' => $engine, 'database' => $database, 'user_id' => auth()->id()] + $attributes);
        $cutoff = self::query()->where('engine', $engine)->where('database', $database)->orderByDesc('id')->skip(self::KEEP)->value('id');

        if ($cutoff !== null) {
            self::query()->where('engine', $engine)->where('database', $database)->where('id', '<=', $cutoff)->delete();
        }

        return $entry;
    }
}
