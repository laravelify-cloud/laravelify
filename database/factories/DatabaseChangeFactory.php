<?php

namespace Database\Factories;

use App\Models\DatabaseChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseChange>
 */
class DatabaseChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = fake()->numberBetween(1, 1000);

        return [
            'database' => 'app_db',
            'table' => 'users',
            'action' => 'update',
            'object_type' => DatabaseChange::ROW,
            'keys' => ['id' => $id],
            'before' => ['id' => $id, 'name' => 'Old'],
            'after' => ['id' => $id, 'name' => 'New'],
        ];
    }
}
