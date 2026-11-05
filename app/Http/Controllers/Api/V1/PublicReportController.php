<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use App\Models\User;
use App\Reports\Sharing\PublicReportGate;
use App\Services\ReportPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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

        if ($this->isClientVisit($report, $request)) {
            $report->recordView();
        }

        return new ReportResource($report);
    }

    /**
     * The report as a PDF for the client's "Descargar PDF" button. Reuses the stored file
     * while it's newer than the report (the send already rendered it); otherwise renders it
     * once and keeps it.
     */
    public function pdf(Request $request, string $token, ReportPdfService $service): StreamedResponse|JsonResponse
    {
        $report = Report::query()->withoutGlobalScopes()->with(['agency', 'definition'])->where('public_token', $token)->firstOrFail();

        if (($denied = $this->gate($report, $request)) !== null) {
            return $denied;
        }

        $path = $report->pdf_path;
        $fresh = $path !== null && Storage::exists($path) && Storage::lastModified($path) >= ($report->updated_at?->getTimestamp() ?? 0);

        if (! $fresh) {
            try {
                $path = $service->generate($report);
            } catch (Throwable $e) {
                Log::error('Public report PDF generation failed', ['report_id' => $report->id, 'error' => $e->getMessage()]);

                return response()->json(['message' => 'No pudimos preparar el PDF ahora mismo. Inténtalo de nuevo en unos minutos.'], 503);
            }
        }

        $name = Str::slug(($report->definition->name ?? 'reporte').' '.$report->period_start->format('Y-m')).'.pdf';

        return Storage::download($path, $name);
    }

    /**
     * A real client visit: not the PDF renderer (print token) and not someone from the agency
     * previewing it from the panel (their session rides along on same-site requests).
     */
    private function isClientVisit(Report $report, Request $request): bool
    {
        $print = $request->header('X-Print-Token') ?: $request->query('print');
        if (is_string($print) && $print !== '') {
            return false;
        }

        $user = Auth::guard('web')->user();

        return ! ($user instanceof User && ($user->agency_id === $report->agency_id || $user->is_platform_admin));
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

        // Only expose SENT siblings in the client-facing period selector. Otherwise a client
        // holding one period's link would receive the public_token of this month's unapproved
        // draft (incl. un-reviewed AI text) and could open it (FUN-3). Admin preview of a draft
        // still works — the admin opens that draft's token directly, not via this selector.
        $periods = Report::query()
            ->withoutGlobalScopes()
            ->where('report_definition_id', $report->report_definition_id)
            ->where('status', ReportStatus::Sent)
            ->orderByDesc('period_start')
            ->get(['public_token', 'period_start', 'period_end'])
            ->map(static fn (Report $sibling): array => [
                'public_token' => $sibling->public_token,
                'period_start' => $sibling->period_start->toIso8601String(),
                'period_end' => $sibling->period_end->toIso8601String(),
            ]);

        return response()->json($periods->all());
    }

    private function gate(Report $report, Request $request): ?JsonResponse
    {
        return PublicReportGate::deny($report, $request);
    }
}
