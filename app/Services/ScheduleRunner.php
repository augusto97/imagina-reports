<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RunScheduledReportJob;
use App\Models\Schedule;
use App\Models\Scopes\AgencyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Finds due schedules and enqueues their generation (CLAUDE.md §5). Runs from the
 * single cron via the reports:run-schedules command. Advances next_run_at on dispatch
 * so a schedule is never picked twice before its job runs.
 */
final readonly class ScheduleRunner
{
    public function dispatchDue(): int
    {
        $due = Schedule::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('next_run_at', '<=', Date::now())
            ->get();

        foreach ($due as $schedule) {
            RunScheduledReportJob::dispatch($schedule->id);

            $schedule->forceFill([
                'next_run_at' => $schedule->cadence->nextRun(CarbonImmutable::now()),
            ])->save();
        }

        return $due->count();
    }
}
