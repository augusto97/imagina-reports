<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Reports\HealthScoreCalculator;
use PHPUnit\Framework\TestCase;

class HealthScoreCalculatorTest extends TestCase
{
    private HealthScoreCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new HealthScoreCalculator;
    }

    public function test_no_signals_returns_all_clear(): void
    {
        $this->assertSame(100, $this->calculator->calculate([]));
    }

    public function test_each_pending_update_costs_five_points(): void
    {
        $this->assertSame(80, $this->calculator->calculate([
            'mainwp' => ['mainwp.updates_available' => 4],
        ]));
    }

    public function test_detected_malware_lowers_the_security_signal(): void
    {
        $this->assertSame(40, $this->calculator->calculate([
            'mainwp' => ['mainwp.malware_found' => 2],
        ]));
    }

    public function test_it_reweights_over_only_the_present_signals(): void
    {
        // uptime 99 (w .30) + updates 100 (w .25) => (29.7 + 25) / .55 ≈ 99.
        $score = $this->calculator->calculate([
            'betteruptime' => ['betteruptime.uptime_percent' => 99],
            'mainwp' => ['mainwp.updates_available' => 0],
        ]);

        $this->assertSame(99, $score);
    }

    public function test_a_fully_healthy_stack_scores_100(): void
    {
        $score = $this->calculator->calculate([
            'betteruptime' => ['betteruptime.uptime_percent' => 100],
            'mainwp' => ['mainwp.updates_available' => 0, 'mainwp.malware_found' => 0],
            'cloudflare' => ['cloudflare.cache_ratio' => 1],
        ]);

        $this->assertSame(100, $score);
    }
}
