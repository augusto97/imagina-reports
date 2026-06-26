<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

/**
 * Slices a stored dataset (bounded, pre-aggregated rows) into the shape the shared
 * BlockRenderer already consumes — a `[{label, value}]` table/series for blocks with a
 * breakdown, or a single scalar for a KPI without one (CLAUDE.md §10 dashboards).
 *
 * Pure and stateless: filtering/grouping/sorting happens in PHP over the small top-N
 * dataset already aggregated at the source (§3.3) — this is NOT a query engine over raw
 * rows. Filters cascade: page/dashboard filters are the base, the block's own filters
 * override per dimension (block wins).
 */
final class DatasetEngine
{
    /**
     * @param  array<array-key, mixed>  $rows  Dataset rows (each an associative array);
     *                                         non-array entries from a malformed snapshot are skipped defensively.
     * @param  list<DatasetFilter>  $pageFilters
     * @return list<array{label: string, value: int|float}>|int|float
     */
    public function shape(array $rows, DatasetQuery $query, array $pageFilters = []): array|int|float
    {
        $filters = $this->cascade($pageFilters, $query->filters);

        $filtered = [];
        foreach ($rows as $row) {
            if (is_array($row) && $this->passes($row, $filters)) {
                $filtered[] = $row;
            }
        }

        if ($query->breakdown === null) {
            return $this->aggregate($filtered, $query->measure);
        }

        $grouped = [];
        foreach ($filtered as $row) {
            $label = array_key_exists($query->breakdown, $row) && is_scalar($row[$query->breakdown])
                ? (string) $row[$query->breakdown]
                : '—';
            $grouped[$label] = ($grouped[$label] ?? 0) + $this->measureValue($row, $query->measure);
        }

        $out = [];
        foreach ($grouped as $label => $value) {
            $out[] = ['label' => (string) $label, 'value' => $value];
        }

        usort($out, function (array $a, array $b) use ($query): int {
            $cmp = $query->sortBy === 'label'
                ? strcmp($a['label'], $b['label'])
                : ($a['value'] <=> $b['value']);

            return $query->sortDir === 'asc' ? $cmp : -$cmp;
        });

        if ($query->limit !== null) {
            $out = array_slice($out, 0, $query->limit);
        }

        return $out;
    }

    /**
     * Merge a base filter set with overrides, keeping one condition per dimension
     * (the override wins). Used for the page→block cascade and the all→page cascade.
     *
     * @param  list<DatasetFilter>  $base
     * @param  list<DatasetFilter>  $override
     * @return list<DatasetFilter>
     */
    public function cascade(array $base, array $override): array
    {
        $byDimension = [];
        foreach ($base as $filter) {
            $byDimension[$filter->dimension] = $filter;
        }
        foreach ($override as $filter) {
            $byDimension[$filter->dimension] = $filter;
        }

        return array_values($byDimension);
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @param  list<DatasetFilter>  $filters
     */
    private function passes(array $row, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (! $filter->matches($row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     */
    private function aggregate(array $rows, ?string $measure): int|float
    {
        $sum = 0;
        foreach ($rows as $row) {
            $sum += $this->measureValue($row, $measure);
        }

        return $sum;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function measureValue(array $row, ?string $measure): int|float
    {
        if ($measure !== null) {
            return isset($row[$measure]) && is_numeric($row[$measure]) ? $row[$measure] + 0 : 0;
        }

        // No measure named: fall back to the first numeric column.
        foreach ($row as $value) {
            if (is_numeric($value)) {
                return $value + 0;
            }
        }

        return 0;
    }
}
