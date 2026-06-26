<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agency;
use App\Models\MetricSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data retention (CLAUDE.md §5): prune normalized snapshots older than an agency's
 * configured window so stored data doesn't grow without bound. Two safety rules:
 *  - Null/0 retention = keep everything (never prune; the default).
 *  - The most recent snapshot per source is ALWAYS kept, so a source never goes blank
 *    even if it hasn't synced within the window.
 * Frozen reports keep their own resolved copy, so pruning never changes a generated report.
 */
final class SnapshotRetentionService
{
    /** The age cutoff for an agency, or null when retention is off. */
    public function cutoffFor(Agency $agency): ?CarbonImmutable
    {
        $months = $agency->snapshot_retention_months;

        return $months !== null && $months > 0 ? CarbonImmutable::now()->subMonths($months) : null;
    }

    /**
     * Query of an agency's prunable snapshots (older than the cutoff, excluding the latest
     * per source), or null when retention is off. Tenant-safe via explicit agency_id.
     *
     * @return Builder<MetricSnapshot>|null
     */
    public function prunableQuery(Agency $agency): ?Builder
    {
        $cutoff = $this->cutoffFor($agency);

        if ($cutoff === null) {
            return null;
        }

        // Keep the most-recently-inserted snapshot of each source (MAX(id) ≈ latest), so
        // pruning never empties a source the dashboard still reads from.
        $keepIds = MetricSnapshot::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agency->id)
            ->selectRaw('MAX(id) as id')
            ->groupBy('data_source_id')
            ->pluck('id');

        return MetricSnapshot::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agency->id)
            ->where('period_end', '<', $cutoff)
            ->whereNotIn('id', $keepIds);
    }

    /**
     * What a prune would remove for an agency right now: snapshot count + approximate bytes.
     *
     * @return array{snapshots: int, bytes: int}
     */
    public function preview(Agency $agency): array
    {
        $query = $this->prunableQuery($agency);

        if ($query === null) {
            return ['snapshots' => 0, 'bytes' => 0];
        }

        $row = (clone $query)
            ->selectRaw('COUNT(*) as snapshots, COALESCE(SUM(LENGTH(payload)), 0) as bytes')
            ->first();

        $snapshots = $row?->getAttribute('snapshots');
        $bytes = $row?->getAttribute('bytes');

        return [
            'snapshots' => is_numeric($snapshots) ? (int) $snapshots : 0,
            'bytes' => is_numeric($bytes) ? (int) $bytes : 0,
        ];
    }

    /** Prune one agency's old snapshots; returns how many were deleted. */
    public function pruneAgency(Agency $agency): int
    {
        $query = $this->prunableQuery($agency);

        if ($query === null) {
            return 0;
        }

        $deleted = $query->delete();

        return is_numeric($deleted) ? (int) $deleted : 0;
    }

    /** Prune every agency that has retention configured; returns the total deleted. */
    public function pruneAll(): int
    {
        $deleted = 0;

        Agency::query()
            ->whereNotNull('snapshot_retention_months')
            ->where('snapshot_retention_months', '>', 0)
            ->each(function (Agency $agency) use (&$deleted): void {
                $deleted += $this->pruneAgency($agency);
            });

        return $deleted;
    }
}
