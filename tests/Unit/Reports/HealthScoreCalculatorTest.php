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

    public function test_known_vulnerabilities_lower_the_security_signal(): void
    {
        $this->assertSame(70, $this->calculator->calculate([
            'mainwp' => ['mainwp.vulnerabilities_count' => 2],
        ]));
    }

    public function test_virusdie_scans_never_count_as_malware(): void
    {
        // A scan count says the site was checked, not that it was infected: no security signal.
        $this->assertSame([], $this->calculator->breakdown([
            'mainwp' => ['mainwp.virusdie_scans' => 30],
        ])['signals']);
    }

    public function test_it_reweights_over_only_the_present_signals(): void
    {
        // uptime 99 % → 80 (w 25) + updates 0 pending → 100 (w 20) => (2000 + 2000) / 45 ≈ 89.
        $score = $this->calculator->calculate([
            'betteruptime' => ['betteruptime.uptime_percent' => 99],
            'mainwp' => ['mainwp.updates_available' => 0],
        ]);

        $this->assertSame(89, $score);
    }

    public function test_a_fully_healthy_stack_scores_100(): void
    {
        $score = $this->calculator->calculate([
            'betteruptime' => ['betteruptime.uptime_percent' => 100],
            'mainwp' => ['mainwp.updates_available' => 0, 'mainwp.vulnerabilities_count' => 0, 'mainwp.ssl_days_remaining' => 80],
            'cloudflare' => ['cloudflare.cache_ratio' => 100],
        ]);

        $this->assertSame(100, $score);
    }

    public function test_the_breakdown_explains_each_signal_in_plain_words(): void
    {
        $result = $this->calculator->breakdown([
            'betteruptime' => ['betteruptime.uptime_percent' => 99.9],
            'site_agent' => ['site_agent.updates_pending' => 3, 'site_agent.ssl_days_remaining' => 10, 'site_agent.last_backup_days' => 2, 'site_agent.abandoned_count' => 1],
            'cloudflare' => ['cloudflare.cache_ratio' => 62.5],
        ]);

        $byKey = array_column($result['signals'], null, 'key');
        $this->assertSame(['uptime', 'updates', 'security', 'ssl', 'backups', 'performance'], array_column($result['signals'], 'key'));
        $this->assertSame('99,9 % del tiempo en línea', $byKey['uptime']['detail']);
        $this->assertSame(98, $byKey['uptime']['score']);
        $this->assertSame('3 actualizaciones pendientes', $byKey['updates']['detail']);
        $this->assertSame(85, $byKey['updates']['score']);
        $this->assertSame('1 plugin abandonado', $byKey['security']['detail']);
        $this->assertSame('Vence en 10 días', $byKey['ssl']['detail']);
        $this->assertSame(40, $byKey['ssl']['score']);
        $this->assertSame('Último respaldo hace 2 días', $byKey['backups']['detail']);
        $this->assertSame(63, $byKey['performance']['score'], 'the cache ratio is already a percentage');
        // (98·25 + 85·20 + 90·20 + 40·10 + 90·10 + 63·15) / 100 = 81.95 → 82
        $this->assertSame(82, $result['score']);
    }

    public function test_googles_speed_score_is_the_performance_signal_when_measured(): void
    {
        $breakdown = $this->calculator->breakdown([
            'pagespeed' => ['pagespeed.score_mobile' => 64],
            'cloudflare' => ['cloudflare.cache_ratio' => 95],
        ]);

        $this->assertSame(64, $breakdown['score']);
        $this->assertSame('Nota de velocidad de Google en celular: 64/100', $breakdown['signals'][0]['detail']);
    }

    public function test_without_any_signal_the_breakdown_is_empty(): void
    {
        $this->assertSame(['score' => 100, 'signals' => []], $this->calculator->breakdown(['ga4' => ['ga4.sessions' => 10]]));
    }
}
