<?php

declare(strict_types=1);

namespace App\Reports;

use App\Connectors\Support\ParsesValues;
use App\Enums\UpsellType;
use App\Reports\Support\ReadsMetricBags;
use Illuminate\Support\Arr;

/**
 * Detects upsell opportunities (CLAUDE.md §13): commercial signals derived from the
 * resolved metrics that justify proposing a plan upgrade or a new service. Pure and
 * config-driven (config/upsell.php) — reads only pre-aggregated snapshot values plus
 * which sources are connected, and returns value objects so the caller decides how
 * to surface them (internal alert + `upsell.detected` webhook).
 *
 * @phpstan-type Bags array<string, array<array-key, mixed>>
 */
final class UpsellDetector
{
    use ParsesValues, ReadsMetricBags;

    /**
     * @param  Bags  $current
     * @param  Bags  $previous
     * @param  list<string>  $connectedSources  Connected data-source type values for the site.
     * @return list<UpsellOpportunity>
     */
    public function detect(array $current, array $previous, array $connectedSources): array
    {
        $opportunities = [];

        foreach ([
            'traffic_growth' => UpsellType::TrafficGrowth,
            'sales_growth' => UpsellType::SalesGrowth,
        ] as $rule => $type) {
            $growth = $this->growth($current, $previous, $rule, $type);

            if ($growth !== null) {
                $opportunities[] = $growth;
            }
        }

        $security = $this->securityPressure($current);

        if ($security !== null) {
            $opportunities[] = $security;
        }

        foreach ($this->coverageGaps($connectedSources) as $gap) {
            $opportunities[] = $gap;
        }

        return $opportunities;
    }

    /**
     * @param  Bags  $current
     * @param  Bags  $previous
     */
    private function growth(array $current, array $previous, string $rule, UpsellType $type): ?UpsellOpportunity
    {
        $config = $this->arrayOf(config("upsell.{$rule}"));
        $metric = $this->toStr($config['metric'] ?? '');

        if ($metric === '') {
            return null;
        }

        $cur = $this->metricValue($current, $metric);
        $prev = $this->metricValue($previous, $metric);

        if ($cur === null || $prev === null || $prev < $this->toFloat($config['min_previous'] ?? 0)) {
            return null;
        }

        $change = $this->changePercent($cur, $prev);

        return $change >= $this->toFloat($config['growth_pct'] ?? 0)
            ? new UpsellOpportunity($type, [
                'metric' => $metric,
                'current' => $cur,
                'previous' => $prev,
                'change_percent' => round($change, 2),
            ])
            : null;
    }

    /**
     * @param  Bags  $current
     */
    private function securityPressure(array $current): ?UpsellOpportunity
    {
        $config = $this->arrayOf(config('upsell.security_pressure'));

        $total = 0.0;
        $found = false;

        foreach ($this->arrayOf($config['metrics'] ?? []) as $rawMetric) {
            $value = $this->metricValue($current, $this->toStr($rawMetric));

            if ($value !== null) {
                $total += $value;
                $found = true;
            }
        }

        return $found && $total >= $this->toFloat($config['min_total'] ?? 0)
            ? new UpsellOpportunity(UpsellType::SecurityHardening, ['attacks' => $total])
            : null;
    }

    /**
     * @param  list<string>  $connected
     * @return list<UpsellOpportunity>
     */
    private function coverageGaps(array $connected): array
    {
        $config = $this->arrayOf(config('upsell.coverage_gaps'));
        $opportunities = [];

        $uptimeSource = $this->toStr(Arr::get($config, 'uptime.source'));

        if ($uptimeSource !== '' && ! in_array($uptimeSource, $connected, true)) {
            $opportunities[] = new UpsellOpportunity(UpsellType::UptimeMonitoring, ['missing_source' => $uptimeSource]);
        }

        $securitySources = array_values(array_filter(
            array_map($this->toStr(...), $this->arrayOf(Arr::get($config, 'security.sources'))),
            static fn (string $source): bool => $source !== '',
        ));

        if ($securitySources !== [] && array_intersect($securitySources, $connected) === []) {
            $opportunities[] = new UpsellOpportunity(UpsellType::SecurityProtection, ['missing_sources' => $securitySources]);
        }

        return $opportunities;
    }
}
