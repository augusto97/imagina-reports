<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportTemplateRequest;
use App\Http\Requests\UpdateReportTemplateRequest;
use App\Http\Resources\ReportTemplateResource;
use App\Models\ReportTemplate;
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
}
