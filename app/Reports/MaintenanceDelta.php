<?php

declare(strict_types=1);

namespace App\Reports;

use Carbon\CarbonImmutable;

/**
 * The computed "what we did this month" maintenance delta (CLAUDE.md §9): the
 * result of diffing two snapshots over a period.
 */
final readonly class MaintenanceDelta
{
    /**
     * @param  array<string, float>  $deltas  Per-metric net change (latest − earliest).
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $updatesApplied,
        public array $deltas,
    ) {}
}
