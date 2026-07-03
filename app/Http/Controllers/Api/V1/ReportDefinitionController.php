<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportDefinitionRequest;
use App\Http\Requests\UpdateReportDefinitionRequest;
use App\Http\Resources\ReportDefinitionResource;
use App\Models\ReportDefinition;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Services\Reports\ReportPdfCleanup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ReportDefinitionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ReportDefinitionResource::collection(ReportDefinition::query()->latest()->get());
    }

    public function store(StoreReportDefinitionRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Enforce that the site (and template, if any) belong to this agency (scoped 404).
        Site::query()->findOrFail($data['site_id']);
        $this->assertOwnedTemplate($data);

        $definition = ReportDefinition::query()->create($data);

        return ReportDefinitionResource::make($definition)->response()->setStatusCode(201);
    }

    public function show(ReportDefinition $reportDefinition): ReportDefinitionResource
    {
        return new ReportDefinitionResource($reportDefinition);
    }

    public function update(UpdateReportDefinitionRequest $request, ReportDefinition $reportDefinition): ReportDefinitionResource
    {
        $data = $request->validated();

        $this->assertOwnedTemplate($data);
        $reportDefinition->update($data);

        return new ReportDefinitionResource($reportDefinition);
    }

    /**
     * A definition may reference a template by id from the request body; make sure it
     * belongs to this agency (the AgencyScope 404s a foreign one) so it can't bind to
     * another tenant's template.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertOwnedTemplate(array $data): void
    {
        $templateId = $data['template_id'] ?? null;

        if ($templateId !== null && $templateId !== '') {
            ReportTemplate::query()->findOrFail($templateId);
        }
    }

    public function destroy(Request $request, ReportDefinition $reportDefinition): JsonResponse
    {
        $this->authorizePrivileged($request);

        // Purge the reports' PDF files first — the FK cascade removes the rows but leaves the
        // stored files orphaned (FUN — PDF cleanup).
        ReportPdfCleanup::forDefinitions([$reportDefinition->id]);

        // Cascade deletes its generated reports (FK), so this clears the definition end to end.
        $reportDefinition->delete();

        return response()->json(['message' => 'Definition deleted.']);
    }
}
