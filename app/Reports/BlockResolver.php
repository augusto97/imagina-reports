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
 * @phpstan-type MetricBags array<string, array<array-key, mixed>>
 * @phpstan-type Resolved array{blocks: list<array<string, mixed>>, data: array<string, mixed>}
 */
final readonly class BlockResolver
{
    private const HIDDEN = '__hidden__';

    /**
     * @param  list<Block>  $blocks
     * @param  MetricBags  $bags
     * @return Resolved
     */
    public function resolve(array $blocks, array $bags, int $score): array
    {
        $visibleBlocks = [];
        $data = [];

        foreach ($blocks as $block) {
            $value = $this->resolveBlock($block, $bags, $score);

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
     * @return mixed|self::HIDDEN resolved value, null (render from props), or HIDDEN
     */
    private function resolveBlock(Block $block, array $bags, int $score): mixed
    {
        $binding = $block->binding;
        $source = is_array($binding) ? ($binding['source'] ?? null) : null;
        $metric = is_array($binding) ? ($binding['metric'] ?? null) : null;

        if (is_string($source) && is_string($metric)) {
            $value = $bags[$source]["{$source}.{$metric}"] ?? null;

            return $value ?? self::HIDDEN;
        }

        return match ($block->type) {
            BlockType::HealthScore => $score,
            BlockType::WorklogTimeline => [], // populated from ir_report_work_logs later
            default => null,
        };
    }
}
