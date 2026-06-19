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
use App\Reports\HealthScoreCalculator;
use App\Reports\MetricBagLoader;
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
    ): JsonResponse {
        $period = $this->resolvePeriod($request);
        $blocks = $validator->validate($request->input('blocks'));

        $bags = $loader->forSite($site->id, $period);
        $score = $health->calculate($bags);

        ['blocks' => $visibleBlocks, 'data' => $data] = $resolver->resolve($blocks, $bags, $score);

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

    private function resolvePeriod(PreviewReportRequest $request): Period
    {
        $start = $request->input('period_start');
        $end = $request->input('period_end');

        if (is_string($start) && is_string($end)) {
            return new Period($start, $end);
        }

        return $this->currentMonth();
    }

    private function currentMonth(): Period
    {
        $now = Carbon::now();

        return new Period($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
    }
}
