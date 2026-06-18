<?php

declare(strict_types=1);

namespace App\Connectors\Support;

/**
 * Defensive coercion helpers shared by connectors that parse third-party JSON
 * (CLAUDE.md §7 — tolerate missing/oddly-typed fields rather than throwing).
 */
trait ParsesValues
{
    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function listOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
