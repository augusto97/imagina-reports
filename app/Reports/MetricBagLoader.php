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
            // Find the snapshot whose window OVERLAPS the requested period (not strictly
            // contained — snapshots store end-of-month timestamps like 23:59:59 while a
            // period end is often a bare midnight date; strict containment dropped the very
            // month just synced, §3.1: use last snapshot).
            //
            // PREFER an exact-range match (same start+end day) so a report over an arbitrary
            // span — a quarter, a full year, a custom range — uses the snapshot SYNCED FOR
            // THAT SPAN (the connector aggregated it at source, §3.3) instead of a stray
            // monthly snapshot that merely overlaps. Only when there's no exact match do we
            // fall back to the latest overlapping snapshot.
            $overlapping = MetricSnapshot::query()
                ->where('data_source_id', $source->id)
                ->where('period_start', '<=', $period->end)
                ->where('period_end', '>=', $period->start);

            $snapshot = (clone $overlapping)
                ->whereDate('period_start', $period->start->toDateString())
                ->whereDate('period_end', $period->end->toDateString())
                ->orderByDesc('captured_at')
                ->first()
                ?? $overlapping->orderByDesc('period_end')->first();

            if ($snapshot === null) {
                continue;
            }

            $metrics = $snapshot->payload['metrics'] ?? [];

            if (! is_array($metrics)) {
                continue;
            }

            if ($source->type === DataSourceType::MainWp) {
                // The connector now reports the real applied-updates count from the Pro
                // Reports activity log. Only fall back to the snapshot-diff proxy when that
                // log was empty (e.g. the site's updates aren't tracked) so the KPI still
                // has a meaningful value.
                $applied = $metrics['mainwp.updates_applied'] ?? null;

                if ($applied === null || (is_numeric($applied) && (int) $applied === 0)) {
                    $delta = $this->maintenance->forDataSource($source->id, $period);

                    if ($delta !== null && $delta->updatesApplied > 0) {
                        $metrics['mainwp.updates_applied'] = $delta->updatesApplied;
                    }
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
