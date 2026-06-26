<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\Period;
use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewReportRequest;
use App\Jobs\SyncSourceJob;
use App\Models\Site;
use App\Reports\BlockResolver;
use App\Reports\Blocks\BlocksValidator;
use App\Reports\Calc\CalculatedMetrics;
use App\Reports\HealthScoreCalculator;
use App\Reports\MetricBagLoader;
use App\Reports\ReportGenerator;
use App\Reports\WorkLogMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Live editor preview (CLAUDE.md §11.3/§11.4): resolves a draft block layout
 * against a site's stored snapshots for a period into REAL metric data — without
 * persisting an `ir_reports` row. Reuses the exact same BlockResolver the
 * ReportGenerator uses, so the editor preview matches the generated report.
 *
 * The `sync` action triggers an on-demand SYNC of the site's data sources so a
 * freshly-configured site can show real data immediately ("Sincronizar ahora").
 */
final class PreviewController extends Controller
{
    public function preview(
        PreviewReportRequest $request,
        Site $site,
        BlocksValidator $validator,
        MetricBagLoader $loader,
        HealthScoreCalculator $health,
        BlockResolver $resolver,
        CalculatedMetrics $calculated,
        WorkLogMetrics $workLogs,
    ): JsonResponse {
        $period = $this->resolvePeriod($request);
        $blocks = $validator->validate($request->input('blocks'));

        $bags = $loader->forSite($site->id, $period);
        $previousBags = $loader->previousForSite($site->id, $period);

        // Work-log metrics (hours invested, tasks, by-category, vs plan) as `worklog`.
        $worklog = $workLogs->forSite($site, $period);
        if ($worklog !== []) {
            $bags['worklog'] = $worklog;
        }
        $worklogPrev = $workLogs->forSite($site, $period->previous());
        if ($worklogPrev !== []) {
            $previousBags['worklog'] = $worklogPrev;
        }

        // Inject calculated metrics (formulas over the bag) as a `calc` source so
        // blocks bind to them like any connector metric (CLAUDE.md §10.1). The computed
        // values are surfaced too, so the calc editor can show each formula's live result.
        // Agency-level reusable metrics merge in; the draft (report-level) ones override.
        $agencyDefs = array_values(array_filter($site->agency->calculated_metrics ?? [], 'is_array'));
        $calcDefs = ReportGenerator::mergeCalcDefinitions($agencyDefs, $this->calcDefinitions($request->input('calculated_metrics')));
        $calcValues = $calculated->compute($calcDefs, $bags);
        $bags = $this->withCalc($bags, $calcValues);
        $previousBags = $this->withCalc($previousBags, $calculated->compute($calcDefs, $previousBags));

        $score = $health->calculate($bags);

        // Page/dashboard filters being edited cascade into the preview (block filters win),
        // so the live preview matches exactly what the generated report will show.
        $rawFilters = $request->input('filters');
        $filtersByPage = [];
        if (is_array($rawFilters)) {
            foreach ($rawFilters as $page => $list) {
                if (is_array($list)) {
                    $filtersByPage[$page] = array_values(array_filter($list, is_array(...)));
                }
            }
        }

        ['blocks' => $visibleBlocks, 'data' => $data] = $resolver->resolve($blocks, $bags, $score, $previousBags, $filtersByPage);

        return response()->json([
            'blocks' => $visibleBlocks,
            'data' => $data,
            'score' => $score,
            'period' => $period->toArray(),
            'has_data' => $bags !== [],
            'sources_with_data' => array_keys($bags),
            // calc.<key> → computed number, for the live "= value" in the calc editor.
            'calc_values' => $calcValues,
        ]);
    }

    public function sync(Request $request, Site $site): JsonResponse
    {
        // Sync the period being previewed in the editor (so "Sincronizar ahora" fetches
        // the month you're looking at), falling back to the current month.
        $start = $request->input('period_start');
        $end = $request->input('period_end');
        $period = is_string($start) && is_string($end)
            ? new Period(Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay())
            : $this->currentMonth();

        // Optionally sync only specific sources (e.g. a newly-added connector or one with a
        // new metric) instead of every source — no need to re-pull sources already up to
        // date. Absent → all of the site's sources (the original "Sincronizar todo").
        $sources = $site->dataSources();

        $requested = $request->input('data_source_ids');
        if (is_array($requested) && $requested !== []) {
            $ids = [];
            foreach ($requested as $id) {
                if (is_numeric($id) && (int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }
            // Scoped by the site relation, so an id from another site/agency is ignored.
            $sources->whereIn('id', $ids);
        }

        $queued = 0;

        foreach ($sources->get() as $source) {
            SyncSourceJob::dispatch(
                $source->id,
                $period->start->toIso8601String(),
                $period->end->toIso8601String(),
            );

            $queued++;
        }

        return response()->json([
            'queued' => $queued,
            'period' => $period->toArray(),
        ], 202);
    }

    /**
     * @param  array<string, int|float>  $calc
     * @param  array<string, array<array-key, mixed>>  $bags
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
     * @return array<int, array<array-key, mixed>>
     */
    private function calcDefinitions(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        return array_values(array_filter($input, 'is_array'));
    }

    private function resolvePeriod(PreviewReportRequest $request): Period
    {
        $start = $request->input('period_start');
        $end = $request->input('period_end');

        if (is_string($start) && is_string($end)) {
            // Expand to full-day bounds so a "2026-06-30" end (parsed as 00:00:00)
            // still contains a snapshot whose period_end is that day's 23:59:59
            // (MetricBagLoader matches with period_end <= period.end).
            return new Period(
                Carbon::parse($start)->startOfDay(),
                Carbon::parse($end)->endOfDay(),
            );
        }

        return $this->currentMonth();
    }

    private function currentMonth(): Period
    {
        $now = Carbon::now();

        return new Period($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
    }
}
