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

    public function __construct(public readonly int $reportId) {}

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
