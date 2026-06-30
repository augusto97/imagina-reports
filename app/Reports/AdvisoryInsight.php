<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * The AI "site condition & recommendations" block (CLAUDE.md §10.6 added value): a dedicated
 * `advisory` block whose text the ReportGenerator fills from a consultative prompt that reads
 * the period + history. Like the executive summary, the text is injected into
 * `resolved_blocks.data[id]` so the shared AdvisoryBlock renders it in the portal/PDF/editor
 * with no renderer change. Kept separate from the neutral executive summary so the advisory's
 * recommendation tone never leaks into the plain monthly recap.
 */
final class AdvisoryInsight
{
    /**
     * @param  array<array-key, mixed>  $blocks
     */
    public static function present(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (is_array($block) && self::isAdvisoryBlock($block)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write the advisory text into every advisory block's data slot.
     *
     * @param  array<array-key, mixed>  $blocks
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function inject(array $blocks, array $data, string $text): array
    {
        foreach ($blocks as $block) {
            if (is_array($block) && self::isAdvisoryBlock($block) && is_string($block['id'] ?? null)) {
                $data[$block['id']] = $text;
            }
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $block
     */
    public static function isAdvisoryBlock(array $block): bool
    {
        return ($block['type'] ?? null) === 'advisory';
    }
}
