<?php

namespace Database\Factories;

use App\Models\CryptoMethod;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CryptoMethod>
 */
class CryptoMethodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Site crypto',
            'website_id' => Website::factory(),
            'boot_laravel' => true,
            'encrypt_code' => 'return encryptString($text);',
            'decrypt_code' => 'return decryptString($text);',
        ];
    }
}
