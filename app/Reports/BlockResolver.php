<?php

declare(strict_types=1);

namespace App\Reports;

use App\Reports\Blocks\Block;
use App\Reports\Blocks\BlockType;
use App\Reports\Datasets\DatasetEngine;
use App\Reports\Datasets\DatasetFilter;
use App\Reports\Datasets\DatasetQuery;

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
 * @phpstan-type Diagnostic array{id: string, type: string, source: string, metric: string}
 * @phpstan-type Resolved array{blocks: list<array<string, mixed>>, data: array<string, mixed>, diagnostics: list<Diagnostic>}
 */
final readonly class BlockResolver
{
    private const HIDDEN = '__hidden__';

    public function __construct(private DatasetEngine $engine = new DatasetEngine) {}

    /**
     * @param  list<Block>  $blocks
     * @param  MetricBags  $bags
     * @param  MetricBags  $previousBags  Equal-length prior period, for "vs previous" comparisons.
     * @param  array<array-key, list<array<array-key, mixed>>>  $filtersByPage  Page/dashboard
     *                                                                          filters keyed by page index, plus 'all' applied to every page (CLAUDE.md §10
     *                                                                          dashboards). Block-level filters override these per dimension.
     * @param  array<string, mixed>|null  $health  The health score's breakdown (HealthScoreCalculator::breakdown), shown by the gauge.
     * @param  string  $locale  For readable hour/weekday labels.
     * @return Resolved
     */
    public function resolve(array $blocks, array $bags, int $score, array $previousBags = [], array $filtersByPage = [], ?array $health = null, string $locale = 'es'): array
    {
        $visibleBlocks = [];
        $data = [];
        $diagnostics = [];

        foreach ($blocks as $block) {
            $value = $this->resolveBlock($block, $bags, $previousBags, $score, $filtersByPage, $health, $locale);

            if ($value === self::HIDDEN) {
                // A bound block disappeared because its metric had no data for the period.
                // Record it so the agency can see exactly what's missing (no silent gaps).
                $binding = $block->binding;
                $source = is_array($binding) ? ($binding['source'] ?? null) : null;
                $metric = is_array($binding) ? ($binding['metric'] ?? null) : null;

                if (is_string($source) && is_string($metric)) {
                    $diagnostics[] = [
                        'id' => $block->id,
                        'type' => $block->type->value,
                        'source' => $source,
                        'metric' => $metric,
                    ];
                }

                continue;
            }

            $visibleBlocks[] = $block->toArray();

            if ($value !== null) {
                $data[$block->id] = $value;
            }
        }

        return ['blocks' => $visibleBlocks, 'data' => $data, 'diagnostics' => $diagnostics];
    }

    /**
     * @param  MetricBags  $bags
     * @param  MetricBags  $previousBags
     * @param  array<array-key, list<array<array-key, mixed>>>  $filtersByPage
     * @param  array<string, mixed>|null  $health  The health score's breakdown (signals), for the gauge block.
     * @return mixed|self::HIDDEN resolved value, null (render from props), or HIDDEN
     */
    private function resolveBlock(Block $block, array $bags, array $previousBags, int $score, array $filtersByPage = [], ?array $health = null, string $locale = 'es'): mixed
    {
        $binding = $block->binding;
        $source = is_array($binding) ? ($binding['source'] ?? null) : null;
        $metric = is_array($binding) ? ($binding['metric'] ?? null) : null;

        if (is_string($source) && is_string($metric)) {
            $key = "{$source}.{$metric}";
            // Hour/weekday series read as random numbers raw: give them order and names.
            $value = SeriesLabels::apply($key, $bags[$source][$key] ?? null, $locale);

            if ($value === null) {
                return self::HIDDEN;
            }

            // Dataset binding (has breakdown/measure): shape the stored rows with the
            // block's query, merged with the page/dashboard filter cascade (§10).
            $query = DatasetQuery::fromBinding($binding);
            if ($query->isDataset() && is_array($value)) {
                return $this->engine->shape($value, $query, $this->pageFiltersFor($filtersByPage, $block->page));
            }

            $compare = $binding['compare'] ?? null;

            if ($compare === 'prev_period' && is_numeric($value)) {
                $resolved = $this->withComparison($value, $previousBags[$source][$key] ?? null);

                // The day-by-day series behind the number, when the source has one: the KPI
                // card draws it as a sparkline (a number + a mini visual, §11.5).
                $series = $this->sparkline($bags[$source]["{$key}_by_date"] ?? null);
                if ($series !== null) {
                    $resolved['series'] = $series;
                }

                return $resolved;
            }

            return $value;
        }

        return match ($block->type) {
            // With the breakdown, the block can say what the score is made of (not just a number).
            // No source measures anything yet: hide the gauge rather than show a 100 that means nothing.
            BlockType::HealthScore => $health === null ? $score : (($health['signals'] ?? []) === [] ? self::HIDDEN : ['score' => $score, ...$health]),
            BlockType::SecurityShield => $this->securityMetrics($bags),
            BlockType::WorklogTimeline => [], // populated from ir_report_work_logs later
            default => null,
        };
    }

    /**
     * Effective page-level filters for a block: the dashboard-global 'all' set merged
     * with the block's own page set (the page-specific filter wins per dimension). The
     * block's own filters then override these inside the DatasetEngine.
     *
     * @param  array<array-key, list<array<array-key, mixed>>>  $filtersByPage
     * @return list<DatasetFilter>
     */
    private function pageFiltersFor(array $filtersByPage, int $page): array
    {
        $all = $this->parseFilters($filtersByPage['all'] ?? []);
        $pageSpecific = $this->parseFilters($filtersByPage[$page] ?? []);

        return $this->engine->cascade($all, $pageSpecific);
    }

    /**
     * @return list<DatasetFilter>
     */
    private function parseFilters(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $filters = [];
        foreach ($raw as $entry) {
            $filter = DatasetFilter::fromArray($entry);
            if ($filter !== null) {
                $filters[] = $filter;
            }
        }

        return $filters;
    }

    /**
     * Collect the security headline numbers the shield block shows (§11.5) from the
     * connectors that expose them — only those actually present, so a client without
     * a given source simply doesn't show that stat. Returns null when none are
     * available (the renderer then shows the reassuring default).
     *
     * @param  MetricBags  $bags
     * @return array<string, int|float>|null
     */
    private function securityMetrics(array $bags): ?array
    {
        $wanted = [
            'threats_blocked' => 'cloudflare.threats_blocked',
            'attacks_blocked' => 'crowdsec.attacks_blocked',
            'malware_found' => 'mainwp.malware_found',
        ];

        $out = [];

        foreach ($wanted as $label => $key) {
            $source = explode('.', $key)[0];
            $value = $bags[$source][$key] ?? null;

            if (is_numeric($value)) {
                $out[$label] = $value + 0;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * A KPI's sparkline: the series' values in date order, averaged down to at most 31
     * points so a long range stays a light payload. Null when there's no usable series.
     *
     * @return list<float>|null
     */
    private function sparkline(mixed $series): ?array
    {
        if (! is_array($series) || count($series) < 2) {
            return null;
        }

        $points = [];
        foreach ($series as $row) {
            if (! is_array($row) || ! is_numeric($row['value'] ?? null)) {
                continue;
            }
            $date = $row['date'] ?? $row['label'] ?? $row['name'] ?? '';
            $points[] = ['at' => is_scalar($date) ? (string) $date : '', 'value' => (float) $row['value']];
        }
        if (count($points) < 2) {
            return null;
        }
        usort($points, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']));
        $values = array_column($points, 'value');

        $max = 31;
        if (count($values) <= $max) {
            return $values;
        }

        $out = [];
        $size = count($values) / $max;
        for ($i = 0; $i < $max; $i++) {
            $chunk = array_slice($values, (int) floor($i * $size), max(1, (int) floor(($i + 1) * $size) - (int) floor($i * $size)));
            $out[] = round(array_sum($chunk) / count($chunk), 2);
        }

        return $out;
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
