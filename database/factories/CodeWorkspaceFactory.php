<?php

namespace Database\Factories;

use App\Models\CodeWorkspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CodeWorkspace>
 */
class CodeWorkspaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => Str::lower(Str::random(10)),
            'name' => fake()->words(2, true),
        ];
    }
}
