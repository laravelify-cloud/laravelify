<?php

namespace Database\Factories;

use App\Models\SiteIssue;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteIssue>
 */
class SiteIssueFactory extends Factory
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
            'fingerprint' => sha1(fake()->unique()->uuid()),
            'level' => 'ERROR',
            'exception' => 'Illuminate\\Database\\QueryException',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
            'file' => 'vendor/laravel/framework/src/Illuminate/Database/Connection.php',
            'line' => 825,
            'trace' => null,
            'occurrences' => 1,
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now(),
            'status' => 'open',
            'resolved_at' => null,
        ];
    }
}
