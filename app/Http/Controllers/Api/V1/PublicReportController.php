<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Models\Report;

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
            ->with('agency')
            ->where('public_token', $token)
            ->firstOrFail();

        return new ReportResource($report);
    }
}
