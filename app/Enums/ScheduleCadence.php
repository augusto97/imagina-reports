<?php

declare(strict_types=1);

namespace App\Enums;

use App\Connectors\Period;
use Carbon\CarbonImmutable;

/**
 * How often a report definition is generated (CLAUDE.md §5). Drives both the period
 * a scheduled run covers (the one that just ended) and the next run time.
 */
enum ScheduleCadence: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';

    /**
     * The just-ended period this cadence reports on, relative to `$now`.
     */
    public function periodFor(CarbonImmutable $now): Period
    {
        return match ($this) {
            self::Monthly => new Period($now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()),
            self::Weekly => new Period($now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()),
        };
    }

    public function nextRun(CarbonImmutable $now): CarbonImmutable
    {
        return match ($this) {
            self::Monthly => $now->addMonthNoOverflow()->startOfMonth(),
            self::Weekly => $now->addWeek()->startOfWeek(),
        };
    }
}
