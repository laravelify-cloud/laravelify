<?php

namespace Database\Factories;

use App\Models\BackupStorage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupStorage>
 */
class BackupStorageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'endpoint' => 'https://nyc3.digitaloceanspaces.com',
            'region' => 'nyc3',
            'bucket' => 'backups-'.fake()->unique()->numerify('####'),
            'path' => 'laravelify',
            'access_key' => 'AKIA'.fake()->bothify('????????????'),
            'secret_key' => fake()->sha256(),
            'is_default' => false,
            'checked_at' => now(),
        ];
    }

    /**
     * A folder on this server.
     */
    public function local(string $path = '/backups/laravelify'): static
    {
        return $this->state(['driver' => BackupStorage::DRIVER_LOCAL, 'endpoint' => null, 'region' => 'local', 'bucket' => '', 'path' => $path, 'access_key' => '', 'secret_key' => '']);
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
