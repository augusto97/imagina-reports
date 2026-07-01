<?php

declare(strict_types=1);

namespace App\Reports;

use App\Connectors\Period;
use App\Enums\WorkLogStatus;
use App\Models\Site;
use App\Models\WorkLog;
use Illuminate\Support\Collection;

/**
 * Turns a site's work logs (the daily "what we did" entries) into a normalized
 * `worklog` metric bag for the period (CLAUDE.md §11.5). This is what lets a report
 * prove the hourly support paid off: hours invested, task count, a per-category
 * breakdown, and hours-used-vs-contracted-plan. Aggregated here (not over raw rows
 * at render), consistent with the snapshot model (§3.3).
 *
 * @phpstan-type WorkLogBag array<string, mixed>
 */
final class WorkLogMetrics
{
    /**
     * @return WorkLogBag keyed `worklog.*`, or [] when there's nothing to report.
     */
    public function forSite(Site $site, Period $period): array
    {
        /** @var Collection<int, WorkLog> $logs */
        $logs = WorkLog::query()
            ->where('site_id', $site->id)
            ->where('status', WorkLogStatus::Done->value)
            ->whereBetween('performed_at', [$period->start, $period->end])
            ->get();

        $planHours = $site->plan_hours !== null ? (float) $site->plan_hours : null;

        if ($logs->isEmpty() && $planHours === null) {
            return [];
        }

        $minutes = 0;
        foreach ($logs as $log) {
            $minutes += $log->minutes ?? 0;
        }
        $hours = round($minutes / 60, 1);

        $bag = [
            'worklog.minutes' => $minutes,
            'worklog.hours' => $hours,
            'worklog.tasks' => $logs->count(),
            'worklog.by_category' => $this->byCategory($logs),
        ];

        if ($planHours !== null) {
            $bag['worklog.plan_hours'] = $planHours;
            // Rich shape consumed by the "goal" block (value vs target).
            $bag['worklog.hours_vs_plan'] = ['value' => $hours, 'target' => $planHours];
        }

        return $bag;
    }

    /**
     * Hours grouped by task category (uncategorized → "Sin categoría"), for the
     * breakdown chart. Rows are `{label, value}` (value in hours).
     *
     * @param  Collection<int, WorkLog>  $logs
     * @return list<array{label: string, value: int|float}>
     */
    private function byCategory(Collection $logs): array
    {
        $totals = [];

        foreach ($logs as $log) {
            $label = $log->category ?? 'Sin categoría';
            $totals[$label] = ($totals[$label] ?? 0) + ($log->minutes ?? 0);
        }

        arsort($totals);

        $rows = [];
        foreach ($totals as $label => $minutes) {
            $rows[] = ['label' => (string) $label, 'value' => round($minutes / 60, 1)];
        }

        return $rows;
    }
}
