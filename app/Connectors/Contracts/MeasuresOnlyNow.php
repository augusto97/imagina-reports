<?php

declare(strict_types=1);

namespace App\Connectors\Contracts;

/**
 * A connector that can only measure the site as it is right now (a speed test, a live check),
 * never a past period. The SyncService skips periods that ended more than GRACE_DAYS ago, so a
 * history backfill doesn't copy today's result into months long gone — while a report built
 * in the first days of a month still gets the month that just closed.
 */
interface MeasuresOnlyNow
{
    public const GRACE_DAYS = 7;
}
