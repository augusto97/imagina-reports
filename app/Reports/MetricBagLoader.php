<?php

declare(strict_types=1);

namespace App\Reports;

use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use Carbon\CarbonImmutable;

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

            // The fallback takes a snapshot of about the same length, or one inside the period
            // (the daily syncs the maintenance delta compares) — never a LONGER one: a
            // quarter's numbers must not be shown under a month's label.
            $snapshot = (clone $overlapping)
                ->whereDate('period_start', $period->start->toDateString())
                ->whereDate('period_end', $period->end->toDateString())
                ->orderByDesc('captured_at')
                ->first()
                ?? $overlapping->orderByDesc('period_end')->get()->first(fn (MetricSnapshot $candidate): bool => $this->sameLength($candidate, $period) || $this->inside($candidate, $period));

            if ($snapshot === null) {
                continue;
            }

            $metrics = $snapshot->payload['metrics'] ?? [];

            if (! is_array($metrics)) {
                continue;
            }
            $metrics = self::withoutMisleading($metrics);

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
     * The metric bags of the period just before this one — the basis for "vs previous period"
     * comparisons (§11.5). The previous calendar month for a whole month (so June compares with
     * May whatever their lengths), else the same number of days right before. Falls back to the
     * latest earlier snapshot of about the same length that has data; never to a period of a
     * different length (a quarter compared with one month read as +200 %).
     *
     * @return Bags
     */
    public function previousForSite(int $siteId, Period $period): array
    {
        $bags = [];
        $previous = self::previousPeriod($period);

        $sources = DataSource::query()->where('site_id', $siteId)->get();

        foreach ($sources as $source) {
            $earlier = MetricSnapshot::query()
                ->where('data_source_id', $source->id)
                ->where('period_end', '<', $period->start);

            $snapshot = (clone $earlier)
                ->whereDate('period_start', $previous->start->toDateString())
                ->whereDate('period_end', $previous->end->toDateString())
                ->orderByDesc('captured_at')
                ->first()
                ?? $earlier->orderByDesc('period_end')->limit(12)->get()->first(
                    fn (MetricSnapshot $candidate): bool => $this->sameLength($candidate, $period) && $this->metricsOf($candidate) !== [],
                );

            if ($snapshot === null) {
                continue;
            }

            $metrics = $this->metricsOf($snapshot);
            if ($metrics !== []) {
                $bags[$source->type->value] = $metrics;
            }
        }

        return $bags;
    }

    /** The previous calendar month for a whole month; otherwise the same span right before. */
    public static function previousPeriod(Period $period): Period
    {
        $start = $period->start->startOfDay();
        $end = $period->end->startOfDay();
        if ($start->day === 1 && $end->isSameDay($start->endOfMonth())) {
            $before = $start->subMonthNoOverflow();

            return new Period($before->startOfMonth(), $before->endOfMonth());
        }

        return $period->previous();
    }

    /** A snapshot that starts and ends within the period. */
    private function inside(MetricSnapshot $snapshot, Period $period): bool
    {
        return CarbonImmutable::parse($snapshot->period_start)->startOfDay()->greaterThanOrEqualTo($period->start->startOfDay())
            && CarbonImmutable::parse($snapshot->period_end)->startOfDay()->lessThanOrEqualTo($period->end->startOfDay());
    }

    /** Spans within three days of each other count as «the same kind of period». */
    private function sameLength(MetricSnapshot $snapshot, Period $period): bool
    {
        $span = (int) round(abs(CarbonImmutable::parse($snapshot->period_start)->startOfDay()->diffInDays(CarbonImmutable::parse($snapshot->period_end)->startOfDay())));
        $wanted = (int) round(abs($period->start->startOfDay()->diffInDays($period->end->startOfDay())));

        return abs($span - $wanted) <= 3;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function metricsOf(MetricSnapshot $snapshot): array
    {
        $metrics = $snapshot->payload['metrics'] ?? [];

        return is_array($metrics) ? self::withoutMisleading($metrics) : [];
    }

    /**
     * Drop values older versions stored under a misleading meaning: «malware found» was really
     * the number of Virusdie SCANS (the MainWP token counts scans), so it must not reach a report.
     *
     * @param  array<array-key, mixed>  $metrics
     * @return array<array-key, mixed>
     */
    public static function withoutMisleading(array $metrics): array
    {
        unset($metrics['mainwp.malware_found'], $metrics['virusdie.malware_found']);

        return $metrics;
    }
}
