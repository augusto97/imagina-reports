<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\AnomalyType;

/**
 * A detected anomaly (CLAUDE.md §13): one watched metric whose period-over-period
 * change crossed a configured threshold. Carries both raw values and the percent
 * change so alert recipients and webhook consumers see the magnitude.
 */
final readonly class Anomaly
{
    public function __construct(
        public AnomalyType $type,
        public string $metric,
        public float $current,
        public float $previous,
        public float $changePercent,
    ) {}

    /**
     * @return array{type: string, metric: string, current: float, previous: float, change_percent: float}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'metric' => $this->metric,
            'current' => $this->current,
            'previous' => $this->previous,
            'change_percent' => round($this->changePercent, 2),
        ];
    }
}
