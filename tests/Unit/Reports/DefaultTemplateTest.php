<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Reports\Blocks\BlocksValidator;
use App\Reports\Blocks\BlockType;
use App\Reports\Templates\DefaultTemplate;
use PHPUnit\Framework\TestCase;

class DefaultTemplateTest extends TestCase
{
    public function test_the_default_template_is_a_valid_layout(): void
    {
        $blocks = (new BlocksValidator)->validate(DefaultTemplate::blocks());

        $this->assertNotEmpty($blocks);
        $this->assertSame(BlockType::Header, $blocks[0]->type);
    }

    public function test_it_opens_with_header_health_and_summary(): void
    {
        $types = array_map(
            static fn (array $block): mixed => $block['type'],
            DefaultTemplate::blocks(),
        );

        $this->assertSame(['header', 'healthscore', 'narrative'], array_slice($types, 0, 3));
    }

    public function test_block_ids_are_unique(): void
    {
        $ids = array_map(static fn (array $block): mixed => $block['id'], DefaultTemplate::blocks());

        $this->assertSame(array_values(array_unique($ids)), $ids);
    }
}
