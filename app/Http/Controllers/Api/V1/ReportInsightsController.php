<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Reports\AiReportBuilder;
use App\Reports\ReportFacts;
use Illuminate\Http\JsonResponse;

/**
 * AI insights for a generated report (CLAUDE.md §10.6, competitor parity): turns the
 * report's resolved metrics into 3-5 plain-language takeaways the agency can paste
 * into the narrative or share with the client. Reads the frozen resolved_blocks —
 * never re-hits external APIs (§3.1).
 */
final class ReportInsightsController extends Controller
{
    public function store(Report $report, AiReportBuilder $builder): JsonResponse
    {
        $facts = $this->facts($report);

        if ($facts === []) {
            return response()->json(['insights' => []]);
        }

        return response()->json(['insights' => $builder->insights($facts, $report->agency->default_locale ?? 'es')]);
    }

    /**
     * Build a compact, semantic metric map (label + value) from the report's resolved
     * blocks so the AI reasons over named figures, not block ids (shared with the
     * per-period narrative via ReportFacts).
     *
     * @return array<string, mixed>
     */
    private function facts(Report $report): array
    {
        $resolved = $report->resolved_blocks;
        $blocks = is_array($resolved['blocks'] ?? null) ? $resolved['blocks'] : [];
        $data = is_array($resolved['data'] ?? null) ? $resolved['data'] : [];

        return ReportFacts::build($blocks, $data, $report->health_score);
    }
}
