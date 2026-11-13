<?php

namespace Database\Factories;

use App\Models\Plugin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plugin>
 */
class PluginFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'redis',
            'status' => Plugin::RUNNING,
            'ports' => ['redis' => 6380],
            'credentials' => ['password' => 'secretredispassword'],
            'credentials_shown_at' => now(),
        ];
    }
}
