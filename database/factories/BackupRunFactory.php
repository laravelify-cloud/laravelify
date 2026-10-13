<?php

namespace Database\Factories;

use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\BackupStorage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRun>
 */
class BackupRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'backup_schedule_id' => BackupSchedule::factory(),
            'backup_storage_id' => BackupStorage::factory(),
            'type' => BackupRun::TYPE_BACKUP,
            'kind' => BackupSchedule::KIND_DATABASE,
            'target' => 'app_db',
            'status' => BackupRun::SUCCESS,
            'trigger' => 'schedule',
            'object_key' => 'databases/app_db/'.fake()->unique()->numerify('########').'.sql.gz',
            'size_bytes' => 2048,
            'meta' => ['tables' => ['users', 'orders']],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
