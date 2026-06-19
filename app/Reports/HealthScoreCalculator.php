<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * Computes the 0–100 health score (CLAUDE.md §10.5) from the available metric
 * bags. Combines uptime, updates-current, security and performance signals, and
 * **re-weights when a signal is missing** — a client is never penalised for a
 * source they don't have. With no signals at all it returns 100 (all-clear).
 *
 * @phpstan-type MetricBags array<string, array<array-key, mixed>>
 */
final class HealthScoreCalculator
{
    /**
     * @param  MetricBags  $bags  source key => normalized metric bag
     */
    public function calculate(array $bags): int
    {
        $weighted = 0.0;
        $weightSum = 0.0;

        $add = function (float $weight, ?float $score) use (&$weighted, &$weightSum): void {
            if ($score !== null) {
                $weighted += $weight * $score;
                $weightSum += $weight;
            }
        };

        $add(0.30, $this->uptimeScore($bags));
        $add(0.25, $this->updatesScore($bags));
        $add(0.25, $this->securityScore($bags));
        $add(0.20, $this->performanceScore($bags));

        if ($weightSum === 0.0) {
            return 100;
        }

        return (int) round($weighted / $weightSum);
    }

    /**
     * @param  MetricBags  $bags
     */
    private function uptimeScore(array $bags): ?float
    {
        $value = $this->metric($bags, 'betteruptime', 'betteruptime.uptime_percent');

        return $value === null ? null : $this->clamp($value, 0, 100);
    }

    /**
     * Each pending update costs 5 points (capped at 0).
     *
     * @param  MetricBags  $bags
     */
    private function updatesScore(array $bags): ?float
    {
        $value = $this->metric($bags, 'mainwp', 'mainwp.updates_available');

        return $value === null ? null : $this->clamp(100 - $value * 5, 0, 100);
    }

    /**
     * @param  MetricBags  $bags
     */
    private function securityScore(array $bags): ?float
    {
        $value = $this->metric($bags, 'mainwp', 'mainwp.ssl_expiring');

        return $value === null ? null : ($value > 0 ? 60.0 : 100.0);
    }

    /**
     * Cloudflare cache ratio (0–1) as a performance proxy.
     *
     * @param  MetricBags  $bags
     */
    private function performanceScore(array $bags): ?float
    {
        $value = $this->metric($bags, 'cloudflare', 'cloudflare.cache_ratio');

        return $value === null ? null : $this->clamp($value * 100, 0, 100);
    }

    /**
     * @param  MetricBags  $bags
     */
    private function metric(array $bags, string $source, string $key): ?float
    {
        $bag = $bags[$source] ?? null;

        if (! is_array($bag)) {
            return null;
        }

        $value = $bag[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
