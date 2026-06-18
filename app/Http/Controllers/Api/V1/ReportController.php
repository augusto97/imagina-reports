<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateReportRequest;
use App\Http\Resources\ReportSummaryResource;
use App\Jobs\GenerateReportJob;
use App\Models\Report;
use App\Models\ReportDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

    public function generate(GenerateReportRequest $request): JsonResponse
    {
        // Enforce that the definition belongs to this agency (scoped 404 otherwise).
        $definition = ReportDefinition::query()->findOrFail($request->integer('report_definition_id'));

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
}
