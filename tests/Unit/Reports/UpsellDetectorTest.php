<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Enums\UpsellType;
use App\Reports\UpsellDetector;
use App\Reports\UpsellOpportunity;
use Tests\TestCase;

class UpsellDetectorTest extends TestCase
{
    /**
     * @param  list<UpsellOpportunity>  $opportunities
     * @return list<string>
     */
    private function types(array $opportunities): array
    {
        return array_map(static fn (UpsellOpportunity $o): string => $o->type->value, $opportunities);
    }

    public function test_it_flags_traffic_growth(): void
    {
        $opportunities = (new UpsellDetector)->detect(
            ['ga4' => ['ga4.sessions' => 1500]],
            ['ga4' => ['ga4.sessions' => 1000]],
            ['ga4', 'betteruptime', 'cloudflare'],
        );

        $this->assertContains(UpsellType::TrafficGrowth->value, $this->types($opportunities));
    }

    public function test_it_flags_sales_growth(): void
    {
        $opportunities = (new UpsellDetector)->detect(
            ['woocommerce' => ['woocommerce.revenue' => 1300]],
            ['woocommerce' => ['woocommerce.revenue' => 1000]],
            ['woocommerce', 'betteruptime', 'cloudflare'],
        );

        $this->assertContains(UpsellType::SalesGrowth->value, $this->types($opportunities));
    }

    public function test_it_flags_security_hardening_under_high_attack_volume(): void
    {
        $opportunities = (new UpsellDetector)->detect(
            ['cloudflare' => ['cloudflare.threats_blocked' => 300], 'crowdsec' => ['crowdsec.attacks_blocked' => 400]],
            [],
            ['cloudflare', 'crowdsec', 'betteruptime'],
        );

        $this->assertContains(UpsellType::SecurityHardening->value, $this->types($opportunities));
    }

    public function test_it_flags_coverage_gaps_for_missing_sources(): void
    {
        $opportunities = (new UpsellDetector)->detect(
            ['ga4' => ['ga4.sessions' => 50]],
            ['ga4' => ['ga4.sessions' => 50]],
            ['ga4'], // no uptime, no security source connected
        );

        $types = $this->types($opportunities);
        $this->assertContains(UpsellType::UptimeMonitoring->value, $types);
        $this->assertContains(UpsellType::SecurityProtection->value, $types);
    }

    public function test_no_coverage_gap_when_a_security_source_is_connected(): void
    {
        $opportunities = (new UpsellDetector)->detect(
            ['ga4' => ['ga4.sessions' => 50]],
            ['ga4' => ['ga4.sessions' => 50]],
            ['ga4', 'betteruptime', 'crowdsec'],
        );

        $this->assertSame([], $this->types($opportunities));
    }

    public function test_flat_metrics_and_full_coverage_yield_nothing(): void
    {
        $opportunities = (new UpsellDetector)->detect(
            ['ga4' => ['ga4.sessions' => 1000], 'cloudflare' => ['cloudflare.threats_blocked' => 5]],
            ['ga4' => ['ga4.sessions' => 1000]],
            ['ga4', 'betteruptime', 'cloudflare', 'crowdsec'],
        );

        $this->assertSame([], $opportunities);
    }
}
