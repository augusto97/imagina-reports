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
        $months = $this->effectiveMonths($agency);

        return $months !== null && $months > 0 ? CarbonImmutable::now()->subMonths($months) : null;
    }

    /**
     * The effective retention (months) for an agency — a PLATFORM setting: the plan's
     * value, overridable per-agency via `plan_overrides['retention_months']`, falling
     * back to the legacy `agencies.snapshot_retention_months` column. Null = keep forever.
     */
    public function effectiveMonths(Agency $agency): ?int
    {
        $overrides = $agency->plan_overrides ?? [];
        if (array_key_exists('retention_months', $overrides)) {
            $value = $overrides['retention_months'];

            return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
        }

        $planMonths = $agency->plan?->retention_months;
        if ($planMonths !== null) {
            return $planMonths > 0 ? $planMonths : null;
        }

        $legacy = $agency->snapshot_retention_months;

        return $legacy !== null && $legacy > 0 ? $legacy : null;
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

    /** Prune every agency whose effective retention is set; returns the total deleted. */
    public function pruneAll(): int
    {
        $deleted = 0;

        Agency::query()->with('plan')->each(function (Agency $agency) use (&$deleted): void {
            if ($this->effectiveMonths($agency) !== null) {
                $deleted += $this->pruneAgency($agency);
            }
        });

        return $deleted;
    }
}
