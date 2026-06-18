<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Enums\AnomalyType;
use App\Reports\AnomalyDetector;
use Tests\TestCase;

class AnomalyDetectorTest extends TestCase
{
    /**
     * @param  array<string, int|float>  $metrics
     * @return array<string, array<string, int|float>>
     */
    private function ga4(array $metrics): array
    {
        return ['ga4' => $metrics];
    }

    public function test_it_flags_a_traffic_drop_past_the_threshold(): void
    {
        $anomalies = (new AnomalyDetector)->detect(
            $this->ga4(['ga4.sessions' => 300]),
            $this->ga4(['ga4.sessions' => 1000]),
        );

        $this->assertCount(1, $anomalies);
        $this->assertSame(AnomalyType::TrafficDrop, $anomalies[0]->type);
        $this->assertSame('ga4.sessions', $anomalies[0]->metric);
        $this->assertSame(300.0, $anomalies[0]->current);
        $this->assertSame(1000.0, $anomalies[0]->previous);
        $this->assertSame(-70.0, $anomalies[0]->changePercent);
    }

    public function test_a_small_dip_is_not_an_anomaly(): void
    {
        $this->assertSame([], (new AnomalyDetector)->detect(
            $this->ga4(['ga4.sessions' => 900]),
            $this->ga4(['ga4.sessions' => 1000]),
        ));
    }

    public function test_a_drop_from_a_tiny_baseline_is_ignored(): void
    {
        // Previous period below min_previous (100): noise, not a real signal.
        $this->assertSame([], (new AnomalyDetector)->detect(
            $this->ga4(['ga4.sessions' => 0]),
            $this->ga4(['ga4.sessions' => 50]),
        ));
    }

    public function test_it_flags_an_attack_spike_over_a_baseline(): void
    {
        $anomalies = (new AnomalyDetector)->detect(
            ['cloudflare' => ['cloudflare.threats_blocked' => 100]],
            ['cloudflare' => ['cloudflare.threats_blocked' => 20]],
        );

        $this->assertCount(1, $anomalies);
        $this->assertSame(AnomalyType::AttackSpike, $anomalies[0]->type);
        $this->assertSame('cloudflare.threats_blocked', $anomalies[0]->metric);
        $this->assertSame(400.0, $anomalies[0]->changePercent);
    }

    public function test_it_flags_an_attack_spike_from_a_near_zero_baseline(): void
    {
        $anomalies = (new AnomalyDetector)->detect(
            ['crowdsec' => ['crowdsec.attacks_blocked' => 60]],
            ['crowdsec' => ['crowdsec.attacks_blocked' => 2]],
        );

        $this->assertCount(1, $anomalies);
        $this->assertSame(AnomalyType::AttackSpike, $anomalies[0]->type);
    }

    public function test_a_modest_attack_increase_is_not_a_spike(): void
    {
        $this->assertSame([], (new AnomalyDetector)->detect(
            ['cloudflare' => ['cloudflare.threats_blocked' => 25]],
            ['cloudflare' => ['cloudflare.threats_blocked' => 20]],
        ));
    }

    public function test_a_missing_metric_produces_no_anomaly(): void
    {
        $this->assertSame([], (new AnomalyDetector)->detect([], []));
        $this->assertSame([], (new AnomalyDetector)->detect(
            $this->ga4(['ga4.sessions' => 300]),
            [],
        ));
    }
}
