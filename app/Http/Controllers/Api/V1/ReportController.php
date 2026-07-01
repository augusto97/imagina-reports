<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateReportRequest;
use App\Http\Resources\ReportSummaryResource;
use App\Jobs\DeliverReportJob;
use App\Jobs\GenerateReportJob;
use App\Models\Agency;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Services\Platform\Entitlements;
use App\Services\ReportPdfService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ReportSummaryResource::collection(Report::query()->latest()->get());
    }

    public function show(Report $report): ReportSummaryResource
    {
        return new ReportSummaryResource($report);
    }

    public function generate(GenerateReportRequest $request, Entitlements $entitlements, TenantContext $tenant): JsonResponse
    {
        // Enforce that the definition belongs to this agency (scoped 404 otherwise).
        $definition = ReportDefinition::query()->findOrFail($request->integer('report_definition_id'));

        $agency = Agency::query()->findOrFail($tenant->id());
        abort_unless($entitlements->canGenerateReport($agency), 403, 'Has alcanzado el límite de reportes de este mes de tu plan. Mejora el plan para generar más.');

        GenerateReportJob::dispatch(
            $definition->id,
            $request->string('period_start')->toString(),
            $request->string('period_end')->toString(),
        );

        return response()->json(['message' => 'Report generation queued.'], 202);
    }

    public function approve(Report $report): ReportSummaryResource
    {
        $report->update(['status' => ReportStatus::Approved]);

        return new ReportSummaryResource($report);
    }

    /**
     * Enqueue DELIVER (CLAUDE.md §3.1/§8): render the PDF and email the branded
     * report to the definition's recipients. Reports must be approved first so an
     * unreviewed draft can't go out by accident.
     */
    public function send(Report $report): JsonResponse
    {
        if ($report->status === ReportStatus::Draft) {
            return response()->json(['message' => 'Aprueba el reporte antes de enviarlo.'], 422);
        }

        DeliverReportJob::dispatch($report->id);

        return response()->json(['message' => 'Report delivery queued.'], 202);
    }

    /**
     * Render the report to PDF on demand (headless Chromium prints the same page the
     * portal shows, CLAUDE.md §10.7) and stream it as a download. Regenerated each
     * time so it reflects any work logs / comments added since generation.
     */
    public function pdf(Report $report, ReportPdfService $service): StreamedResponse|JsonResponse
    {
        try {
            $path = $service->generate($report);
        } catch (\Throwable $e) {
            // Surface a readable reason instead of a silent 500 (usually a Chromium /
            // Browsershot misconfig on the server, CLAUDE.md §12.5).
            Log::error('Report PDF generation failed', ['report_id' => $report->id, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'No se pudo generar el PDF. Revisa que Chromium esté instalado y que BROWSERSHOT_CHROME_PATH apunte al binario. Detalle: '.$e->getMessage(),
            ], 500);
        }

        return Storage::download($path, "reporte-{$report->id}.pdf");
    }

    public function destroy(Report $report): JsonResponse
    {
        if ($report->pdf_path !== null) {
            Storage::delete($report->pdf_path);
        }

        $report->delete();

        return response()->json(['message' => 'Report deleted.']);
    }
}
