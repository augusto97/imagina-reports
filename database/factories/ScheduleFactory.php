<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScheduleCadence;
use App\Models\Agency;
use App\Models\ReportDefinition;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'report_definition_id' => ReportDefinition::factory(),
            'cadence' => ScheduleCadence::Monthly,
            'next_run_at' => now()->addMonth()->startOfMonth(),
        ];
    }

    public function due(): static
    {
        return $this->state(fn (array $attributes): array => [
            'next_run_at' => now()->subDay(),
        ]);
    }
}
