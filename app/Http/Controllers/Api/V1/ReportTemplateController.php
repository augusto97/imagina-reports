<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportTemplateRequest;
use App\Http\Requests\UpdateReportTemplateRequest;
use App\Http\Resources\ReportTemplateResource;
use App\Models\ReportDefinition;
use App\Models\ReportTemplate;
use App\Reports\Templates\DefaultTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Reusable report templates (CLAUDE.md §10.2). The block layout is validated
 * server-side (ValidatesBlocks) so a saved template is always renderable.
 */
final class ReportTemplateController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ReportTemplateResource::collection(ReportTemplate::query()->latest()->get());
    }

    /**
     * The default narrative layout (CLAUDE.md §11.5) as a starting point for the
     * editor — a professional template instead of a blank canvas.
     */
    public function defaultBlocks(): JsonResponse
    {
        return response()->json(['blocks' => DefaultTemplate::blocks()]);
    }

    public function store(StoreReportTemplateRequest $request): JsonResponse
    {
        $template = ReportTemplate::query()->create($request->validated());

        return ReportTemplateResource::make($template)->response()->setStatusCode(201);
    }

    public function show(ReportTemplate $reportTemplate): ReportTemplateResource
    {
        return new ReportTemplateResource($reportTemplate);
    }

    public function update(UpdateReportTemplateRequest $request, ReportTemplate $reportTemplate): ReportTemplateResource
    {
        $reportTemplate->update($request->validated());

        return new ReportTemplateResource($reportTemplate);
    }

    public function destroy(ReportTemplate $reportTemplate): JsonResponse
    {
        // Deleting a template the DB null-outs (nullOnDelete) every definition that uses
        // it, which would silently make those reports fall back to the default layout.
        // Block it with a clear error so the association can be reassigned first.
        $inUse = ReportDefinition::query()->where('template_id', $reportTemplate->id)->count();

        if ($inUse > 0) {
            return response()->json([
                'message' => "Esta plantilla está en uso por {$inUse} definición(es) de reporte. Cámbiales la plantilla antes de borrarla.",
            ], 409);
        }

        $reportTemplate->delete();

        return response()->json(['message' => 'Template deleted.']);
    }
}
