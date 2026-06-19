<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportDefinitionRequest;
use App\Http\Requests\UpdateReportDefinitionRequest;
use App\Http\Resources\ReportDefinitionResource;
use App\Models\ReportDefinition;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
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

        // Enforce that the site belongs to this agency (scoped 404 otherwise).
        Site::query()->findOrFail($data['site_id']);

        $definition = ReportDefinition::query()->create($data);

        return ReportDefinitionResource::make($definition)->response()->setStatusCode(201);
    }

    public function show(ReportDefinition $reportDefinition): ReportDefinitionResource
    {
        return new ReportDefinitionResource($reportDefinition);
    }

    public function update(UpdateReportDefinitionRequest $request, ReportDefinition $reportDefinition): ReportDefinitionResource
    {
        $reportDefinition->update($request->validated());

        return new ReportDefinitionResource($reportDefinition);
    }
}
