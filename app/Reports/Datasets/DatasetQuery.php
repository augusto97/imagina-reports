<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

/**
 * How a block shapes a dataset (CLAUDE.md §10 dashboards): which measure to read, which
 * dimension to break down by, the filters to apply, and the sort/limit. Parsed from the
 * block's `binding`. A binding is a dataset binding when it sets a `breakdown` or
 * `measure` — otherwise the metric resolves the legacy way (raw value).
 */
final readonly class DatasetQuery
{
    /**
     * @param  list<DatasetFilter>  $filters
     */
    public function __construct(
        public array $filters = [],
        public ?string $breakdown = null,
        public ?string $measure = null,
        public string $sortBy = 'value',
        public string $sortDir = 'desc',
        public ?int $limit = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $binding
     */
    public static function fromBinding(array $binding): self
    {
        $filters = [];
        $rawFilters = $binding['filters'] ?? null;
        if (is_array($rawFilters)) {
            foreach ($rawFilters as $raw) {
                $filter = DatasetFilter::fromArray($raw);
                if ($filter !== null) {
                    $filters[] = $filter;
                }
            }
        }

        $breakdown = is_string($binding['breakdown'] ?? null) && $binding['breakdown'] !== '' ? $binding['breakdown'] : null;
        $measure = is_string($binding['measure'] ?? null) && $binding['measure'] !== '' ? $binding['measure'] : null;

        $sortBy = 'value';
        $sortDir = 'desc';
        $sort = $binding['sort'] ?? null;
        if (is_array($sort)) {
            if (is_string($sort['by'] ?? null) && $sort['by'] !== '') {
                $sortBy = $sort['by'];
            }
            if (is_string($sort['dir'] ?? null) && strtolower($sort['dir']) === 'asc') {
                $sortDir = 'asc';
            }
        }

        $limit = isset($binding['limit']) && is_numeric($binding['limit']) ? max(1, (int) $binding['limit']) : null;

        return new self($filters, $breakdown, $measure, $sortBy, $sortDir, $limit);
    }

    /** Whether this binding intends to shape a dataset (vs. read a plain metric value). */
    public function isDataset(): bool
    {
        return $this->breakdown !== null || $this->measure !== null;
    }
}
