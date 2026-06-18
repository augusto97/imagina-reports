<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkLogRequest;
use App\Http\Resources\WorkLogResource;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Manual "what we did this month" entries for a report (CLAUDE.md §8/§11.5).
 */
final class WorkLogController extends Controller
{
    public function index(Report $report): AnonymousResourceCollection
    {
        return WorkLogResource::collection($report->workLogs()->get());
    }

    public function store(StoreWorkLogRequest $request, Report $report): JsonResponse
    {
        $log = $report->workLogs()->create([
            ...$request->validated(),
            'site_id' => $report->definition?->site_id,
        ]);

        return WorkLogResource::make($log)->response()->setStatusCode(201);
    }
}
