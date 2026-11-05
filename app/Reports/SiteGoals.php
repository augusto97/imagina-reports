<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\SiteGoal;

/**
 * Fills the «Metas» block (Etapa 4) with the SITE's goals measured against the period's bags.
 * The block lives in the template; the goals live in the site — so one linked template shows
 * every client their own targets. A goals block with nothing to show is removed, like any
 * block without data (§10.4).
 *
 * @phpstan-type Goal array{label: string, value: float, target: float, direction: string, format: string|null, progress: float, met: bool}
 */
final class SiteGoals
{
    /**
     * @param  list<array<string, mixed>>  $blocks  Resolved visible blocks.
     * @param  array<string, mixed>  $data
     * @param  array<string, array<array-key, mixed>>  $bags
     * @return array{blocks: list<array<string, mixed>>, data: array<string, mixed>}
     */
    public static function apply(?int $siteId, array $blocks, array $data, array $bags): array
    {
        if (! self::present($blocks)) {
            return ['blocks' => $blocks, 'data' => $data];
        }

        $goals = $siteId === null ? [] : self::measure(
            SiteGoal::query()->withoutGlobalScopes()->where('site_id', $siteId)->orderBy('position')->orderBy('id')->get(),
            $bags,
        );

        $kept = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) !== 'goals') {
                $kept[] = $block;

                continue;
            }
            if ($goals === [] || ! is_string($block['id'] ?? null)) {
                continue;
            }
            $kept[] = $block;
            $data[$block['id']] = $goals;
        }

        return ['blocks' => $kept, 'data' => $data];
    }

    /**
     * Each goal's value this period; goals whose metric has no data are left out.
     *
     * @param  iterable<SiteGoal>  $goals
     * @param  array<string, array<array-key, mixed>>  $bags
     * @return list<Goal>
     */
    public static function measure(iterable $goals, array $bags): array
    {
        $out = [];
        foreach ($goals as $goal) {
            $raw = $bags[$goal->source][$goal->source.'.'.$goal->metric] ?? null;
            if (is_array($raw)) {
                $raw = $raw['value'] ?? null;
            }
            if (! is_numeric($raw)) {
                continue;
            }

            $value = round((float) $raw, 4);
            $target = (float) $goal->target;
            $atMost = $goal->direction === SiteGoal::AT_MOST;

            $out[] = [
                'label' => $goal->label,
                'value' => $value,
                'target' => $target,
                'direction' => $atMost ? SiteGoal::AT_MOST : SiteGoal::AT_LEAST,
                'format' => $goal->format,
                'progress' => $target > 0 ? round($value / $target * 100, 1) : ($value > 0 ? 100.0 : 0.0),
                'met' => $atMost ? $value <= $target : $value >= $target,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private static function present(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'goals') {
                return true;
            }
        }

        return false;
    }
}
