<?php

declare(strict_types=1);

namespace App\Reports\Support;

use Illuminate\Support\Str;

/**
 * Shared reads over the source-keyed metric-bag map (CLAUDE.md §10.1) used by the
 * intelligence services (anomaly + upsell detection). Keeps "read a scalar metric"
 * and "period-over-period percent change" in one place.
 *
 * @phpstan-type Bags array<string, array<array-key, mixed>>
 */
trait ReadsMetricBags
{
    /**
     * Read a scalar metric out of the bag map (keyed by source, then full metric
     * key). Returns null when the metric is absent so we never compare against a
     * phantom baseline.
     *
     * @param  Bags  $bags
     */
    private function metricValue(array $bags, string $key): ?float
    {
        $bag = $bags[Str::before($key, '.')] ?? null;

        if (! is_array($bag) || ! array_key_exists($key, $bag)) {
            return null;
        }

        $value = $bag[$key];

        return is_numeric($value) ? (float) $value : null;
    }

    private function changePercent(float $current, float $previous): float
    {
        if ($previous === 0.0) {
            return $current > 0.0 ? 100.0 : 0.0;
        }

        return ($current - $previous) / $previous * 100.0;
    }
}
