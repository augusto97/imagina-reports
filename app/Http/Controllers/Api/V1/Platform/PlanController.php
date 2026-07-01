<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Plans CRUD for the platform panel (SaaS Fase 1). Platform-admin only (middleware).
 */
final class PlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::query()->orderBy('sort')->orderBy('id')->get());
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        $data = $request->validated();
        $name = is_string($data['name'] ?? null) ? $data['name'] : 'Plan';
        $slugSource = is_string($data['slug'] ?? null) && $data['slug'] !== '' ? $data['slug'] : $name;
        $data['slug'] = Str::slug($slugSource);

        $plan = Plan::query()->create($data);

        return PlanResource::make($plan)->response()->setStatusCode(201);
    }

    public function update(StorePlanRequest $request, Plan $plan): PlanResource
    {
        $data = $request->validated();
        if (is_string($data['slug'] ?? null) && $data['slug'] !== '') {
            $data['slug'] = Str::slug($data['slug']);
        } else {
            unset($data['slug']);
        }

        $plan->update($data);

        return PlanResource::make($plan);
    }

    public function destroy(Plan $plan): JsonResponse
    {
        // Agencies keep working; their plan_id is nulled by the FK (unlimited-ish until reassigned).
        $plan->delete();

        return response()->json(null, 204);
    }
}
