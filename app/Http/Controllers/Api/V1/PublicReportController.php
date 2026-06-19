<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use Illuminate\Http\JsonResponse;

/**
 * Public, token-authenticated report data for the interactive portal and the PDF
 * (CLAUDE.md §8). No Sanctum auth — the signed public_token is the capability, so
 * the query bypasses the AgencyScope.
 */
final class PublicReportController extends Controller
{
    public function show(string $token): ReportResource
    {
        $report = Report::query()
            ->withoutGlobalScopes()
            ->with(['agency', 'workLogs'])
            ->where('public_token', $token)
            ->firstOrFail();

        return new ReportResource($report);
    }

    /**
     * Sibling reports (same definition) for the portal's period selector (§11.2).
     */
    public function periods(string $token): JsonResponse
    {
        $report = Report::query()
            ->withoutGlobalScopes()
            ->where('public_token', $token)
            ->firstOrFail();

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
}
