<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateReportNarrativeRequest;
use App\Models\Report;
use App\Reports\AiReportBuilder;
use App\Reports\ExecutiveSummary;
use App\Reports\ReportFacts;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Edit or regenerate a report's AI executive summary before sending (§10.6 "always
 * editable"). Both operate on the FROZEN resolved blocks — regenerate re-runs the AI
 * over the stored figures, never a live data-source API (§3.1). Tenant-scoped via the
 * route-model binding (agency scope is active under the `tenant` middleware).
 */
final class ReportNarrativeController extends Controller
{
    /**
     * Save an operator-edited summary.
     */
    public function update(UpdateReportNarrativeRequest $request, Report $report): JsonResponse
    {
        $text = trim((string) $request->string('text'));

        $this->apply($report, $text);

        return response()->json(['executive_summary' => $report->executive_summary]);
    }

    /**
     * Re-write the summary with the AI from the report's stored figures.
     */
    public function regenerate(Report $report, AiReportBuilder $builder): JsonResponse
    {
        $resolved = $report->resolved_blocks;
        $blocks = is_array($resolved['blocks'] ?? null) ? $resolved['blocks'] : [];
        $data = is_array($resolved['data'] ?? null) ? $resolved['data'] : [];

        $facts = ReportFacts::build($blocks, $data, $report->health_score);

        if ($facts === []) {
            return response()->json(['executive_summary' => $report->executive_summary]);
        }

        try {
            $text = trim($builder->narrative($facts, $this->locale($report)));
        } catch (Throwable) {
            return response()->json(['message' => 'No se pudo regenerar el resumen con la IA. Revisa la API key de la agencia.'], 502);
        }

        if ($text !== '') {
            $this->apply($report, $text);
        }

        return response()->json(['executive_summary' => $report->executive_summary]);
    }

    /**
     * Store the text on the column AND inject it into the resolved executive-summary
     * block(s) so the portal/PDF reflect the edit (shared with the generator).
     */
    private function apply(Report $report, string $text): void
    {
        $resolved = $report->resolved_blocks;
        $blocks = is_array($resolved['blocks'] ?? null) ? $resolved['blocks'] : [];
        $data = is_array($resolved['data'] ?? null) ? $resolved['data'] : [];

        $resolved['data'] = ExecutiveSummary::inject($blocks, $data, $text);
        $report->resolved_blocks = $resolved;
        $report->executive_summary = $text;
        $report->save();
    }

    private function locale(Report $report): string
    {
        $definitionLocale = $report->definition?->locale;

        if (is_string($definitionLocale) && $definitionLocale !== '') {
            return $definitionLocale;
        }

        $agencyLocale = $report->agency?->default_locale;

        return is_string($agencyLocale) && $agencyLocale !== '' ? $agencyLocale : 'es';
    }
}
