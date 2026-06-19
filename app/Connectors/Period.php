<?php

declare(strict_types=1);

namespace App\Connectors;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * An immutable, inclusive reporting period. Connectors fetch metrics aggregated
 * over this window (CLAUDE.md §3.1/§7). `previous()` yields the equal-length
 * window immediately before it — the basis for "vs previous period" comparisons.
 */
final readonly class Period
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public function __construct(DateTimeInterface|string $start, DateTimeInterface|string $end)
    {
        $this->start = CarbonImmutable::parse($start);
        $this->end = CarbonImmutable::parse($end);

        if ($this->end->lessThan($this->start)) {
            throw new InvalidArgumentException('Period end must not precede its start.');
        }
    }

    public static function make(DateTimeInterface|string $start, DateTimeInterface|string $end): self
    {
        return new self($start, $end);
    }

    /**
     * Whole-day length of the period, inclusive of both ends.
     */
    public function days(): int
    {
        return (int) $this->start->startOfDay()->diffInDays($this->end->startOfDay(), true) + 1;
    }

    /**
     * The equal-length period immediately preceding this one.
     */
    public function previous(): self
    {
        $days = $this->days();

        return new self(
            $this->start->subDays($days),
            $this->end->subDays($days),
        );
    }

    public function contains(DateTimeInterface|string $moment): bool
    {
        $moment = CarbonImmutable::parse($moment);

        return $moment->betweenIncluded($this->start, $this->end);
    }

    /**
     * @return array{start: string, end: string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
        ];
    }
}
