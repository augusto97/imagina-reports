<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * Admin-side report metadata (list/show). The full rendered payload is served to
 * the portal/PDF by ReportResource via the public token.
 */
final class ReportSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource;

        if (! $report instanceof Report) {
            throw new LogicException('ReportSummaryResource expects a Report.');
        }

        return [
            'id' => $report->id,
            'report_definition_id' => $report->report_definition_id,
            'period_start' => $report->period_start->toIso8601String(),
            'period_end' => $report->period_end->toIso8601String(),
            'health_score' => $report->health_score,
            'status' => $report->status->value,
            'public_token' => $report->public_token,
            'pdf_path' => $report->pdf_path,
        ];
    }
}
