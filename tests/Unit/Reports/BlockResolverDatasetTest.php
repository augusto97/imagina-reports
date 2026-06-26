<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Reports\BlockResolver;
use App\Reports\Blocks\Block;
use App\Reports\Blocks\BlockType;
use Tests\TestCase;

class BlockResolverDatasetTest extends TestCase
{
    /**
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function bags(): array
    {
        return [
            'ga4' => [
                'ga4.geo' => [
                    ['country' => 'Colombia', 'city' => 'Bogotá', 'sessions' => 120],
                    ['country' => 'Colombia', 'city' => 'Medellín', 'sessions' => 80],
                    ['country' => 'México', 'city' => 'CDMX', 'sessions' => 200],
                ],
            ],
        ];
    }

    public function test_dataset_block_is_shaped_by_its_binding(): void
    {
        $block = new Block('b1', BlockType::Table, [
            'source' => 'ga4',
            'metric' => 'geo',
            'measure' => 'sessions',
            'breakdown' => 'city',
            'filters' => [['dimension' => 'country', 'op' => 'is', 'value' => 'Colombia']],
        ]);

        $resolved = (new BlockResolver)->resolve([$block], $this->bags(), 0);

        $this->assertSame([
            ['label' => 'Bogotá', 'value' => 120],
            ['label' => 'Medellín', 'value' => 80],
        ], $resolved['data']['b1']);
    }

    public function test_page_filter_applies_and_block_filter_overrides_it(): void
    {
        // No block filter: the page filter (country=México) drives the result.
        $pageBlock = new Block('p', BlockType::Table, ['source' => 'ga4', 'metric' => 'geo', 'measure' => 'sessions', 'breakdown' => 'city']);
        $filtersByPage = ['all' => [['dimension' => 'country', 'op' => 'is', 'value' => 'México']]];

        $resolved = (new BlockResolver)->resolve([$pageBlock], $this->bags(), 0, [], $filtersByPage);
        $this->assertSame([['label' => 'CDMX', 'value' => 200]], $resolved['data']['p']);

        // Block filter (country=Colombia) wins over the page filter (country=México).
        $blockOverride = new Block('o', BlockType::Table, [
            'source' => 'ga4', 'metric' => 'geo', 'measure' => 'sessions', 'breakdown' => 'city',
            'filters' => [['dimension' => 'country', 'op' => 'is', 'value' => 'Colombia']],
        ]);

        $resolved = (new BlockResolver)->resolve([$blockOverride], $this->bags(), 0, [], $filtersByPage);
        $labels = array_column($resolved['data']['o'], 'label');
        $this->assertContains('Bogotá', $labels);
        $this->assertNotContains('CDMX', $labels);
    }

    public function test_non_dataset_binding_resolves_the_plain_value(): void
    {
        // A legacy table metric (no breakdown/measure) is returned as-is, untouched.
        $bags = ['ga4' => ['ga4.top_pages' => [['label' => '/home', 'value' => 234]]]];
        $block = new Block('t', BlockType::Table, ['source' => 'ga4', 'metric' => 'top_pages']);

        $resolved = (new BlockResolver)->resolve([$block], $bags, 0);

        $this->assertSame([['label' => '/home', 'value' => 234]], $resolved['data']['t']);
    }

    public function test_missing_dataset_metric_hides_the_block(): void
    {
        $block = new Block('m', BlockType::Table, ['source' => 'ga4', 'metric' => 'geo', 'measure' => 'sessions', 'breakdown' => 'city']);

        $resolved = (new BlockResolver)->resolve([$block], ['ga4' => []], 0);

        $this->assertArrayNotHasKey('m', $resolved['data']);
        $this->assertCount(1, $resolved['diagnostics']);
    }
}
