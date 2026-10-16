<?php

namespace App\Models;

use Database\Factories\DatabaseChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change made in the Database tool: a row added, edited, or deleted, or a trigger, event,
 * procedure, or function created, changed, or dropped. Keeps what was there before and after,
 * so the change can be undone on its own later (a checkpoint), even when the table changed since.
 */
class DatabaseChange extends Model
{
    /** @use HasFactory<DatabaseChangeFactory> */
    use HasFactory;

    public const ROW = 'row';

    protected $fillable = [
        'user_id',
        'database',
        'table',
        'action',
        'object_type',
        'object_name',
        'keys',
        'before',
        'after',
        'sql_before',
        'sql_after',
        'snapshot',
        'reverts_id',
        'reverted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'keys' => 'array',
            'before' => 'array',
            'after' => 'array',
            'reverted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reverts(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverts_id');
    }

    public function isRow(): bool
    {
        return $this->object_type === self::ROW;
    }

    /**
     * A short line for the log, like "Deleted a row from users (id 42)".
     */
    public function summary(): string
    {
        if (! $this->isRow()) {
            $verb = ['create' => 'Created', 'alter' => 'Changed', 'drop' => 'Dropped'][$this->action] ?? ucfirst($this->action);

            return $verb.' '.$this->object_type.' '.$this->object_name;
        }

        $verb = ['insert' => 'Added a row to', 'update' => 'Edited a row in', 'delete' => 'Deleted a row from'][$this->action] ?? $this->action;
        $keys = collect((array) $this->keys)->map(fn ($value, $column) => $column.' '.(is_scalar($value) ? $value : json_encode($value)))->implode(', ');

        // getAttribute: inside the model, $this->table is Eloquent's own table name.
        return $verb.' '.$this->getAttribute('table').($keys !== '' ? ' ('.$keys.')' : '');
    }

    /**
     * The columns an edit changed.
     *
     * @return list<string>
     */
    public function changedColumns(): array
    {
        $before = (array) $this->before;
        $after = (array) $this->after;

        return array_values(array_filter(array_keys($after), fn (string $column): bool => ! array_key_exists($column, $before) || $before[$column] !== $after[$column]));
    }
}
