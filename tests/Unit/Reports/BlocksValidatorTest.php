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
}
