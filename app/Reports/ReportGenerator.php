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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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
        private AiReportBuilder $ai,
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
        // Page/dashboard filters cascade in; each block's own filters then override them.
        ['blocks' => $visibleBlocks, 'data' => $data, 'diagnostics' => $diagnostics] = $this->resolver->resolve($blocks, $bags, $score, $previousBags, $this->pageFilters($definition));

        // AI per-period narrative (§10.6): regenerate the executive-summary text from the
        // resolved figures and inject it into the report's narrative block(s). This is the
        // one acknowledged exception to "no external APIs during GENERATE" (§3.1/§10.6); it
        // is fully resilient — a failure leaves the summary empty and never breaks the report.
        $summary = $this->narrative($definition, $visibleBlocks, $data, $score);
        if ($summary !== null) {
            $data = ExecutiveSummary::inject($visibleBlocks, $data, $summary);
        }

        // Per-report theme (accent + density): the definition's, falling back to its template's.
        $theme = $definition->theme ?? $definition->template?->theme;

        $report = $this->persist($definition, $period, $visibleBlocks, $data, $score, is_array($theme) ? $theme : null, $diagnostics, $summary);

        // Fire the lifecycle event (CLAUDE.md §8): listeners emit the report.generated
        // webhook and run anomaly detection — keeping this generator free of delivery
        // concerns.
        Event::dispatch(new ReportGenerated($report));

        return $report;
    }

    /**
     * Page/dashboard filters for the resolver cascade: the definition's, falling back to
     * its template's. Shape: { all|<pageIndex> : [{dimension, op, value}, ...] }.
     *
     * @return array<array-key, list<array<array-key, mixed>>>
     */
    private function pageFilters(ReportDefinition $definition): array
    {
        $filters = $definition->filters ?? $definition->template?->filters;

        return is_array($filters) ? $filters : [];
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
     * Generate the AI executive-summary text for the period from the resolved figures,
     * in the report's locale — but only when the layout has an AI-fillable summary block
     * and there is data to summarize. Returns null (no narrative) on any failure, an empty
     * result, or when there's nothing to write — never throwing, so GENERATE always finishes.
     *
     * @param  list<array<string, mixed>>  $visibleBlocks
     * @param  array<string, mixed>  $data
     */
    private function narrative(ReportDefinition $definition, array $visibleBlocks, array $data, int $score): ?string
    {
        if (! ExecutiveSummary::present($visibleBlocks)) {
            return null;
        }

        $facts = ReportFacts::build($visibleBlocks, $data, $score);

        if ($facts === []) {
            return null;
        }

        try {
            $text = $this->ai->narrative($facts, $this->locale($definition));
        } catch (Throwable $e) {
            Log::warning('Report narrative generation failed; leaving the summary empty.', [
                'definition_id' => $definition->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $text === '' ? null : $text;
    }

    /**
     * The report's locale: the definition's, falling back to the agency default, then 'es'.
     */
    private function locale(ReportDefinition $definition): string
    {
        if ($definition->locale !== '') {
            return $definition->locale;
        }

        $agencyLocale = $definition->agency?->default_locale;

        return is_string($agencyLocale) && $agencyLocale !== '' ? $agencyLocale : 'es';
    }

    /**
     * @param  list<array<string, mixed>>  $visibleBlocks
     * @param  array<array-key, mixed>  $data
     * @param  array<string, mixed>|null  $theme
     * @param  list<array<string, mixed>>  $diagnostics
     */
    private function persist(ReportDefinition $definition, Period $period, array $visibleBlocks, array $data, int $score, ?array $theme = null, array $diagnostics = [], ?string $summary = null): Report
    {
        $report = new Report;
        $report->agency_id = $definition->agency_id;
        $report->report_definition_id = $definition->id;
        $report->period_start = Carbon::instance($period->start);
        $report->period_end = Carbon::instance($period->end);
        $report->resolved_blocks = ['blocks' => $visibleBlocks, 'data' => $data, 'theme' => $theme, 'diagnostics' => $diagnostics];
        $report->health_score = $score;
        $report->executive_summary = $summary; // AI per-period narrative (§10.6), null if unavailable
        $report->public_token = Str::random(48);
        $report->status = ReportStatus::Draft;
        $report->save();

        return $report;
    }
}
