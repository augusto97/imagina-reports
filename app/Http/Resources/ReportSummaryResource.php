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

        // These three are read from denormalized columns kept in sync with resolved_blocks by
        // the Report model (PERF-3), so the list never decodes the heavy snapshot JSON:
        //   - hidden_metrics: metrics whose blocks were hidden for lack of data (diagnostics);
        //   - has_advisory / advisory: whether the report has an AI advisory block + its text.
        return [
            'id' => $report->id,
            'report_definition_id' => $report->report_definition_id,
            'period_start' => $report->period_start->toIso8601String(),
            'period_end' => $report->period_end->toIso8601String(),
            'health_score' => $report->health_score,
            'status' => $report->status->value,
            'executive_summary' => $report->executive_summary,
            'has_advisory' => $report->has_advisory,
            'advisory' => $report->advisory,
            'public_token' => $report->public_token,
            'pdf_path' => $report->pdf_path,
            'hidden_metrics' => $report->hidden_metrics ?? [],
            // When the report was generated, so the list shows the exact moment (not just the period).
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }
}
