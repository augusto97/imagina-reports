<?php

declare(strict_types=1);

namespace App\Services;

use App\Connectors\ConnectorRegistry;
use App\Connectors\MetricSet;
use App\Connectors\Period;
use App\Enums\DataSourceStatus;
use App\Enums\SourceEvent;
use App\Jobs\NotifySourceEvent;
use App\Jobs\NotifySourceFailure;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Services\Notices\SourceNotifier;
use Illuminate\Support\Facades\Cache;
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

        return $this->record($source, $period, $metricSet);
    }

    /**
     * Persist an already-normalized MetricSet as the period's snapshot and record the
     * source outcome. Shared by the polled sync() above and the push ingest endpoint
     * (CLAUDE.md §9), so both paths produce identical snapshots.
     */
    public function record(DataSource $source, Period $period, MetricSet $metricSet): MetricSnapshot
    {
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
        $wasFailing = $source->status === DataSourceStatus::Error;

        $attributes = [
            'status' => $metricSet->isFailed() ? DataSourceStatus::Error : DataSourceStatus::Ok,
            'last_synced_at' => Date::now(),
            'last_error' => $metricSet->error,
        ];

        // Connector-reported source metadata (e.g. the agent plugin version) — merged onto
        // the source so the card can show it without re-testing. Merge (not replace) so a
        // failed/partial sync that reports nothing doesn't wipe a previously-known value.
        $before = $source->meta ?? [];
        $meta = $metricSet->meta !== [] ? array_merge($before, $metricSet->meta) : $before;

        // It just stopped working (Etapa 5): tell the agency once, not on every retry. A source
        // flapping between ok and error doesn't ping more than twice a day.
        $failed = ! $wasFailing && $metricSet->isFailed() && Cache::add('source-failed:'.$source->id, true, now()->addHours(12));
        if ($failed) {
            $meta['failure_notified'] = true;
        }

        // Data flows again after a failure the team was told about (by itself or after the
        // client reconnected): close the loop with a «works again».
        $recovered = ! $metricSet->isFailed() && ($meta['failure_notified'] ?? false) === true;
        if ($recovered) {
            unset($meta['failure_notified']);
        }

        if ($meta !== $before) {
            $attributes['meta'] = $meta;
        }

        $source->forceFill($attributes)->save();

        if ($failed) {
            NotifySourceFailure::dispatch($source->id);
        }
        if ($recovered) {
            NotifySourceEvent::dispatch(SourceEvent::Recovered, SourceNotifier::describe($source));
        }
    }
}
