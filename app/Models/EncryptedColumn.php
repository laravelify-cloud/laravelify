<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A column the Database tool shows decrypted: its values are stored encrypted by a website's
 * encryption method. Only the setting is kept here, never a decrypted value.
 */
class EncryptedColumn extends Model
{
    protected $fillable = ['database', 'table', 'column', 'crypto_method_id'];

    public function method(): BelongsTo
    {
        return $this->belongsTo(CryptoMethod::class, 'crypto_method_id');
    }

    /**
     * The encrypted columns of one table and their methods, by column.
     *
     * @return array<string, CryptoMethod>
     */
    public static function forTable(string $database, string $table): array
    {
        return self::query()->with('method.website')
            ->where('database', $database)
            ->where('table', $table)
            ->get()
            ->filter(fn (self $column): bool => $column->method !== null)
            ->mapWithKeys(fn (self $column): array => [$column->getAttribute('column') => $column->method])
            ->all();
    }
}
