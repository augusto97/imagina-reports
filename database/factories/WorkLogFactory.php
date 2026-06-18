<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Site;
use App\Models\WorkLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkLog>
 */
class WorkLogFactory extends Factory
{
    protected $model = WorkLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'report_id' => null,
            'site_id' => Site::factory(),
            'performed_at' => now()->subDays(3),
            'description' => fake()->sentence(),
            'screenshot_path' => null,
        ];
    }
}
