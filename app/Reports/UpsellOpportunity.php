<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\UpsellType;

/**
 * A detected upsell opportunity (CLAUDE.md §13): a commercial signal for the agency.
 * The `type` is the stable identifier (localized in the UI); `context` carries the
 * supporting numbers (growth %, attack totals, missing source).
 */
final readonly class UpsellOpportunity
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public UpsellType $type,
        public array $context = [],
    ) {}

    /**
     * @return array{type: string, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'context' => $this->context,
        ];
    }
}
