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
        ['blocks' => $visibleBlocks, 'data' => $data, 'health_score' => $score, 'theme' => $theme, 'diagnostics' => $diagnostics, 'pages' => $pages, 'bags' => $bags] = $this->compose($definition, $period);

        // AI per-period narrative (§10.6): regenerate the executive-summary text from the
        // resolved figures and inject it into the report's narrative block(s). This is the
        // one acknowledged exception to "no external APIs during GENERATE" (§3.1/§10.6); it
        // is fully resilient — a failure leaves the summary empty and never breaks the report.
        $summary = $this->narrative($definition, $visibleBlocks, $data, $score);
        if ($summary !== null) {
            $data = ExecutiveSummary::inject($visibleBlocks, $data, $summary);
        }

        // AI advisory "site condition" insight (§10.6 added value): a consultative diagnosis
        // that also reads the previous period, the maintenance/updates done, downtime and the
        // multi-month health trend, recommending a follow-up only when the data warrants it.
        // Same resilience as the narrative — a failure just leaves the block empty.
        if (AdvisoryInsight::present($visibleBlocks)) {
            $advisory = $this->advisory($definition, $visibleBlocks, $data, $score, $bags, $period);
            if ($advisory !== null) {
                $data = AdvisoryInsight::inject($visibleBlocks, $data, $advisory);
            }
        }

        $report = $this->persist($definition, $period, $visibleBlocks, $data, $score, $theme, $diagnostics, $summary, $pages);

        // Fire the lifecycle event (CLAUDE.md §8): listeners emit the report.generated
        // webhook and run anomaly detection — keeping this generator free of delivery
        // concerns.
        Event::dispatch(new ReportGenerated($report));

        return $report;
    }

    /**
     * Resolve a definition's blocks against a period's snapshots WITHOUT persisting —
     * the live dashboard mode (CLAUDE.md §11.2/Etapa D). Same composition as generate(),
     * but no AI narrative, no persistence, and no lifecycle event, so it's cheap enough
     * to run per request as the client changes the date range. Still reads only stored
     * snapshots (§3.1) — never a live API.
     *
     * @return array{blocks: list<array<string, mixed>>, data: array<string, mixed>, health_score: int, theme: array<string, mixed>|null, diagnostics: list<array<string, mixed>>, pages: list<array<string, mixed>>, bags: array<string, array<array-key, mixed>>}
     */
    public function resolveLive(ReportDefinition $definition, Period $period): array
    {
        return $this->compose($definition, $period);
    }

    /**
     * The shared resolution core (CLAUDE.md §10.4): layout → bags (with work logs +
     * calculated metrics) → health score → resolved blocks. Used by both the persisted
     * GENERATE stage and the live dashboard.
     *
     * @return array{blocks: list<array<string, mixed>>, data: array<string, mixed>, health_score: int, theme: array<string, mixed>|null, diagnostics: list<array<string, mixed>>, pages: list<array<string, mixed>>, bags: array<string, array<array-key, mixed>>}
     */
    private function compose(ReportDefinition $definition, Period $period): array
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

        // Per-report theme (accent + density): the definition's, falling back to its template's.
        $theme = $definition->theme ?? $definition->template?->theme;

        return [
            'blocks' => $visibleBlocks,
            'data' => $data,
            'health_score' => $score,
            'theme' => is_array($theme) ? $theme : null,
            'diagnostics' => $diagnostics,
            'pages' => $this->pageNames($definition),
            'bags' => $bags,
        ];
    }

    /**
     * Generate the AI advisory text (CLAUDE.md §10.6) from a rich fact set — current figures,
     * change vs. the previous period, the multi-month health trend, the maintenance done and
     * uptime/incidents. Returns null (no advisory) on any failure or empty result, never
     * throwing, so GENERATE always finishes.
     *
     * @param  list<array<string, mixed>>  $visibleBlocks
     * @param  array<array-key, mixed>  $data
     * @param  array<string, array<array-key, mixed>>  $bags
     */
    private function advisory(ReportDefinition $definition, array $visibleBlocks, array $data, int $score, array $bags, Period $period): ?string
    {
        $facts = $this->advisoryFacts($definition, $visibleBlocks, $data, $score, $bags, $period);

        if ($facts === []) {
            return null;
        }

        try {
            $text = $this->ai->advisory($facts, $this->locale($definition));
        } catch (Throwable $e) {
            Log::warning('Report advisory generation failed; leaving the block empty.', [
                'definition_id' => $definition->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $text === '' ? null : $text;
    }

    /**
     * The fact set fed to the advisory prompt: the resolved headline figures, the KPI changes
     * vs. the previous period, the multi-month health trend, the maintenance done and the
     * uptime/incidents — all read from already-stored data (§3.1).
     *
     * @param  list<array<string, mixed>>  $visibleBlocks
     * @param  array<array-key, mixed>  $data
     * @param  array<string, array<array-key, mixed>>  $bags
     * @return array<string, mixed>
     */
    private function advisoryFacts(ReportDefinition $definition, array $visibleBlocks, array $data, int $score, array $bags, Period $period): array
    {
        $facts = ReportFacts::build($visibleBlocks, $data, $score);

        $changes = [];
        foreach ($visibleBlocks as $block) {
            $type = $block['type'] ?? null;
            if ($type !== 'kpi' && $type !== 'sales_summary') {
                continue;
            }
            $id = $block['id'] ?? null;
            if (! is_string($id) || ! array_key_exists($id, $data)) {
                continue;
            }
            $value = $data[$id];
            if (is_array($value) && isset($value['change_percent']) && is_numeric($value['change_percent'])) {
                $changes[$this->blockLabel($block)] = round((float) $value['change_percent'], 1);
            }
        }
        if ($changes !== []) {
            $facts['cambio_vs_anterior_pct'] = $changes;
        }

        $trend = $this->healthTrend($definition, $period, $score);
        if (count($trend) > 1) {
            $facts['tendencia_salud'] = $trend;
        }

        $maintenance = $this->maintenanceFacts($bags);
        if ($maintenance !== []) {
            $facts['mantenimiento'] = $maintenance;
        }

        $uptime = $this->uptimeFacts($bags);
        if ($uptime !== []) {
            $facts['disponibilidad'] = $uptime;
        }

        return $facts;
    }

    /**
     * The site's health score over the last few periods (prior reports + the current score),
     * so the advisory can speak to a trend rather than a single month.
     *
     * @return list<array{periodo: string, salud: int|null}>
     */
    private function healthTrend(ReportDefinition $definition, Period $period, int $score): array
    {
        $prior = Report::query()
            ->where('report_definition_id', $definition->id)
            ->where('period_end', '<', Carbon::instance($period->end))
            ->orderByDesc('period_end')
            ->take(5)
            ->get();

        $trend = [];
        foreach ($prior->sortBy('period_end') as $report) {
            $trend[] = ['periodo' => $report->period_end->format('Y-m'), 'salud' => $report->health_score];
        }
        $trend[] = ['periodo' => Carbon::instance($period->end)->format('Y-m'), 'salud' => $score];

        return $trend;
    }

    /**
     * Maintenance done this period for the advisory: updates applied, support hours/tasks, and
     * the elements actually updated (from the agent's local history or MainWP's work log).
     *
     * @param  array<string, array<array-key, mixed>>  $bags
     * @return array<string, mixed>
     */
    private function maintenanceFacts(array $bags): array
    {
        $maintenance = [];

        $applied = $this->bagNum($bags, 'mainwp', 'mainwp.updates_applied') ?? $this->bagNum($bags, 'site_agent', 'site_agent.updates_applied');
        if ($applied !== null) {
            $maintenance['actualizaciones_aplicadas'] = (int) $applied;
        }

        $hours = $this->bagNum($bags, 'worklog', 'worklog.hours');
        if ($hours !== null) {
            $maintenance['horas_soporte'] = $hours;
        }

        $tasks = $this->bagNum($bags, 'worklog', 'worklog.tasks');
        if ($tasks !== null) {
            $maintenance['tareas'] = (int) $tasks;
        }

        $log = $bags['site_agent']['site_agent.updates_log'] ?? $bags['mainwp']['mainwp.work_log'] ?? null;
        if (is_array($log)) {
            $items = [];
            foreach (array_slice($log, 0, 8) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $element = $row['Elemento'] ?? null;
                $version = $row['Versión'] ?? null;
                if (is_string($element)) {
                    $items[] = is_string($version) && $version !== '' ? $element.' ('.$version.')' : $element;
                }
            }
            if ($items !== []) {
                $maintenance['elementos'] = $items;
            }
        }

        return $maintenance;
    }

    /**
     * Uptime/incidents for the advisory (so it can flag downtime under a traffic spike).
     *
     * @param  array<string, array<array-key, mixed>>  $bags
     * @return array<string, mixed>
     */
    private function uptimeFacts(array $bags): array
    {
        $uptime = [];

        $percent = $this->bagNum($bags, 'betteruptime', 'betteruptime.uptime_percent');
        if ($percent !== null) {
            $uptime['uptime_percent'] = $percent;
        }

        $incidents = $this->bagNum($bags, 'betteruptime', 'betteruptime.incidents');
        if ($incidents !== null) {
            $uptime['incidentes'] = (int) $incidents;
        }

        $downtime = $this->bagNum($bags, 'betteruptime', 'betteruptime.total_downtime');
        if ($downtime !== null) {
            $uptime['tiempo_caido_seg'] = (int) $downtime;
        }

        return $uptime;
    }

    /**
     * A metric's numeric value from the bag, or null when absent/non-numeric.
     *
     * @param  array<string, array<array-key, mixed>>  $bags
     */
    private function bagNum(array $bags, string $source, string $key): int|float|null
    {
        $value = $bags[$source][$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function blockLabel(array $block): string
    {
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        $binding = is_array($block['binding'] ?? null) ? $block['binding'] : [];
        $label = $props['label'] ?? $props['title'] ?? ($binding['metric'] ?? 'metric');

        return is_string($label) ? $label : 'metric';
    }

    /**
     * The report's named pages (CLAUDE.md §11 — Looker/Power-BI parity): the definition's,
     * falling back to its template's. Shape: list of {name} indexed by the blocks' page
     * index; drives the interactive page-navigation menu. Sanitised to the {name} shape so
     * a hand-edited payload can't smuggle other keys into the frozen report.
     *
     * @return list<array<string, mixed>>
     */
    private function pageNames(ReportDefinition $definition): array
    {
        $pages = $definition->pages ?? $definition->template?->pages;

        if (! is_array($pages)) {
            return [];
        }

        $result = [];
        foreach ($pages as $page) {
            $name = $page['name'] ?? null;
            $result[] = ['name' => is_string($name) ? $name : ''];
        }

        return $result;
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
     * The calculated-metric formulas in effect: the agency's reusable ones (CLAUDE.md §10.1)
     * merged with the definition's own (falling back to its template's), where a report-level
     * metric overrides an agency one with the same key.
     *
     * @return array<int, array<array-key, mixed>>
     */
    private function calcDefinitions(ReportDefinition $definition): array
    {
        $reportDefs = $definition->calculated_metrics;

        if ($reportDefs === null || $reportDefs === []) {
            $template = $definition->template;
            $reportDefs = $template !== null ? ($template->calculated_metrics ?? []) : [];
        }

        $agencyDefs = $definition->agency->calculated_metrics ?? [];
        $siteDefs = $definition->site->calculated_metrics ?? [];

        // Precedence (more specific wins): agency → site → report.
        return self::mergeCalcDefinitions(
            array_values(array_filter($agencyDefs, 'is_array')),
            array_values(array_filter($siteDefs, 'is_array')),
            array_values(array_filter($reportDefs, 'is_array')),
        );
    }

    /**
     * Merge calc-metric lists by key, where LATER lists win on a key clash — so the order
     * agency → site → report makes the more specific scope override the broader one.
     *
     * @param  array<int, array<array-key, mixed>>  ...$lists
     * @return array<int, array<array-key, mixed>>
     */
    public static function mergeCalcDefinitions(array ...$lists): array
    {
        $byKey = [];
        foreach ($lists as $list) {
            foreach ($list as $def) {
                $key = $def['key'] ?? null;
                if (is_string($key) && $key !== '') {
                    $byKey[$key] = $def;
                }
            }
        }

        return array_values($byKey);
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
     * @param  list<array<string, mixed>>  $pages
     */
    private function persist(ReportDefinition $definition, Period $period, array $visibleBlocks, array $data, int $score, ?array $theme = null, array $diagnostics = [], ?string $summary = null, array $pages = []): Report
    {
        $report = new Report;
        $report->agency_id = $definition->agency_id;
        $report->report_definition_id = $definition->id;
        $report->period_start = Carbon::instance($period->start);
        $report->period_end = Carbon::instance($period->end);
        $report->resolved_blocks = ['blocks' => $visibleBlocks, 'data' => $data, 'theme' => $theme, 'diagnostics' => $diagnostics, 'pages' => $pages];
        $report->health_score = $score;
        $report->executive_summary = $summary; // AI per-period narrative (§10.6), null if unavailable
        $report->public_token = Str::random(48);
        $report->status = ReportStatus::Draft;
        $report->save();

        return $report;
    }
}
