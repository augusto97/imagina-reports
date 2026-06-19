<?php

declare(strict_types=1);

namespace App\Reports;

use App\Connectors\Period;
use App\Models\MetricSnapshot;
use Illuminate\Support\Arr;

/**
 * Computes maintenance "work done" deltas by diffing snapshots (CLAUDE.md §9).
 * MainWP's API exposes current state, not history, so "X updates applied this
 * month" is derived here from the earliest vs latest snapshot in the period —
 * this is what replaces the MainWP Pro Reports extension.
 *
 * "Updates applied" is computed as the reduction in pending updates
 * (max(0, before − after)) — a proxy until per-item inventory diffing lands
 * (see PROGRESS Open Questions).
 */
final class MaintenanceDeltaCalculator
{
    private const DEFAULT_APPLIED_METRIC = 'mainwp.updates_available';

    /**
     * Diff the earliest and latest snapshots for a data source within the period.
     *
     * @param  list<string>  $trackedMetrics  Numeric metric keys to report net change for.
     */
    public function forDataSource(
        int $dataSourceId,
        Period $period,
        array $trackedMetrics = [self::DEFAULT_APPLIED_METRIC],
        string $appliedMetric = self::DEFAULT_APPLIED_METRIC,
    ): ?MaintenanceDelta {
        $snapshots = MetricSnapshot::query()
            ->where('data_source_id', $dataSourceId)
            ->where('period_start', '>=', $period->start)
            ->where('period_end', '<=', $period->end)
            ->orderBy('period_start')
            ->get();

        if ($snapshots->count() < 2) {
            return null;
        }

        $earliest = $snapshots->first();
        $latest = $snapshots->last();

        if ($earliest === null || $latest === null) {
            return null;
        }

        return $this->between($earliest, $latest, $trackedMetrics, $appliedMetric);
    }

    /**
     * @param  list<string>  $trackedMetrics
     */
    public function between(
        MetricSnapshot $earliest,
        MetricSnapshot $latest,
        array $trackedMetrics = [self::DEFAULT_APPLIED_METRIC],
        string $appliedMetric = self::DEFAULT_APPLIED_METRIC,
    ): MaintenanceDelta {
        $applied = (int) max(0, $this->numeric($earliest, $appliedMetric) - $this->numeric($latest, $appliedMetric));

        $deltas = [];
        foreach ($trackedMetrics as $metric) {
            $deltas[$metric] = $this->numeric($latest, $metric) - $this->numeric($earliest, $metric);
        }

        return new MaintenanceDelta(
            $earliest->captured_at->toImmutable(),
            $latest->captured_at->toImmutable(),
            $applied,
            $deltas,
        );
    }

    private function numeric(MetricSnapshot $snapshot, string $metricKey): float
    {
        // Metric keys contain dots (e.g. "mainwp.updates_available"), so index the
        // metrics map directly rather than via Arr::get's dot notation.
        $metrics = $snapshot->payload['metrics'] ?? [];
        $value = is_array($metrics) ? ($metrics[$metricKey] ?? 0) : 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
