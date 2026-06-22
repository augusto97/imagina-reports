<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * Builds a compact, semantic metric map (named label → value) from a report's resolved
 * blocks, so the AI reasons over named figures (e.g. "Visitas" => 6000) rather than
 * opaque block ids. Shared by the per-period narrative (ReportGenerator) and the AI
 * insights endpoint — both read the FROZEN resolved blocks, never a live API (§3.1).
 */
final class ReportFacts
{
    /**
     * @param  array<array-key, mixed>  $blocks  Resolved block array (id, binding, props).
     * @param  array<array-key, mixed>  $data  Resolved block-id → value map.
     * @return array<string, mixed>
     */
    public static function build(array $blocks, array $data, ?int $healthScore = null): array
    {
        $facts = [];

        if ($healthScore !== null) {
            $facts['health_score'] = $healthScore;
        }

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $id = $block['id'] ?? null;
            $binding = $block['binding'] ?? null;
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];

            if (! is_string($id) || ! is_array($binding) || ! array_key_exists($id, $data)) {
                continue;
            }

            $value = $data[$id];

            // KPI/sales cards with compare:prev_period resolve to {value, previous, ...};
            // pull the headline number. Tables/series (plain arrays) are skipped.
            if (is_array($value)) {
                $value = array_key_exists('value', $value) && ! is_array($value['value']) ? $value['value'] : null;
            }

            if ($value === null) {
                continue;
            }

            $label = $props['label'] ?? $props['title'] ?? ($binding['metric'] ?? 'metric');
            $facts[is_string($label) ? $label : 'metric'] = $value;
        }

        return $facts;
    }
}
