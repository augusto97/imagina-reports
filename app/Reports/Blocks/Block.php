<?php

declare(strict_types=1);

namespace App\Reports\Blocks;

/**
 * A single report block (CLAUDE.md §10.2): a typed unit with an optional binding
 * to one or more metrics, plus presentational props/style. This is the validated,
 * in-memory representation of one entry in the blocks JSON.
 */
final readonly class Block
{
    /**
     * @param  array<array-key, mixed>|null  $binding  Metric binding (source/metric/dimension/compare).
     * @param  array<array-key, mixed>  $props
     * @param  array<array-key, mixed>  $style
     */
    public function __construct(
        public string $id,
        public BlockType $type,
        public ?array $binding = null,
        public array $props = [],
        public array $style = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'binding' => $this->binding,
            'props' => $this->props,
            'style' => $this->style,
        ];
    }
}
