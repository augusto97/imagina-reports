<?php

declare(strict_types=1);

namespace App\Reports\Sharing;

use App\Models\Report;
use App\Support\CustomDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who may reach a report through its public token: the same rules for the portal data, the
 * PDF, the month selector and the client's conversation, so none of them can be a side door.
 * Returns null when access is allowed, or the denial response otherwise.
 */
final class PublicReportGate
{
    public static function deny(Report $report, Request $request): ?JsonResponse
    {
        // Opened through another agency's own domain: as if it didn't exist.
        if (CustomDomain::blocks($request, $report->agency_id)) {
            return response()->json(['message' => 'Not Found.'], 404);
        }

        // Suspended (unpaid) agency → its whole public surface is dark, print included
        // (delivery is blocked for suspended agencies anyway). Checked on the report's own
        // agency so ad-hoc reports without a definition are covered too.
        if ($report->agency?->isSuspended() === true) {
            return response()->json(['message' => 'Este informe no está disponible en este momento.'], 402);
        }

        // PDF generation (Browsershot) bypasses the gate with the server-only print token.
        if (self::isPrintRender($report, $request)) {
            return null;
        }

        return ShareGate::deny($report->definition, $request);
    }

    public static function isPrintRender(Report $report, Request $request): bool
    {
        $print = $request->header('X-Print-Token') ?: $request->query('print');

        return is_string($print) && $print !== '' && hash_equals($report->printToken(), $print);
    }
}
