<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnomalyAlertResource;
use App\Models\AnomalyAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The in-app anomaly alerts feed (CLAUDE.md §13): traffic drops and attack spikes
 * detected on report generation. Tenant-scoped by the global AgencyScope.
 */
final class AnomalyController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AnomalyAlertResource::collection(
            AnomalyAlert::query()->with('site')->latest()->limit(200)->get(),
        );
    }

    public function acknowledge(AnomalyAlert $anomaly): AnomalyAlertResource
    {
        $anomaly->forceFill(['acknowledged_at' => now()])->save();

        return AnomalyAlertResource::make($anomaly->load('site'));
    }

    public function destroy(AnomalyAlert $anomaly): JsonResponse
    {
        $anomaly->delete();

        return response()->json(null, 204);
    }
}
