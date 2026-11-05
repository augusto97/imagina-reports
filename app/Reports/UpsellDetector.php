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
 * Thresholds come from config and can be overridden per agency (Etapa 5: «umbrales reales»,
 * set from the Oportunidades screen).
 *
 * @phpstan-type Bags array<string, array<array-key, mixed>>
 * @phpstan-type Thresholds array{traffic_growth_pct: float, sales_growth_pct: float, security_min_total: float, plan_overuse_pct: float, slow_response_ms: float}
 */
final class UpsellDetector
{
    use ParsesValues, ReadsMetricBags;

    /** Threshold keys an agency can tune. */
    public const THRESHOLDS = ['traffic_growth_pct', 'sales_growth_pct', 'security_min_total', 'plan_overuse_pct', 'slow_response_ms'];

    /**
     * The effective thresholds: config defaults, overlaid with the agency's own.
     *
     * @param  array<array-key, mixed>  $overrides
     * @return Thresholds
     */
    public static function thresholds(array $overrides = []): array
    {
        $num = static function (string $key, mixed $default) use ($overrides): float {
            $value = $overrides[$key] ?? $default;

            return is_numeric($value) ? (float) $value : 0.0;
        };

        return [
            'traffic_growth_pct' => $num('traffic_growth_pct', config('upsell.traffic_growth.growth_pct', 40)),
            'sales_growth_pct' => $num('sales_growth_pct', config('upsell.sales_growth.growth_pct', 25)),
            'security_min_total' => $num('security_min_total', config('upsell.security_pressure.min_total', 500)),
            'plan_overuse_pct' => $num('plan_overuse_pct', config('upsell.plan_overuse.over_pct', 20)),
            'slow_response_ms' => $num('slow_response_ms', config('upsell.performance.response_ms', 1000)),
        ];
    }

    /**
     * @param  Bags  $current
     * @param  Bags  $previous
     * @param  list<string>  $connectedSources  Connected data-source type values for the site.
     * @param  array<array-key, mixed>  $overrides  The agency's own thresholds.
     * @param  list<array{met: bool}>  $goals  The site's goals measured this period.
     * @return list<UpsellOpportunity>
     */
    public function detect(array $current, array $previous, array $connectedSources, array $overrides = [], array $goals = []): array
    {
        $limits = self::thresholds($overrides);
        $opportunities = [];

        foreach ([
            'traffic_growth' => UpsellType::TrafficGrowth,
            'sales_growth' => UpsellType::SalesGrowth,
        ] as $rule => $type) {
            $growth = $this->growth($current, $previous, $rule, $type, $limits[$rule.'_pct']);

            if ($growth !== null) {
                $opportunities[] = $growth;
            }
        }

        $security = $this->securityPressure($current, $limits['security_min_total']);

        if ($security !== null) {
            $opportunities[] = $security;
        }

        foreach ([$this->planOveruse($current, $limits['plan_overuse_pct']), $this->slowSite($current, $limits['slow_response_ms']), $this->goalsReached($goals)] as $signal) {
            if ($signal !== null) {
                $opportunities[] = $signal;
            }
        }

        foreach ($this->coverageGaps($connectedSources) as $gap) {
            $opportunities[] = $gap;
        }

        return $opportunities;
    }

    /**
     * They use more support hours than they pay for: the plan no longer fits.
     *
     * @param  Bags  $current
     */
    private function planOveruse(array $current, float $overPct): ?UpsellOpportunity
    {
        $hours = $this->metricValue($current, 'worklog.hours');
        $plan = $this->metricValue($current, 'worklog.plan_hours');

        if ($hours === null || $plan === null || $plan <= 0 || $hours < $plan * (1 + $overPct / 100)) {
            return null;
        }

        return new UpsellOpportunity(UpsellType::PlanOveruse, [
            'hours' => $hours,
            'plan_hours' => $plan,
            'over_percent' => round(($hours / $plan - 1) * 100, 1),
        ]);
    }

    /**
     * @param  Bags  $current
     */
    private function slowSite(array $current, float $limitMs): ?UpsellOpportunity
    {
        $metric = $this->toStr(config('upsell.performance.metric', 'betteruptime.avg_response_time'));
        $ms = $metric !== '' ? $this->metricValue($current, $metric) : null;

        return $ms !== null && $limitMs > 0 && $ms >= $limitMs ? new UpsellOpportunity(UpsellType::Performance, ['response_ms' => $ms]) : null;
    }

    /**
     * Every goal met (two or more): the moment to raise them, with the plan that gets there.
     *
     * @param  list<array{met: bool}>  $goals
     */
    private function goalsReached(array $goals): ?UpsellOpportunity
    {
        if (count($goals) < 2) {
            return null;
        }
        foreach ($goals as $goal) {
            if ($goal['met'] !== true) {
                return null;
            }
        }

        return new UpsellOpportunity(UpsellType::GoalsReached, ['goals' => count($goals)]);
    }

    /**
     * @param  Bags  $current
     * @param  Bags  $previous
     */
    private function growth(array $current, array $previous, string $rule, UpsellType $type, float $growthPct): ?UpsellOpportunity
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

        return $change >= $growthPct
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
    private function securityPressure(array $current, float $minTotal): ?UpsellOpportunity
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

        return $found && $total >= $minTotal
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
