<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Schedule;
use App\Models\Scopes\AgencyScope;
use App\Reports\ReportGenerator;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generates a scheduled report for the just-ended period and queues its delivery
 * (CLAUDE.md §3.1/§5). Queue-safe and tenant-bound.
 */
final class RunScheduledReportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $scheduleId) {}

    public function handle(ReportGenerator $generator, TenantContext $tenant): void
    {
        $schedule = Schedule::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->with('definition')
            ->find($this->scheduleId);

        if ($schedule === null || $schedule->definition === null) {
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
