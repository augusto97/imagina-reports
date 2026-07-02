<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use App\Reports\Sharing\ShareGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, token-authenticated report data for the interactive portal and the PDF
 * (CLAUDE.md §8). No Sanctum auth — the signed public_token is the capability, so
 * the query bypasses the AgencyScope.
 */
final class PublicReportController extends Controller
{
    public function show(Request $request, string $token): ReportResource|JsonResponse
    {
        $report = Report::query()
            ->withoutGlobalScopes()
            ->with(['agency', 'workLogs', 'definition.site.client'])
            ->where('public_token', $token)
            ->firstOrFail();

        if (($denied = $this->gate($report, $request)) !== null) {
            return $denied;
        }

        return new ReportResource($report);
    }

    /**
     * Sibling reports (same definition) for the portal's period selector (§11.2).
     */
    public function periods(Request $request, string $token): JsonResponse
    {
        $report = Report::query()
            ->withoutGlobalScopes()
            ->with('definition')
            ->where('public_token', $token)
            ->firstOrFail();

        if (($denied = $this->gate($report, $request)) !== null) {
            return $denied;
        }

        $periods = Report::query()
            ->withoutGlobalScopes()
            ->where('report_definition_id', $report->report_definition_id)
            ->orderByDesc('period_start')
            ->get(['public_token', 'period_start', 'period_end'])
            ->map(static fn (Report $sibling): array => [
                'public_token' => $sibling->public_token,
                'period_start' => $sibling->period_start->toIso8601String(),
                'period_end' => $sibling->period_end->toIso8601String(),
            ]);

        return response()->json($periods->all());
    }

    /**
     * Enforce the definition's sharing settings (CLAUDE.md §10/Etapa D). The PDF renderer
     * carries a server-only print token that bypasses the gate so protected reports still
     * generate. Returns null when access is allowed, or the denial response otherwise.
     */
    private function gate(Report $report, Request $request): ?JsonResponse
    {
        // Suspended (unpaid) agency → its whole public surface is dark, print included
        // (delivery is blocked for suspended agencies anyway). Checked on the report's own
        // agency so ad-hoc reports without a definition are covered too.
        if ($report->agency?->isSuspended() === true) {
            return response()->json(['message' => 'Este informe no está disponible en este momento.'], 402);
        }

        // PDF generation (Browsershot) bypasses the gate with the server-only print token.
        $print = $request->header('X-Print-Token') ?: $request->query('print');
        if (is_string($print) && hash_equals($report->printToken(), $print)) {
            return null;
        }

        return ShareGate::deny($report->definition, $request);
    }
}
