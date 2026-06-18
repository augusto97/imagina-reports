<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DataSourceStatus;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\DataSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSource>
 */
class DataSourceFactory extends Factory
{
    protected $model = DataSource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'site_id' => null,
            'type' => DataSourceType::MainWp,
            'credentials' => ['token' => fake()->sha256()],
            'config' => ['dashboard_url' => fake()->url()],
            'status' => DataSourceStatus::Pending,
            'last_synced_at' => null,
            'last_error' => null,
        ];
    }
}
