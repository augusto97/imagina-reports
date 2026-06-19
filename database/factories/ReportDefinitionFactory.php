<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Agency;
use App\Models\ReportDefinition;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportDefinition>
 */
class ReportDefinitionFactory extends Factory
{
    protected $model = ReportDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'site_id' => Site::factory(),
            'name' => 'Monthly report',
            'template_id' => null,
            'blocks' => null,
            'requested_metrics' => null,
            'locale' => 'es',
            'schedule' => null,
            'recipients' => null,
        ];
    }
}
