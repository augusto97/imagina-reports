<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Connectors\MetricSetStatus;
use App\Models\Agency;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricSnapshot>
 */
class MetricSnapshotFactory extends Factory
{
    protected $model = MetricSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfMonth();

        return [
            'agency_id' => Agency::factory(),
            'data_source_id' => DataSource::factory(),
            'period_start' => $start,
            'period_end' => $start->copy()->endOfMonth(),
            'payload' => [
                'status' => MetricSetStatus::Ok->value,
                'error' => null,
                'metrics' => ['fake.visits' => 42],
            ],
            'status' => MetricSetStatus::Ok,
            'captured_at' => now(),
        ];
    }
}
