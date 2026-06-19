<?php

declare(strict_types=1);

namespace App\Reports;

use App\Connectors\Period;
use App\Enums\ReportStatus;
use App\Events\ReportGenerated;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Reports\Blocks\Block;
use App\Reports\Blocks\BlocksValidator;
use App\Reports\Blocks\BlockType;
use App\Reports\Templates\DefaultTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
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
        private MetricBagLoader $bags,
    ) {}

    public function generate(ReportDefinition $definition, Period $period): Report
    {
        $blocks = $this->resolveLayout($definition);
        $bags = $this->bags->forSite($definition->site_id, $period);
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

        $report = $this->persist($definition, $period, $visibleBlocks, $data, $score);

        // Fire the lifecycle event (CLAUDE.md §8): listeners emit the report.generated
        // webhook and run anomaly detection — keeping this generator free of delivery
        // concerns.
        Event::dispatch(new ReportGenerated($report));

        return $report;
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
