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

        // Metrics whose blocks were hidden at generation because they had no data for the
        // period — surfaced so the agency sees exactly what's missing (not a silent gap).
        $diagnostics = $report->resolved_blocks['diagnostics'] ?? [];
        $hiddenMetrics = [];

        if (is_array($diagnostics)) {
            foreach ($diagnostics as $diagnostic) {
                $source = is_array($diagnostic) ? ($diagnostic['source'] ?? null) : null;
                $metric = is_array($diagnostic) ? ($diagnostic['metric'] ?? null) : null;

                if (is_string($source) && is_string($metric)) {
                    $hiddenMetrics[] = "{$source}.{$metric}";
                }
            }
        }

        // The AI advisory block (if the report has one) + its current text, so the admin can
        // edit/regenerate it like the executive summary. The text lives in resolved_blocks.data.
        $blocks = $report->resolved_blocks['blocks'] ?? [];
        $data = $report->resolved_blocks['data'] ?? [];
        $hasAdvisory = false;
        $advisory = null;

        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'advisory') {
                    $hasAdvisory = true;
                    $id = $block['id'] ?? null;
                    $value = is_string($id) && is_array($data) ? ($data[$id] ?? null) : null;
                    if (is_string($value)) {
                        $advisory = $value;
                    }
                }
            }
        }

        return [
            'id' => $report->id,
            'report_definition_id' => $report->report_definition_id,
            'period_start' => $report->period_start->toIso8601String(),
            'period_end' => $report->period_end->toIso8601String(),
            'health_score' => $report->health_score,
            'status' => $report->status->value,
            'executive_summary' => $report->executive_summary,
            'has_advisory' => $hasAdvisory,
            'advisory' => $advisory,
            'public_token' => $report->public_token,
            'pdf_path' => $report->pdf_path,
            'hidden_metrics' => $hiddenMetrics,
            // When the report was generated, so the list shows the exact moment (not just the period).
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }
}
