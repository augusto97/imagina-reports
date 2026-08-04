<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShowDimensionValuesRequest;
use App\Models\MetricSnapshot;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

/**
 * The values a dataset dimension actually holds, read from the latest snapshot.
 *
 * The editor's filters used to be a free-text box: the agency had to type a campaign name
 * exactly right, and a single typo produced an empty block with no explanation. Offering the
 * real values turns filtering into picking from a list — the single biggest source of
 * "I set a filter and nothing showed up".
 *
 * Reads the stored snapshot, never the provider: values come from the same bounded, already
 * aggregated rows the report resolves against (§3.1/§3.3), so what you can filter by is
 * exactly what the report can show.
 */
final class DimensionValuesController extends Controller
{
    /** Distinct values returned per dimension — a picker, not a data dump. */
    private const MAX_VALUES = 200;

    public function show(ShowDimensionValuesRequest $request, Site $site): JsonResponse
    {
        $sourceKey = $request->string('source')->toString();
        $metric = $request->string('metric')->toString();
        $dimension = $request->string('dimension')->toString();

        $source = $site->dataSources()->where('type', $sourceKey)->first();

        if ($source === null) {
            return response()->json(['values' => []]);
        }

        $snapshot = MetricSnapshot::query()
            ->where('data_source_id', $source->getKey())
            ->orderByDesc('period_end')
            ->first();

        if ($snapshot === null) {
            return response()->json(['values' => []]);
        }

        $rows = Arr::get($snapshot->payload, $sourceKey.'.'.$metric);

        if (! is_array($rows)) {
            return response()->json(['values' => []]);
        }

        $values = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $value = $row[$dimension] ?? null;
            if (! is_scalar($value)) {
                continue;
            }

            $value = (string) $value;
            if ($value !== '') {
                // Keyed to de-duplicate while preserving the snapshot's order, which is
                // sorted by weight (spend, sessions…) — so the useful values come first.
                $values[$value] = true;
            }
        }

        return response()->json(['values' => array_slice(array_keys($values), 0, self::MAX_VALUES)]);
    }
}
