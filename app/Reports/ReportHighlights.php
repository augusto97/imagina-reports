<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * The few headline numbers of a generated report (its first KPI cards, in the order the
 * agency designed them), formatted for places outside the report itself — the email that
 * announces it. Read from the frozen resolved blocks, never a live API.
 */
final class ReportHighlights
{
    /** Metrics where a drop is the good news (mirrors the renderer's LOWER_IS_BETTER). */
    private const LOWER_IS_BETTER = '/position|bounce|response_time|downtime|incident|cpc|cpa|cost_per|unsubscribe|vulnerab|out_of_stock/';

    /**
     * @param  array<array-key, mixed>  $resolved  The report's resolved_blocks.
     * @return list<array{label: string, value: string, change: string|null, good: bool|null}>
     */
    public static function from(array $resolved, string $locale, string $currency, int $limit = 3): array
    {
        $lang = FallbackNarrative::lang($locale);
        $blocks = is_array($resolved['blocks'] ?? null) ? $resolved['blocks'] : [];
        $data = is_array($resolved['data'] ?? null) ? $resolved['data'] : [];
        $out = [];

        foreach ($blocks as $block) {
            if (count($out) >= $limit) {
                break;
            }
            if (! is_array($block) || ! in_array($block['type'] ?? null, ['kpi', 'sales_summary'], true)) {
                continue;
            }
            $id = $block['id'] ?? null;
            $raw = is_string($id) ? ($data[$id] ?? null) : null;
            $value = is_array($raw) ? ($raw['value'] ?? null) : $raw;
            if (! is_numeric($value)) {
                continue;
            }

            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $style = is_array($block['style'] ?? null) ? $block['style'] : [];
            $binding = is_array($block['binding'] ?? null) ? $block['binding'] : [];
            $label = $props['label'] ?? $props['title'] ?? null;
            if (! is_string($label) || $label === '') {
                continue;
            }

            $format = is_string($style['format'] ?? null) ? $style['format'] : ($block['type'] === 'sales_summary' ? 'currency' : 'number');
            $formatted = match ($format) {
                'percent' => FallbackNarrative::percent((float) $value, $lang),
                'currency' => FallbackNarrative::number((float) $value, $lang).' '.$currency,
                default => FallbackNarrative::number((float) $value, $lang),
            };

            $change = is_array($raw) && is_numeric($raw['change_percent'] ?? null) ? (float) $raw['change_percent'] : null;
            $metric = strtolower((is_string($binding['source'] ?? null) ? $binding['source'] : '').'.'.(is_string($binding['metric'] ?? null) ? $binding['metric'] : ''));
            $good = $change === null || abs($change) < 0.05 ? null : (($change > 0) !== (preg_match(self::LOWER_IS_BETTER, $metric) === 1));

            $out[] = [
                'label' => $label,
                'value' => $formatted,
                'change' => $change === null || abs($change) < 0.05 ? null : ($change > 0 ? '+' : '−').FallbackNarrative::percent(abs($change), $lang),
                'good' => $good,
            ];
        }

        return $out;
    }
}
