<?php

namespace Database\Factories;

use App\Models\ServerSample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerSample>
 */
class ServerSampleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'at' => now(),
            'cpu' => fake()->randomFloat(2, 2, 60),
            'memory' => fake()->randomFloat(2, 30, 70),
            'swap' => 0,
            'load1' => fake()->randomFloat(2, 0, 2),
            'disk' => 41.5,
            'net_in' => fake()->numberBetween(1000, 500000),
            'net_out' => fake()->numberBetween(1000, 500000),
        ];
    }
}
