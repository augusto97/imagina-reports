<?php

declare(strict_types=1);

namespace App\Reports;

use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Enums\ReportStatus;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Reports\Blocks\Block;
use App\Reports\Blocks\BlocksValidator;
use App\Reports\Blocks\BlockType;
use App\Reports\Templates\DefaultTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The GENERATE stage (CLAUDE.md §3.1/§10.4): resolves a definition's blocks against
 * the period's stored snapshots into `ir_reports.resolved_blocks` — never touching a
 * live API. Blocks whose bound metric has no data are gracefully hidden (§10.4); the
 * health score is computed with missing-signal re-weighting (§10.5).
 *
 * @phpstan-type MetricBags array<string, array<array-key, mixed>>
 */
final readonly class ReportGenerator
{
    public function __construct(
        private BlocksValidator $validator,
        private HealthScoreCalculator $health,
        private MaintenanceDeltaCalculator $maintenance,
    ) {}

    public function generate(ReportDefinition $definition, Period $period): Report
    {
        $blocks = $this->resolveLayout($definition);
        $bags = $this->loadBags($definition, $period);
        $score = $this->health->calculate($bags);

        $visibleBlocks = [];
        $data = [];

        foreach ($blocks as $block) {
            $value = $this->resolve($block, $bags, $score);

            if ($value === self::HIDDEN) {
                continue;
            }

            $visibleBlocks[] = $block->toArray();

            if ($value !== null) {
                $data[$block->id] = $value;
            }
        }

        return $this->persist($definition, $period, $visibleBlocks, $data, $score);
    }

    private const HIDDEN = '__hidden__';

    /**
     * @param  MetricBags  $bags
     * @return mixed|self::HIDDEN resolved value, null (render from props), or HIDDEN
     */
    private function resolve(Block $block, array $bags, int $score): mixed
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

    /**
     * @return list<Block>
     */
    private function resolveLayout(ReportDefinition $definition): array
    {
        $blocks = $definition->blocks;

        if (! is_array($blocks) || $blocks === []) {
            $template = $definition->template;
            $blocks = $template !== null && $template->blocks !== []
                ? $template->blocks
                : DefaultTemplate::blocks();
        }

        return $this->validator->validate($blocks);
    }

    /**
     * Build the source-key → metric-bag map from the period's snapshots for the
     * definition's site. A computed `mainwp.updates_applied` is injected from the
     * maintenance delta (§9) so the "updates applied" KPI has a value.
     *
     * @return MetricBags
     */
    private function loadBags(ReportDefinition $definition, Period $period): array
    {
        $bags = [];

        $sources = DataSource::query()->where('site_id', $definition->site_id)->get();

        foreach ($sources as $source) {
            $snapshot = MetricSnapshot::query()
                ->where('data_source_id', $source->id)
                ->where('period_start', '>=', $period->start)
                ->where('period_end', '<=', $period->end)
                ->orderByDesc('period_end')
                ->first();

            if ($snapshot === null) {
                continue;
            }

            $metrics = $snapshot->payload['metrics'] ?? [];

            if (! is_array($metrics)) {
                continue;
            }

            if ($source->type === DataSourceType::MainWp) {
                $delta = $this->maintenance->forDataSource($source->id, $period);

                if ($delta !== null) {
                    $metrics['mainwp.updates_applied'] = $delta->updatesApplied;
                }
            }

            $bags[$source->type->value] = $metrics;
        }

        return $bags;
    }

    /**
     * @param  list<array<string, mixed>>  $visibleBlocks
     * @param  array<string, mixed>  $data
     */
    private function persist(ReportDefinition $definition, Period $period, array $visibleBlocks, array $data, int $score): Report
    {
        $report = new Report;
        $report->agency_id = $definition->agency_id;
        $report->report_definition_id = $definition->id;
        $report->period_start = Carbon::instance($period->start);
        $report->period_end = Carbon::instance($period->end);
        $report->resolved_blocks = ['blocks' => $visibleBlocks, 'data' => $data];
        $report->health_score = $score;
        $report->executive_summary = null; // AI narrative is Phase 2
        $report->public_token = Str::random(48);
        $report->status = ReportStatus::Draft;
        $report->save();

        return $report;
    }
}
