<?php

namespace Database\Factories;

use App\Models\Heartbeat;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Heartbeat>
 */
class HeartbeatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'name' => 'schedule:run',
            'interval_minutes' => 5,
            'grace_minutes' => 2,
            'last_ping_at' => now(),
        ];
    }
}
