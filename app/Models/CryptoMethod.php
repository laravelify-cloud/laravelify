<?php

namespace App\Models;

use Database\Factories\CryptoMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A website's own way to encrypt a value (and decrypt it), for searching encrypted columns in the
 * Database tool. The code is the body of `function (string $text): string`, like
 * `return encryptString($text);`, and runs in the website's folder as the website's user, with its
 * Laravel app loaded when boot_laravel is on. The code is encrypted at rest.
 */
class CryptoMethod extends Model
{
    /** @use HasFactory<CryptoMethodFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'website_id',
        'boot_laravel',
        'encrypt_code',
        'decrypt_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'boot_laravel' => 'boolean',
            'encrypt_code' => 'encrypted',
            'decrypt_code' => 'encrypted',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function canDecrypt(): bool
    {
        return trim((string) $this->decrypt_code) !== '';
    }
}
