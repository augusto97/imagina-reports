<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\Period;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Site;
use App\Reports\Calc\CalculatedMetrics;
use App\Reports\MetricBagLoader;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Agency-level reusable calculated metrics (CLAUDE.md §10.1): formulas defined once and
 * available in every report. `update` replaces the agency's list; `preview` evaluates a
 * draft set against a site's real data for a period so the editor shows each formula's
 * live result before saving.
 */
final class CalculatedMetricController extends Controller
{
    /** PUT /agency/calculated-metrics — replace the agency's reusable calculated metrics. */
    public function update(Request $request, TenantContext $tenant): JsonResponse
    {
        $request->validate([
            'calculated_metrics' => ['present', 'array', 'max:50'],
            'calculated_metrics.*.key' => ['required', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*$/', 'max:50'],
            'calculated_metrics.*.label' => ['nullable', 'string', 'max:120'],
            'calculated_metrics.*.formula' => ['required', 'string', 'max:500'],
        ]);

        $raw = $request->input('calculated_metrics', []);
        $metrics = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];

        $agency = Agency::query()->findOrFail($tenant->id());
        $agency->calculated_metrics = $metrics;
        $agency->save();

        return response()->json(['calculated_metrics' => $agency->calculated_metrics]);
    }

    /**
     * POST /sites/{site}/calc-preview — compute the posted formulas against the site's real
     * data for a period, returning calc.<key> → value (or absent when incomputable). Lets the
     * editor show a live "= value" + a KPI preview without saving anything.
     */
    public function preview(Request $request, Site $site, MetricBagLoader $loader, CalculatedMetrics $calculated): JsonResponse
    {
        $period = $this->period($request);
        $bags = $loader->forSite($site->id, $period);

        $defs = array_values(array_filter(
            is_array($request->input('calculated_metrics')) ? $request->input('calculated_metrics') : [],
            'is_array',
        ));

        return response()->json([
            'values' => $calculated->compute($defs, $bags),
            'period' => $period->toArray(),
        ]);
    }

    private function period(Request $request): Period
    {
        $start = $request->input('period_start');
        $end = $request->input('period_end');

        if (is_string($start) && is_string($end)) {
            return new Period(Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay());
        }

        $now = Carbon::now();

        return new Period($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
    }
}
