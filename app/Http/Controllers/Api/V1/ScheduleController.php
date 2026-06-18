<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ScheduleCadence;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\ReportDefinition;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ScheduleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ScheduleResource::collection(Schedule::query()->latest()->get());
    }

    public function store(StoreScheduleRequest $request): JsonResponse
    {
        // Enforce that the definition belongs to this agency (scoped 404 otherwise).
        $definition = ReportDefinition::query()->findOrFail($request->integer('report_definition_id'));

        $cadence = ScheduleCadence::from($request->string('cadence')->toString());

        $schedule = Schedule::query()->create([
            'report_definition_id' => $definition->id,
            'cadence' => $cadence,
            'next_run_at' => $cadence->nextRun(CarbonImmutable::now()),
        ]);

        return ScheduleResource::make($schedule)->response()->setStatusCode(201);
    }
}
