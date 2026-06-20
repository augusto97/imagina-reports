<?php

declare(strict_types=1);

namespace App\Reports;

use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use App\Models\MetricSnapshot;

/**
 * Loads the source-key → metric-bag map for a site over a period from the stored
 * snapshots (CLAUDE.md §3.1 — the GENERATE stage never touches a live API). Shared
 * by the ReportGenerator (current period) and the AnomalyDetector wiring (current
 * vs previous period). A computed `mainwp.updates_applied` is injected from the
 * maintenance delta (§9) so the "updates applied" KPI has a value.
 *
 * @phpstan-type Bags array<string, array<array-key, mixed>>
 */
final readonly class MetricBagLoader
{
    public function __construct(private MaintenanceDeltaCalculator $maintenance) {}

    /**
     * @return Bags
     */
    public function forSite(int $siteId, Period $period): array
    {
        $bags = [];

        $sources = DataSource::query()->where('site_id', $siteId)->get();

        foreach ($sources as $source) {
            // Match the most recent snapshot whose window OVERLAPS the requested period,
            // not one strictly contained in it. Snapshots are stored with end-of-month
            // timestamps (e.g. 23:59:59) while a report period end is often a bare date
            // (midnight), so strict containment dropped the very month just synced — the
            // root cause of "the report shows no metrics" (CLAUDE.md §3.1: use last snapshot).
            $snapshot = MetricSnapshot::query()
                ->where('data_source_id', $source->id)
                ->where('period_start', '<=', $period->end)
                ->where('period_end', '>=', $period->start)
                ->orderByDesc('period_end')
                ->first();

            if ($snapshot === null) {
                continue;
            }

            $metrics = $snapshot->payload['metrics'] ?? [];

            if (! is_array($metrics)) {
                continue;
            }

            if ($source->type === DataSourceType::MainWp) {
                $delta = $this->maintenance->forDataSource($source->id, $period);

                if ($delta !== null) {
                    $metrics['mainwp.updates_applied'] = $delta->updatesApplied;
                }
            }

            $bags[$source->type->value] = $metrics;
        }

        return $bags;
    }

    /**
     * The metric bags of the most recent snapshot that ended strictly before this
     * period — the basis for "vs previous period" comparisons (§11.5). Robust to
     * months of different lengths (the previous calendar period is whatever snapshot
     * precedes the current one), unlike a fixed-length shift.
     *
     * @return Bags
     */
    public function previousForSite(int $siteId, Period $period): array
    {
        $bags = [];

        $sources = DataSource::query()->where('site_id', $siteId)->get();

        foreach ($sources as $source) {
            $snapshot = MetricSnapshot::query()
                ->where('data_source_id', $source->id)
                ->where('period_end', '<', $period->start)
                ->orderByDesc('period_end')
                ->first();

            if ($snapshot === null) {
                continue;
            }

            $metrics = $snapshot->payload['metrics'] ?? [];

            if (is_array($metrics)) {
                $bags[$source->type->value] = $metrics;
            }
        }

        return $bags;
    }
}
