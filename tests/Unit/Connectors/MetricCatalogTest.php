<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricType;
use PHPUnit\Framework\TestCase;

class MetricCatalogTest extends TestCase
{
    public function test_it_indexes_definitions_by_key(): void
    {
        $catalog = new MetricCatalog(
            new MetricDefinition('ga4.sessions', 'Sessions', MetricType::Scalar),
            new MetricDefinition('ga4.top_pages', 'Top pages', MetricType::Table),
        );

        $this->assertTrue($catalog->has('ga4.sessions'));
        $this->assertFalse($catalog->has('ga4.unknown'));
        $this->assertSame('Sessions', $catalog->get('ga4.sessions')?->label);
        $this->assertNull($catalog->get('ga4.unknown'));
        $this->assertSame(['ga4.sessions', 'ga4.top_pages'], $catalog->keys());
    }

    public function test_with_replaces_by_key_and_stays_immutable(): void
    {
        $catalog = new MetricCatalog(
            new MetricDefinition('ga4.sessions', 'Sessions', MetricType::Scalar),
        );

        $extended = $catalog->with(new MetricDefinition('ga4.users', 'Users', MetricType::Scalar));

        $this->assertFalse($catalog->has('ga4.users'));
        $this->assertTrue($extended->has('ga4.users'));
        $this->assertCount(2, $extended->all());
    }

    public function test_empty_catalog(): void
    {
        $this->assertTrue((new MetricCatalog)->isEmpty());
    }
}
