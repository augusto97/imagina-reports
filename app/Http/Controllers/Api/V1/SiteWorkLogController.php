<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkLogRequest;
use App\Http\Resources\WorkLogResource;
use App\Models\Site;
use App\Models\WorkLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Fast, day-to-day work logging per site (CLAUDE.md §11.5). The agency runs an
 * hourly support service, so these entries — what was done + optional minutes +
 * category — are added constantly, independent of any report. The report engine
 * later aggregates them into "hours invested this period".
 */
final class SiteWorkLogController extends Controller
{
    public function index(Request $request, Site $site): AnonymousResourceCollection
    {
        $query = $site->workLogs()->orderByDesc('performed_at');

        if ($request->filled('from')) {
            $query->where('performed_at', '>=', Carbon::parse($request->string('from')->toString())->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('performed_at', '<=', Carbon::parse($request->string('to')->toString())->endOfDay());
        }

        return WorkLogResource::collection($query->get());
    }

    public function store(StoreWorkLogRequest $request, Site $site): JsonResponse
    {
        $data = $request->validated();
        unset($data['screenshot']); // the uploaded file is handled below, not mass-assigned
        $data['performed_at'] ??= now();

        if ($request->hasFile('screenshot')) {
            $path = $request->file('screenshot')?->store('worklogs', 'public');

            if (is_string($path)) {
                $data['screenshot_path'] = $path;
            }
        }

        $log = $site->workLogs()->create($data);

        return WorkLogResource::make($log)->response()->setStatusCode(201);
    }

    public function destroy(WorkLog $workLog): JsonResponse
    {
        $workLog->delete();

        return response()->json(null, 204);
    }
}
