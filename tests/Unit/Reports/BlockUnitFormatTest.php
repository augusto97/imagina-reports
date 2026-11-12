<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Reports\BlockResolver;
use App\Reports\Blocks\Block;
use App\Reports\Blocks\BlockType;
use App\Reports\MetricUnits;
use Tests\TestCase;

/**
 * A block nobody formatted by hand reads by its metric's unit: a rate as %, money in the
 * report's currency, a response time in ms, a backup age in days.
 */
class BlockUnitFormatTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function bags(): array
    {
        return [
            'gsc' => ['gsc.ctr' => 4.2, 'gsc.clicks' => 120],
            'betteruptime' => ['betteruptime.avg_response_time' => 310],
            'site_agent' => ['site_agent.last_backup_days' => 2],
            'woocommerce' => ['woocommerce.revenue' => 1520.5],
        ];
    }

    public function test_unformatted_blocks_take_the_format_of_their_metrics_unit(): void
    {
        $blocks = [
            new Block('ctr', BlockType::Kpi, ['source' => 'gsc', 'metric' => 'ctr']),
            new Block('rt', BlockType::Kpi, ['source' => 'betteruptime', 'metric' => 'avg_response_time']),
            new Block('bk', BlockType::Kpi, ['source' => 'site_agent', 'metric' => 'last_backup_days']),
            new Block('rev', BlockType::SalesSummary, ['source' => 'woocommerce', 'metric' => 'revenue']),
            new Block('clicks', BlockType::Kpi, ['source' => 'gsc', 'metric' => 'clicks']),
            // Chosen by hand: left alone.
            new Block('mine', BlockType::Kpi, ['source' => 'gsc', 'metric' => 'ctr'], style: ['format' => 'number']),
        ];

        $resolved = (new BlockResolver)->resolve($blocks, $this->bags(), 0);
        $formats = array_column(array_map(static fn (array $block): array => ['id' => $block['id'], 'format' => $block['style']['format'] ?? null], $resolved['blocks']), 'format', 'id');

        $this->assertSame(['ctr' => 'percent', 'rt' => 'ms', 'bk' => 'days', 'rev' => 'currency', 'clicks' => null, 'mine' => 'number'], $formats);
    }

    public function test_every_unit_in_the_catalogs_is_known_or_reads_as_a_plain_number(): void
    {
        MetricUnits::flush();
        $units = array_unique(array_values((new MetricUnits)->units()));

        // Units shown as plain numbers on purpose; anything else must have a format.
        $plain = ['count', 'score', 'unit'];
        foreach ($units as $unit) {
            $this->assertTrue(in_array($unit, $plain, true) || array_key_exists($unit, MetricUnits::FORMATS), "Unit «{$unit}» has no format.");
        }
    }
}
