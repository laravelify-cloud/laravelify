<?php

namespace Database\Factories;

use App\Models\CloudflareTunnel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CloudflareTunnel>
 */
class CloudflareTunnelFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'token' => 'tunnel-token-'.Str::random(20),
            'ingress' => "ingress:\n  - service: http_status:404\n",
        ];
    }
}
