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
        $sendDay = $cadence === ScheduleCadence::Monthly && $request->filled('send_day') ? $request->integer('send_day') : null;

        // One active schedule per definition: replace any existing one so switching
        // cadence (or re-enabling) never leaves a stale schedule behind.
        Schedule::query()->where('report_definition_id', $definition->id)->delete();

        // A throwaway instance computes the first run from the cadence + designated day.
        $nextRun = (new Schedule(['cadence' => $cadence, 'send_day' => $sendDay]))->nextRunAfter(CarbonImmutable::now());

        $schedule = Schedule::query()->create([
            'report_definition_id' => $definition->id,
            'cadence' => $cadence,
            'send_day' => $sendDay,
            'next_run_at' => $nextRun,
        ]);

        return ScheduleResource::make($schedule)->response()->setStatusCode(201);
    }

    public function destroy(Schedule $schedule): JsonResponse
    {
        // Tenant-scoped by the global AgencyScope on the model binding.
        $schedule->delete();

        return response()->json(['message' => 'Schedule deleted.']);
    }
}
