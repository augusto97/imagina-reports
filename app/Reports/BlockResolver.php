<?php

declare(strict_types=1);

namespace App\Reports;

use App\Reports\Blocks\Block;
use App\Reports\Blocks\BlockType;

/**
 * Resolves a validated block layout against a period's metric bags into the
 * `{blocks, data}` shape the shared BlockRenderer consumes (CLAUDE.md §10.4/§11.4).
 *
 * This is the single source of truth for block→data resolution: the ReportGenerator
 * uses it to freeze `ir_reports.resolved_blocks`, and the live editor preview uses it
 * to show REAL metric data — so what you design is exactly what the report renders.
 * Blocks whose bound metric has no data are gracefully hidden (§10.4).
 *
 * When a data block binds with `compare: prev_period`, the resolved value is enriched
 * with the previous period's value and the percent change so the renderer can draw a
 * professional KPI card (big number + trend vs previous period, §11.5).
 *
 * @phpstan-type MetricBags array<string, array<array-key, mixed>>
 * @phpstan-type Resolved array{blocks: list<array<string, mixed>>, data: array<string, mixed>}
 */
final readonly class BlockResolver
{
    private const HIDDEN = '__hidden__';

    /**
     * @param  list<Block>  $blocks
     * @param  MetricBags  $bags
     * @param  MetricBags  $previousBags  Equal-length prior period, for "vs previous" comparisons.
     * @return Resolved
     */
    public function resolve(array $blocks, array $bags, int $score, array $previousBags = []): array
    {
        $visibleBlocks = [];
        $data = [];

        foreach ($blocks as $block) {
            $value = $this->resolveBlock($block, $bags, $previousBags, $score);

            if ($value === self::HIDDEN) {
                continue;
            }

            $visibleBlocks[] = $block->toArray();

            if ($value !== null) {
                $data[$block->id] = $value;
            }
        }

        return ['blocks' => $visibleBlocks, 'data' => $data];
    }

    /**
     * @param  MetricBags  $bags
     * @param  MetricBags  $previousBags
     * @return mixed|self::HIDDEN resolved value, null (render from props), or HIDDEN
     */
    private function resolveBlock(Block $block, array $bags, array $previousBags, int $score): mixed
    {
        $binding = $block->binding;
        $source = is_array($binding) ? ($binding['source'] ?? null) : null;
        $metric = is_array($binding) ? ($binding['metric'] ?? null) : null;

        if (is_string($source) && is_string($metric)) {
            $key = "{$source}.{$metric}";
            $value = $bags[$source][$key] ?? null;

            if ($value === null) {
                return self::HIDDEN;
            }

            $compare = $binding['compare'] ?? null;

            if ($compare === 'prev_period' && is_numeric($value)) {
                return $this->withComparison($value, $previousBags[$source][$key] ?? null);
            }

            return $value;
        }

        return match ($block->type) {
            BlockType::HealthScore => $score,
            BlockType::WorklogTimeline => [], // populated from ir_report_work_logs later
            default => null,
        };
    }

    /**
     * @return array{value: mixed, previous: int|float|null, change_percent: float|null}
     */
    private function withComparison(mixed $value, mixed $previous): array
    {
        $current = is_numeric($value) ? (float) $value : 0.0;
        $previousValue = is_numeric($previous) ? $previous + 0 : null;

        $changePercent = $previousValue !== null && (float) $previousValue !== 0.0
            ? round((($current - (float) $previousValue) / abs((float) $previousValue)) * 100, 1)
            : null;

        return [
            'value' => $value,
            'previous' => $previousValue,
            'change_percent' => $changePercent,
        ];
    }
}
