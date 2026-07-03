<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Report;
use App\Models\Scopes\AgencyScope;
use App\Services\DeliveryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued DELIVER (CLAUDE.md §3.1): renders the PDF and emails the branded report.
 * Queue-safe — loads the report without the AgencyScope and runs inside its tenant.
 */
final class DeliverReportJob implements ShouldQueue
{
    use Queueable;

    /** PDF render (up to ~120s) + N emails can exceed the 60s default worker timeout (PERF-1).
     *  tries=1 — deliver() re-sends to every recipient, so a retry would duplicate client emails. */
    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly int $reportId)
    {
        // Route delivery to the dedicated, low-concurrency 'pdf' queue (PERF-2): each report
        // spins up a headless Chromium (~300-500 MB). On the 1st of the month hundreds of
        // these enqueue at once — capping the pdf queue's processes keeps RAM bounded so a
        // burst can't OOM the VPS. They just drain a bit more slowly (they're background work).
        $this->onQueue('pdf');
    }

    public function handle(DeliveryService $delivery, TenantContext $tenant): void
    {
        $report = Report::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->with('definition')
            ->find($this->reportId);

        if ($report === null) {
            return;
        }

        $tenant->actingAs($report->agency_id, function () use ($delivery, $report): void {
            $delivery->deliver($report);
        });
    }
}
