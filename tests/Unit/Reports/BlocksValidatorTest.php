<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Reports\Blocks\BlocksValidator;
use App\Reports\Blocks\BlockType;
use App\Reports\Blocks\BlockValidationException;
use PHPUnit\Framework\TestCase;

class BlocksValidatorTest extends TestCase
{
    private BlocksValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new BlocksValidator;
    }

    public function test_it_parses_a_valid_layout_into_block_objects(): void
    {
        $blocks = $this->validator->validate([
            ['id' => 'h1', 'type' => 'header', 'props' => ['showLogo' => true]],
            ['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions']],
        ]);

        $this->assertCount(2, $blocks);
        $this->assertSame('h1', $blocks[0]->id);
        $this->assertSame(BlockType::Kpi, $blocks[1]->type);
        $this->assertSame('ga4', $blocks[1]->binding['source'] ?? null);
    }

    public function test_it_rejects_a_non_array_layout(): void
    {
        $this->expectException(BlockValidationException::class);
        $this->validator->validate('not-a-list');
    }

    public function test_it_collects_id_type_and_duplicate_errors(): void
    {
        try {
            $this->validator->validate([
                ['type' => 'header'],                       // missing id
                ['id' => 'x', 'type' => 'nope'],            // invalid type
                ['id' => 'dup', 'type' => 'divider'],
                ['id' => 'dup', 'type' => 'divider'],       // duplicate id
            ]);
            $this->fail('Expected BlockValidationException.');
        } catch (BlockValidationException $e) {
            $this->assertCount(3, $e->errors);
        }
    }

    public function test_data_blocks_require_a_metric_binding(): void
    {
        try {
            $this->validator->validate([
                ['id' => 'c1', 'type' => 'chart', 'props' => ['chartType' => 'line']],
            ]);
            $this->fail('Expected BlockValidationException.');
        } catch (BlockValidationException $e) {
            $this->assertStringContainsString('requires a binding', $e->errors[0]);
        }
    }

    public function test_non_data_blocks_do_not_require_a_binding(): void
    {
        $blocks = $this->validator->validate([
            ['id' => 'd1', 'type' => 'divider'],
            ['id' => 'n1', 'type' => 'narrative', 'props' => ['variant' => 'executive_summary']],
        ]);

        $this->assertCount(2, $blocks);
        $this->assertNull($blocks[0]->binding);
    }

    public function test_it_accepts_and_clamps_grid_layout(): void
    {
        $blocks = $this->validator->validate([
            ['id' => 'a', 'type' => 'divider', 'layout' => ['x' => 9, 'y' => 2, 'w' => 8, 'h' => 3]],
        ]);

        // x=9 leaves only 3 columns, so w is clamped from 8 to 3.
        $this->assertSame(['x' => 9, 'y' => 2, 'w' => 3, 'h' => 3], $blocks[0]->layout);
    }

    public function test_layout_is_null_when_absent(): void
    {
        $blocks = $this->validator->validate([['id' => 'a', 'type' => 'divider']]);

        $this->assertNull($blocks[0]->layout);
    }

    public function test_it_parses_and_clamps_the_page_index(): void
    {
        $blocks = $this->validator->validate([
            ['id' => 'a', 'type' => 'divider', 'page' => 2],
            ['id' => 'b', 'type' => 'divider', 'page' => -5],
            ['id' => 'c', 'type' => 'divider'],
        ]);

        $this->assertSame(2, $blocks[0]->page);
        $this->assertSame(0, $blocks[1]->page); // negative clamped to 0
        $this->assertSame(0, $blocks[2]->page); // absent defaults to 0
    }

    public function test_it_rejects_a_non_numeric_layout(): void
    {
        try {
            $this->validator->validate([
                ['id' => 'a', 'type' => 'divider', 'layout' => ['x' => 'left', 'y' => 0, 'w' => 6, 'h' => 2]],
            ]);
            $this->fail('Expected BlockValidationException.');
        } catch (BlockValidationException $e) {
            $this->assertStringContainsString('layout.x', $e->errors[0]);
        }
    }
}
