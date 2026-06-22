<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * Where the AI per-period narrative lives in a report: narrative blocks flagged
 * `props.variant === 'executive_summary'` (the default template §11.5). The text is
 * stored on `ir_reports.executive_summary` AND injected into `resolved_blocks.data[id]`
 * so the shared NarrativeBlock renders it (it shows `data` over `props.text`) in the
 * portal/PDF/editor with no renderer change.
 *
 * Shared by the ReportGenerator (writes it at generation) and the narrative
 * edit/regenerate endpoints (rewrites it on a persisted report) so both agree on
 * exactly which blocks carry the summary.
 */
final class ExecutiveSummary
{
    /**
     * @param  array<array-key, mixed>  $blocks
     */
    public static function present(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (is_array($block) && self::isSummaryBlock($block)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write the summary text into every executive-summary block's data slot.
     *
     * @param  array<array-key, mixed>  $blocks
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function inject(array $blocks, array $data, string $text): array
    {
        foreach ($blocks as $block) {
            if (is_array($block) && self::isSummaryBlock($block) && is_string($block['id'] ?? null)) {
                $data[$block['id']] = $text;
            }
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $block
     */
    public static function isSummaryBlock(array $block): bool
    {
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];

        return ($block['type'] ?? null) === 'narrative'
            && ($props['variant'] ?? null) === 'executive_summary';
    }
}
