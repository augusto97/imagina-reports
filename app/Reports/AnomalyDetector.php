<?php

declare(strict_types=1);

namespace App\Reports;

use App\Connectors\Support\ParsesValues;
use App\Enums\AnomalyType;
use App\Reports\Support\ReadsMetricBags;

/**
 * Detects anomalies (CLAUDE.md §13) by comparing a period's metric bags against the
 * previous period's. Pure and config-driven (config/anomalies.php) — it reads only
 * pre-aggregated snapshot values, never live APIs, and returns value objects so the
 * caller decides how to alert (internal log + `anomaly.detected` webhook, §8).
 *
 * @phpstan-type Bags array<string, array<array-key, mixed>>
 */
final class AnomalyDetector
{
    use ParsesValues, ReadsMetricBags;

    /**
     * @param  Bags  $current
     * @param  Bags  $previous
     * @return list<Anomaly>
     */
    public function detect(array $current, array $previous): array
    {
        $anomalies = [];

        $drop = $this->trafficDrop($current, $previous);

        if ($drop !== null) {
            $anomalies[] = $drop;
        }

        foreach ([
            ['anomalies.attack_spike', AnomalyType::AttackSpike],
            ['anomalies.spend_spike', AnomalyType::SpendSpike],
        ] as [$configKey, $type]) {
            foreach ($this->spikes($current, $previous, $this->arrayOf(config($configKey)), $type) as $spike) {
                $anomalies[] = $spike;
            }
        }

        foreach ($this->drops($current, $previous, $this->arrayOf(config('anomalies.conversions_drop')), AnomalyType::ConversionsDrop) as $drop) {
            $anomalies[] = $drop;
        }

        return $anomalies;
    }

    /**
     * @param  Bags  $current
     * @param  Bags  $previous
     */
    private function trafficDrop(array $current, array $previous): ?Anomaly
    {
        $config = $this->arrayOf(config('anomalies.traffic_drop'));
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

        return $change <= -$this->toFloat($config['drop_pct'] ?? 0)
            ? new Anomaly(AnomalyType::TrafficDrop, $metric, $cur, $prev, $change)
            : null;
    }

    /**
     * A spike over any of the config's metrics: rose by at least spike_pct vs a meaningful
     * baseline, OR jumped from a near-zero baseline to at least min_current.
     *
     * @param  Bags  $current
     * @param  Bags  $previous
     * @param  array<array-key, mixed>  $config
     * @return list<Anomaly>
     */
    private function spikes(array $current, array $previous, array $config, AnomalyType $type): array
    {
        $minPrevious = $this->toFloat($config['min_previous'] ?? 0);
        $minCurrent = $this->toFloat($config['min_current'] ?? 0);
        $spikePct = $this->toFloat($config['spike_pct'] ?? 0);

        $anomalies = [];

        foreach ($this->arrayOf($config['metrics'] ?? []) as $rawMetric) {
            $metric = $this->toStr($rawMetric);

            if ($metric === '') {
                continue;
            }

            $cur = $this->metricValue($current, $metric);
            $prev = $this->metricValue($previous, $metric);

            if ($cur === null || $prev === null) {
                continue;
            }

            $change = $this->changePercent($cur, $prev);

            $spiked = ($prev >= $minPrevious && $change >= $spikePct)
                || ($prev < $minPrevious && $cur >= $minCurrent);

            if ($spiked) {
                $anomalies[] = new Anomaly($type, $metric, $cur, $prev, $change);
            }
        }

        return $anomalies;
    }

    /**
     * A drop over any of the config's metrics: fell by at least drop_pct from a meaningful
     * baseline (mirrors trafficDrop but for a metric list).
     *
     * @param  Bags  $current
     * @param  Bags  $previous
     * @param  array<array-key, mixed>  $config
     * @return list<Anomaly>
     */
    private function drops(array $current, array $previous, array $config, AnomalyType $type): array
    {
        $minPrevious = $this->toFloat($config['min_previous'] ?? 0);
        $dropPct = $this->toFloat($config['drop_pct'] ?? 0);

        $anomalies = [];

        foreach ($this->arrayOf($config['metrics'] ?? []) as $rawMetric) {
            $metric = $this->toStr($rawMetric);

            if ($metric === '') {
                continue;
            }

            $cur = $this->metricValue($current, $metric);
            $prev = $this->metricValue($previous, $metric);

            if ($cur === null || $prev === null || $prev < $minPrevious) {
                continue;
            }

            $change = $this->changePercent($cur, $prev);

            if ($change <= -$dropPct) {
                $anomalies[] = new Anomaly($type, $metric, $cur, $prev, $change);
            }
        }

        return $anomalies;
    }
}
