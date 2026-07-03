<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Connectors\Period;
use App\Models\DataSource;
use App\Models\Scopes\AgencyScope;
use App\Services\SyncService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued SYNC of a single data source for a period (CLAUDE.md §3.1). Idempotent
 * and safely re-runnable (§6) — the SyncService upserts the snapshot.
 *
 * The data source is loaded without the AgencyScope (the queue has no bound
 * tenant) and processed inside its agency's tenant context.
 */
final class SyncSourceJob implements ShouldQueue
{
    use Queueable;

    /** A connector fetch (several aggregated API calls) can run past the 60s default (PERF-1). */
    public int $timeout = 180;

    /** Idempotent (the SyncService upserts the snapshot), so a transient API/network failure is
     *  safe to retry — unlike generate/deliver. Backoff spaces the attempts. */
    public int $tries = 3;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * @param  list<string>  $requestedMetrics
     */
    public function __construct(
        public readonly int $dataSourceId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly array $requestedMetrics = [],
    ) {}

    public function handle(SyncService $service, TenantContext $tenant): void
    {
        $source = DataSource::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->find($this->dataSourceId);

        if ($source === null) {
            return;
        }

        $period = new Period($this->periodStart, $this->periodEnd);

        $tenant->actingAs(
            $source->agency_id,
            fn (): mixed => $service->sync($source, $period, $this->requestedMetrics),
        );
    }
}
