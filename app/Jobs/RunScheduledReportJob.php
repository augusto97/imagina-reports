<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Scopes\AgencyScope;
use App\Reports\ReportGenerator;
use App\Services\Platform\Entitlements;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Generates a scheduled report for the just-ended period and queues its delivery
 * (CLAUDE.md §3.1/§5). Queue-safe and tenant-bound.
 */
final class RunScheduledReportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $scheduleId) {}

    public function handle(ReportGenerator $generator, TenantContext $tenant, Entitlements $entitlements): void
    {
        $schedule = Schedule::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->with('definition')
            ->find($this->scheduleId);

        if ($schedule === null || $schedule->definition === null) {
            return;
        }

        // The scheduler bypasses the web middleware, so enforce the plan here too: a
        // suspended (unpaid) agency gets no scheduled reports generated or emailed, and
        // schedules count against max_reports_per_month like manual generation does.
        $agency = Agency::query()->withoutGlobalScopes()->find($schedule->agency_id);

        if ($agency === null || $agency->isSuspended()) {
            return;
        }

        if (! $entitlements->canGenerateReport($agency)) {
            Log::info('Scheduled report skipped: monthly report limit reached.', ['agency_id' => $agency->id, 'schedule_id' => $schedule->id]);

            return;
        }

        $period = $schedule->cadence->periodFor(CarbonImmutable::now());
        $definition = $schedule->definition;

        $tenant->actingAs($schedule->agency_id, function () use ($generator, $definition, $period): void {
            $report = $generator->generate($definition, $period);
            DeliverReportJob::dispatch($report->id);
        });
    }
}
