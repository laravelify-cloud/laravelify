<?php

namespace Database\Factories;

use App\Models\SiteRequest;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteRequest>
 */
class SiteRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = '/'.fake()->randomElement(['', 'login', 'dashboard', 'api/messages']);

        return [
            'website_id' => Website::factory(),
            'at' => now()->subMinutes(fake()->numberBetween(0, 50)),
            'method' => 'GET',
            'uri' => $path,
            'route' => $path,
            'status' => 200,
            'duration_ms' => fake()->numberBetween(20, 400),
            'bytes' => fake()->numberBetween(200, 50000),
            'ip' => fake()->ipv4(),
            'country' => null,
            'user_agent' => 'Mozilla/5.0',
            'referer' => null,
            'threat' => null,
            'risk' => 0,
        ];
    }

    /**
     * A request flagged by the threat detector.
     */
    public function threat(string $category = 'sql_injection', int $risk = 90): static
    {
        return $this->state(fn (): array => [
            'uri' => "/search?q=1' UNION SELECT password FROM users--",
            'route' => '/search',
            'status' => 404,
            'threat' => $category,
            'risk' => $risk,
        ]);
    }
}
