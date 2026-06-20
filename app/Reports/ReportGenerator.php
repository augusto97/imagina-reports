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
use App\Reports\Calc\CalculatedMetrics;
use App\Reports\Templates\DefaultTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * The GENERATE stage (CLAUDE.md §3.1/§10.4): resolves a definition's blocks against
 * the period's stored snapshots into `ir_reports.resolved_blocks` — never touching a
 * live API. Blocks whose bound metric has no data are gracefully hidden (§10.4); the
 * health score is computed with missing-signal re-weighting (§10.5).
 */
final readonly class ReportGenerator
{
    public function __construct(
        private BlocksValidator $validator,
        private HealthScoreCalculator $health,
        private MetricBagLoader $bags,
        private BlockResolver $resolver,
        private CalculatedMetrics $calculated,
        private WorkLogMetrics $workLogs,
    ) {}

    public function generate(ReportDefinition $definition, Period $period): Report
    {
        $blocks = $this->resolveLayout($definition);
        $bags = $this->bags->forSite($definition->site_id, $period);
        $previousBags = $this->bags->previousForSite($definition->site_id, $period);

        // Work-log metrics (hours invested, tasks, by-category, vs plan) as `worklog`.
        $site = $definition->site;
        if ($site !== null) {
            $bags = $this->withWorkLogs($bags, $this->workLogs->forSite($site, $period));
            $previousBags = $this->withWorkLogs($previousBags, $this->workLogs->forSite($site, $period->previous()));
        }

        // Calculated metrics (formulas over the bag) injected as a `calc` source.
        $calcDefs = $this->calcDefinitions($definition);
        $bags = $this->withCalc($bags, $this->calculated->compute($calcDefs, $bags));
        $previousBags = $this->withCalc($previousBags, $this->calculated->compute($calcDefs, $previousBags));

        $score = $this->health->calculate($bags);

        // Resolve blocks→data via the shared resolver — the same logic the live
        // editor preview uses, so the preview matches the generated report exactly.
        ['blocks' => $visibleBlocks, 'data' => $data] = $this->resolver->resolve($blocks, $bags, $score, $previousBags);

        // Per-report theme (accent + density): the definition's, falling back to its template's.
        $theme = $definition->theme ?? $definition->template?->theme;

        $report = $this->persist($definition, $period, $visibleBlocks, $data, $score, is_array($theme) ? $theme : null);

        // Fire the lifecycle event (CLAUDE.md §8): listeners emit the report.generated
        // webhook and run anomaly detection — keeping this generator free of delivery
        // concerns.
        Event::dispatch(new ReportGenerated($report));

        return $report;
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
     * The definition's calculated-metric formulas, falling back to its template's.
     *
     * @return array<int, array<array-key, mixed>>
     */
    private function calcDefinitions(ReportDefinition $definition): array
    {
        $defs = $definition->calculated_metrics;

        if ($defs === null || $defs === []) {
            $template = $definition->template;
            $defs = $template !== null ? ($template->calculated_metrics ?? []) : [];
        }

        return array_values(array_filter($defs, 'is_array'));
    }

    /**
     * @param  array<string, array<array-key, mixed>>  $bags
     * @param  array<string, int|float>  $calc
     * @return array<string, array<array-key, mixed>>
     */
    private function withCalc(array $bags, array $calc): array
    {
        if ($calc !== []) {
            $bags['calc'] = $calc;
        }

        return $bags;
    }

    /**
     * @param  array<string, array<array-key, mixed>>  $bags
     * @param  array<string, mixed>  $worklog
     * @return array<string, array<array-key, mixed>>
     */
    private function withWorkLogs(array $bags, array $worklog): array
    {
        if ($worklog !== []) {
            $bags['worklog'] = $worklog;
        }

        return $bags;
    }

    /**
     * @param  list<array<string, mixed>>  $visibleBlocks
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $theme
     */
    private function persist(ReportDefinition $definition, Period $period, array $visibleBlocks, array $data, int $score, ?array $theme = null): Report
    {
        $report = new Report;
        $report->agency_id = $definition->agency_id;
        $report->report_definition_id = $definition->id;
        $report->period_start = Carbon::instance($period->start);
        $report->period_end = Carbon::instance($period->end);
        $report->resolved_blocks = ['blocks' => $visibleBlocks, 'data' => $data, 'theme' => $theme];
        $report->health_score = $score;
        $report->executive_summary = null; // AI narrative is Phase 2
        $report->public_token = Str::random(48);
        $report->status = ReportStatus::Draft;
        $report->save();

        return $report;
    }
}
