<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Reports\BlockResolver;
use App\Reports\Blocks\Block;
use App\Reports\Blocks\BlockType;
use Tests\TestCase;

/**
 * What the resolver measures, not how it looks: a dataset reaching a plain chart still draws,
 * and a rate compares in points.
 */
class BlockResolverMeasurementTest extends TestCase
{
    public function test_a_chart_bound_to_a_dataset_without_a_breakdown_draws_its_first_cut(): void
    {
        $bags = ['ga4' => ['ga4.devices' => [
            ['device' => 'mobile', 'browser' => 'Chrome', 'os' => 'Android', 'sessions' => 300, 'users' => 250],
            ['device' => 'desktop', 'browser' => 'Chrome', 'os' => 'Windows', 'sessions' => 120, 'users' => 100],
            ['device' => 'mobile', 'browser' => 'Safari', 'os' => 'iOS', 'sessions' => 80, 'users' => 70],
        ]]];
        $blocks = [new Block('donut', BlockType::Chart, ['source' => 'ga4', 'metric' => 'devices'], props: ['variant' => 'donut'])];

        $resolved = (new BlockResolver)->resolve($blocks, $bags, 0);

        $this->assertSame([
            ['label' => 'mobile', 'value' => 380],
            ['label' => 'desktop', 'value' => 120],
        ], $resolved['data']['donut']);
    }

    public function test_a_chart_already_in_shape_is_left_alone(): void
    {
        $rows = [['label' => 'google', 'value' => 10], ['label' => 'bing', 'value' => 2]];
        $blocks = [new Block('bar', BlockType::Chart, ['source' => 'ga4', 'metric' => 'traffic_sources'])];

        $resolved = (new BlockResolver)->resolve($blocks, ['ga4' => ['ga4.traffic_sources' => $rows]], 0);

        $this->assertSame($rows, $resolved['data']['bar']);
    }

    public function test_a_rate_compares_in_points_and_a_count_only_in_percent(): void
    {
        $blocks = [
            new Block('bounce', BlockType::Kpi, ['source' => 'ga4', 'metric' => 'bounce_rate', 'compare' => 'prev_period']),
            new Block('sessions', BlockType::Kpi, ['source' => 'ga4', 'metric' => 'sessions', 'compare' => 'prev_period']),
        ];

        $resolved = (new BlockResolver)->resolve(
            $blocks,
            ['ga4' => ['ga4.bounce_rate' => 42.0, 'ga4.sessions' => 1100]],
            0,
            ['ga4' => ['ga4.bounce_rate' => 40.0, 'ga4.sessions' => 1000]],
        );

        $this->assertSame(2.0, $resolved['data']['bounce']['change_points']);
        $this->assertSame(5.0, $resolved['data']['bounce']['change_percent']);
        $this->assertSame(10.0, $resolved['data']['sessions']['change_percent']);
        $this->assertArrayNotHasKey('change_points', $resolved['data']['sessions']);
    }
}
