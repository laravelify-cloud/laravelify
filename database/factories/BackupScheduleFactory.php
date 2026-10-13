<?php

namespace Database\Factories;

use App\Models\BackupSchedule;
use App\Models\BackupStorage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupSchedule>
 */
class BackupScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'kind' => BackupSchedule::KIND_DATABASE,
            'backup_storage_id' => BackupStorage::factory(),
            'enabled' => true,
            'frequency' => 'hourly',
            'interval' => 1,
            'run_time' => '00:00',
            'weekday' => 0,
            'cron' => '0 * * * *',
            'targets' => ['all' => false, 'databases' => ['app_db']],
            'retention_count' => 3,
        ];
    }

    public function files(): static
    {
        return $this->state([
            'kind' => BackupSchedule::KIND_FILES,
            'targets' => ['paths' => ['site'], 'excludes' => ['node_modules']],
        ]);
    }
}
