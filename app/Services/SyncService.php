<?php

declare(strict_types=1);

namespace App\Services;

use App\Connectors\ConnectorRegistry;
use App\Connectors\MetricSet;
use App\Connectors\Period;
use App\Enums\DataSourceStatus;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use Illuminate\Support\Facades\Date;

/**
 * The SYNC stage (CLAUDE.md §3.1): resolve a data source's connector, fetch the
 * requested metrics aggregated at the source (§3.3), and persist the normalized
 * MetricSet as a snapshot. Idempotent — re-syncing a period upserts the snapshot.
 */
final readonly class SyncService
{
    public function __construct(private ConnectorRegistry $registry) {}

    /**
     * @param  list<string>  $requestedMetrics  Metric keys referenced by active report
     *                                          definitions; empty lets the connector decide.
     */
    public function sync(DataSource $source, Period $period, array $requestedMetrics = []): MetricSnapshot
    {
        $connector = $this->registry->for($source);

        // Connectors catch their own API errors and return partial/failed sets (§7),
        // so a single failing source never breaks the pipeline.
        $metricSet = $connector->fetch($source, $period, $requestedMetrics);

        $snapshot = $this->store($source, $period, $metricSet);

        $this->recordOutcome($source, $metricSet);

        return $snapshot;
    }

    private function store(DataSource $source, Period $period, MetricSet $metricSet): MetricSnapshot
    {
        return MetricSnapshot::query()->updateOrCreate(
            [
                'data_source_id' => $source->id,
                'period_start' => $period->start,
                'period_end' => $period->end,
            ],
            [
                'agency_id' => $source->agency_id,
                'payload' => $metricSet->toArray(),
                'status' => $metricSet->status,
                'captured_at' => Date::now(),
            ],
        );
    }

    private function recordOutcome(DataSource $source, MetricSet $metricSet): void
    {
        $source->forceFill([
            'status' => $metricSet->isFailed() ? DataSourceStatus::Error : DataSourceStatus::Ok,
            'last_synced_at' => Date::now(),
            'last_error' => $metricSet->error,
        ])->save();
    }
}
