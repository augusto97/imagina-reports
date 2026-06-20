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
use App\Reports\WorkLogMetrics;
use Illuminate\Http\JsonResponse;
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
        // blocks bind to them like any connector metric (CLAUDE.md §10.1).
        $calcDefs = $this->calcDefinitions($request->input('calculated_metrics'));
        $bags = $this->withCalc($bags, $calculated->compute($calcDefs, $bags));
        $previousBags = $this->withCalc($previousBags, $calculated->compute($calcDefs, $previousBags));

        $score = $health->calculate($bags);

        ['blocks' => $visibleBlocks, 'data' => $data] = $resolver->resolve($blocks, $bags, $score, $previousBags);

        return response()->json([
            'blocks' => $visibleBlocks,
            'data' => $data,
            'score' => $score,
            'period' => $period->toArray(),
            'has_data' => $bags !== [],
            'sources_with_data' => array_keys($bags),
        ]);
    }

    public function sync(Site $site): JsonResponse
    {
        $period = $this->currentMonth();

        $queued = 0;

        foreach ($site->dataSources()->get() as $source) {
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
