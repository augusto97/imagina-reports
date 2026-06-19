<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Agency;
use App\Models\ReportTemplate;
use App\Reports\Templates\DefaultTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportTemplate>
 */
class ReportTemplateFactory extends Factory
{
    protected $model = ReportTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'name' => 'Default',
            'blocks' => DefaultTemplate::blocks(),
            'is_default' => true,
            'locale' => 'es',
        ];
    }
}
