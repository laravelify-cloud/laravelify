<?php

namespace Database\Factories;

use App\Models\TrashItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrashItem>
 */
class TrashItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().'.txt';

        return [
            'scope' => 'files:global',
            'original_path' => $name,
            'name' => $name,
            'is_dir' => false,
            'size_bytes' => 120,
        ];
    }
}
