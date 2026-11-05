<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Reports\SeriesLabels;
use PHPUnit\Framework\TestCase;

class SeriesLabelsTest extends TestCase
{
    public function test_hours_get_their_natural_order_and_a_readable_label(): void
    {
        // As GA4 returns them: bare numbers in text order (0, 1, 10, 11, … 2, 20…).
        $raw = [['date' => '0', 'value' => 1], ['date' => '1', 'value' => 2], ['date' => '10', 'value' => 5], ['date' => '2', 'value' => 3], ['date' => '23', 'value' => 4]];

        $this->assertSame(
            [['date' => '00 h', 'value' => 1], ['date' => '01 h', 'value' => 2], ['date' => '02 h', 'value' => 3], ['date' => '10 h', 'value' => 5], ['date' => '23 h', 'value' => 4]],
            SeriesLabels::apply('ga4.sessions_by_hour', $raw),
        );
    }

    public function test_weekdays_start_on_monday_with_their_names(): void
    {
        // 0 = Sunday … 6 = Saturday.
        $raw = array_map(static fn (int $day): array => ['date' => (string) $day, 'value' => $day * 10], range(0, 6));

        $result = SeriesLabels::apply('ga4.sessions_by_weekday', $raw);

        $this->assertSame(['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'], array_column($result, 'date'));
        $this->assertSame([10, 20, 30, 40, 50, 60, 0], array_column($result, 'value'));
        $this->assertSame('Mon', SeriesLabels::apply('ga4.sessions_by_weekday', $raw, 'en')[0]['date']);
    }

    public function test_anything_else_is_left_alone(): void
    {
        $dates = [['date' => '2026-10-01', 'value' => 3]];
        $this->assertSame($dates, SeriesLabels::apply('ga4.sessions_by_date', $dates));
        // Already labelled, or not a bare number: untouched.
        $labelled = [['date' => 'Lun', 'value' => 3]];
        $this->assertSame($labelled, SeriesLabels::apply('ga4.sessions_by_weekday', $labelled));
        $this->assertSame(42, SeriesLabels::apply('ga4.sessions_by_hour', 42));
    }
}
