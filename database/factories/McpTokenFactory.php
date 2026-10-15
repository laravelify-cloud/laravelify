<?php

namespace Database\Factories;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<McpToken>
 */
class McpTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = McpToken::PREFIX.Str::random(48);

        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 10),
        ];
    }
}
