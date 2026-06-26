<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

/**
 * One filter condition applied to a dataset (a dimension = value rule), authored either
 * at the page/dashboard level or on a single block (CLAUDE.md §10 dashboards).
 *
 * Looker-style semantics: a filter that references a dimension NOT present in a given
 * row is "not applicable" and never excludes that row — so a page-level filter on
 * `country` only affects blocks whose dataset actually has a country column, and legacy
 * fixed-metric blocks are untouched.
 */
final readonly class DatasetFilter
{
    /**
     * @param  string|list<string>  $value
     */
    public function __construct(
        public string $dimension,
        public string $op,
        public string|array $value,
    ) {}

    /**
     * Lenient parse from the stored JSON (`{dimension, op, value}`); returns null when
     * the dimension is missing so a malformed filter is simply ignored, never fatal.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $dimension = $raw['dimension'] ?? null;
        if (! is_string($dimension) || $dimension === '') {
            return null;
        }

        $op = is_string($raw['op'] ?? null) && $raw['op'] !== '' ? strtolower($raw['op']) : 'is';

        $rawValue = $raw['value'] ?? '';
        if (is_array($rawValue)) {
            $value = [];
            foreach ($rawValue as $item) {
                if (is_scalar($item)) {
                    $value[] = (string) $item;
                }
            }
        } else {
            $value = is_scalar($rawValue) ? (string) $rawValue : '';
        }

        return new self($dimension, $op, $value);
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    public function matches(array $row): bool
    {
        if (! array_key_exists($this->dimension, $row)) {
            return true; // Not applicable to this dataset → don't exclude (§ Looker filters).
        }

        $cell = is_scalar($row[$this->dimension]) ? (string) $row[$this->dimension] : '';
        $needles = is_array($this->value) ? $this->value : [$this->value];

        return match ($this->op) {
            'is', 'in' => $this->anyEquals($cell, $needles),
            'is_not', 'not_in' => ! $this->anyEquals($cell, $needles),
            'contains' => $this->anyContains($cell, $needles),
            'not_contains' => ! $this->anyContains($cell, $needles),
            default => true,
        };
    }

    /**
     * @param  list<string>  $needles
     */
    private function anyEquals(string $cell, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (mb_strtolower($cell) === mb_strtolower($needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $needles
     */
    private function anyContains(string $cell, array $needles): bool
    {
        $haystack = mb_strtolower($cell);
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
