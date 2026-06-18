<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\Agency;
use App\Models\Report;
use App\Models\ReportDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfMonth();

        return [
            'agency_id' => Agency::factory(),
            'report_definition_id' => ReportDefinition::factory(),
            'period_start' => $start,
            'period_end' => $start->copy()->endOfMonth(),
            'resolved_blocks' => ['blocks' => [], 'data' => []],
            'health_score' => 100,
            'executive_summary' => null,
            'pdf_path' => null,
            'public_token' => Str::random(48),
            'status' => ReportStatus::Draft,
        ];
    }
}
