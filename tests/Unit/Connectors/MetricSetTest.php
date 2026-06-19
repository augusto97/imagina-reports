<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\MetricSet;
use App\Connectors\MetricSetStatus;
use PHPUnit\Framework\TestCase;

class MetricSetTest extends TestCase
{
    public function test_ok_set_exposes_its_metrics(): void
    {
        $set = MetricSet::ok([
            'ga4.sessions' => 1200,
            'ga4.sessions_by_date' => [['2026-06-01', 40]],
        ]);

        $this->assertTrue($set->isOk());
        $this->assertSame(1200, $set->get('ga4.sessions'));
        $this->assertTrue($set->has('ga4.sessions_by_date'));
        $this->assertNull($set->error);
        $this->assertSame(['ga4.sessions', 'ga4.sessions_by_date'], $set->keys());
    }

    public function test_partial_keeps_data_and_records_the_error(): void
    {
        $set = MetricSet::partial(['ga4.sessions' => 10], 'top_pages timed out');

        $this->assertTrue($set->isPartial());
        $this->assertSame('top_pages timed out', $set->error);
        $this->assertSame(10, $set->get('ga4.sessions'));
    }

    public function test_failed_has_no_metrics(): void
    {
        $set = MetricSet::failed('auth rejected');

        $this->assertTrue($set->isFailed());
        $this->assertSame([], $set->keys());
        $this->assertSame('default', $set->get('missing', 'default'));
    }

    public function test_to_array_is_snapshot_shaped(): void
    {
        $set = MetricSet::ok(['x' => 1]);

        $this->assertSame([
            'status' => MetricSetStatus::Ok->value,
            'error' => null,
            'metrics' => ['x' => 1],
        ], $set->toArray());
    }
}
