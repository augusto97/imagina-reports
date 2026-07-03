<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Scopes\AgencyScope;
use App\Reports\ReportGenerator;
use App\Services\Platform\Entitlements;
use App\Services\SyncService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates a scheduled report for the just-ended period and queues its delivery
 * (CLAUDE.md §3.1/§5). Queue-safe and tenant-bound.
 */
final class RunScheduledReportJob implements ShouldQueue
{
    use Queueable;

    /** Refreshing every source + two Claude calls + block resolution needs headroom (PERF-1). */
    public int $timeout = 600;

    /** tries=1 — this job generates a report and queues its delivery (neither idempotent), so
     *  a retry would produce a duplicate report and duplicate client emails. */
    public int $tries = 1;

    public function __construct(public readonly int $scheduleId) {}

    public function handle(ReportGenerator $generator, SyncService $sync, TenantContext $tenant, Entitlements $entitlements): void
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
        $requestedMetrics = array_values($definition->requested_metrics ?? []);

        $tenant->actingAs($schedule->agency_id, function () use ($generator, $sync, $definition, $period, $requestedMetrics): void {
            // SYNC before GENERATE (CLAUDE.md §3.1, FUN-1): the cron used to generate straight
            // from whatever snapshots happened to exist, so automatic reports were built on stale
            // or missing data (blocks silently hidden). Refresh each of the site's sources for the
            // period first. Connectors catch their own errors and record partial/failed snapshots,
            // so one failing source never aborts the run — worst case its block is hidden.
            foreach ($definition->site?->dataSources()->get() ?? [] as $source) {
                try {
                    $sync->sync($source, $period, $requestedMetrics);
                } catch (Throwable $e) {
                    Log::warning('Scheduled sync failed for a source; continuing.', [
                        'agency_id' => $source->agency_id,
                        'data_source_id' => $source->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $report = $generator->generate($definition, $period);
            DeliverReportJob::dispatch($report->id);
        });
    }
}
