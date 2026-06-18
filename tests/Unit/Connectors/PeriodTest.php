<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Period;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PeriodTest extends TestCase
{
    public function test_it_counts_inclusive_whole_days(): void
    {
        $period = Period::make('2026-06-01', '2026-06-30');

        $this->assertSame(30, $period->days());
    }

    public function test_previous_is_the_equal_length_window_immediately_before(): void
    {
        $period = Period::make('2026-06-01', '2026-06-30');
        $previous = $period->previous();

        $this->assertSame('2026-05-02', $previous->start->toDateString());
        $this->assertSame('2026-05-31', $previous->end->toDateString());
        $this->assertSame($period->days(), $previous->days());
    }

    public function test_contains_checks_the_boundaries(): void
    {
        $period = Period::make('2026-06-01', '2026-06-30');

        $this->assertTrue($period->contains('2026-06-15'));
        $this->assertTrue($period->contains('2026-06-01'));
        $this->assertFalse($period->contains('2026-07-01'));
    }

    public function test_it_rejects_an_end_before_the_start(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Period::make('2026-06-30', '2026-06-01');
    }
}
