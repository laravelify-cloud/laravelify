<?php

namespace Database\Factories;

use App\Models\PanelTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PanelTask>
 */
class PanelTaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'compress',
            'scope' => 'files:global',
            'status' => PanelTask::QUEUED,
            'label' => 'Compressing '.fake()->word(),
            'payload' => [],
            'progress' => 0,
        ];
    }

    public function finished(): static
    {
        return $this->state(['status' => PanelTask::SUCCESS, 'progress' => 100, 'started_at' => now()->subSeconds(5), 'finished_at' => now()]);
    }
}
