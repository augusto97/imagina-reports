<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\ReceivesPushedData;
use App\Enums\DataSourceStatus;
use App\Enums\DataSourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDataSourceRequest;
use App\Http\Requests\UpdateDataSourceRequest;
use App\Http\Resources\DataSourceResource;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Data sources for a site (CLAUDE.md §8/§9), plus the "Test connection" action that
 * runs the connector's testConnection() without storing a snapshot.
 */
final class DataSourceController extends Controller
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    public function index(Site $site): AnonymousResourceCollection
    {
        $sources = $site->dataSources()->latest()->get();

        // Attach MainWP's "Child Reports active" flag from the latest snapshot so the
        // sync panel can warn when the child site isn't recording its activity history.
        foreach ($sources as $source) {
            if ($source->type === DataSourceType::MainWp) {
                $source->setAttribute('child_reports_active', $this->childReportsActive($source));
            }

            $this->decoratePush($source);
        }

        return DataSourceResource::collection($sources);
    }

    /**
     * Push-capable sources (CrowdSec) are configured by installing an outbound push on
     * the client VPS, not by polling — so they need a per-source push token + the ingest
     * URL surfaced to the admin. Generate the token lazily so existing sources get one
     * the first time they're listed, then expose both for the install snippet.
     */
    private function decoratePush(DataSource $source): void
    {
        if (! $this->registry->for($source) instanceof ReceivesPushedData) {
            return;
        }

        if ($source->push_token === null || $source->push_token === '') {
            $source->forceFill(['push_token' => Str::random(48)])->save();
        }

        $source->setAttribute('is_push', true);
        $source->setAttribute('push_token', $source->push_token);
        $source->setAttribute('ingest_url', route('api.ingest.store', ['token' => $source->push_token]));
    }

    /**
     * Stored-data coverage per source for a site: the date span of its snapshots, how many
     * there are, roughly how much storage they use, AND the GAPS — uncovered day-ranges
     * inside the span (e.g. a month that was never synced). Lets the admin see exactly what
     * is already synced, spot a missing period before generating a report, and gauge storage
     * for retention (§5). The span (period_start→period_end) alone hides interior gaps, so we
     * report them explicitly.
     */
    public function coverage(Site $site): JsonResponse
    {
        $sources = $site->dataSources()->get();
        $sourceIds = $sources->pluck('id');

        // Count + approximate stored bytes (LENGTH of the JSON payload) per source.
        $rows = MetricSnapshot::query()
            ->whereIn('data_source_id', $sourceIds)
            ->selectRaw('data_source_id, COUNT(*) as snapshots, COALESCE(SUM(LENGTH(payload)), 0) as bytes')
            ->groupBy('data_source_id')
            ->get()
            ->keyBy('data_source_id');

        // The actual stored periods per source, for the span + gap detection.
        $periods = MetricSnapshot::query()
            ->whereIn('data_source_id', $sourceIds)
            ->orderBy('period_start')
            ->get(['data_source_id', 'period_start', 'period_end'])
            ->groupBy('data_source_id');

        $coverage = $sources->map(function (DataSource $source) use ($rows, $periods): array {
            $row = $rows->get($source->id);
            $snapshots = $row?->getAttribute('snapshots');
            $bytes = $row?->getAttribute('bytes');

            /** @var Collection<int, MetricSnapshot> $list */
            $list = $periods->get($source->id) ?? collect();
            $intervals = array_values($list
                ->map(static fn (MetricSnapshot $s): array => ['start' => $s->period_start, 'end' => $s->period_end])
                ->all());

            $first = $list->first()?->period_start;
            $last = $list->max('period_end');
            $gaps = $this->gaps($intervals);

            return [
                'data_source_id' => $source->id,
                'period_start' => $first instanceof Carbon ? $first->toIso8601String() : null,
                'period_end' => $last instanceof Carbon ? $last->toIso8601String() : null,
                'snapshots' => is_numeric($snapshots) ? (int) $snapshots : 0,
                'bytes' => is_numeric($bytes) ? (int) $bytes : 0,
                'gaps' => array_map(static fn (array $gap): array => [
                    'start' => $gap['start']->toIso8601String(),
                    'end' => $gap['end']->toIso8601String(),
                ], $gaps),
            ];
        });

        return response()->json($coverage->values()->all());
    }

    /**
     * Uncovered day-ranges between a source's stored periods. Snapshots are merged at day
     * granularity (adjacent days count as continuous); any whole day between two covered
     * stretches with no snapshot is a gap — so a skipped month shows up even though the
     * overall span looks continuous.
     *
     * @param  list<array{start: Carbon, end: Carbon}>  $intervals
     * @return list<array{start: Carbon, end: Carbon}>
     */
    private function gaps(array $intervals): array
    {
        if (count($intervals) < 2) {
            return [];
        }

        // Normalize to day boundaries and sort by start.
        $days = array_map(static fn (array $i): array => [
            'start' => $i['start']->copy()->startOfDay(),
            'end' => $i['end']->copy()->startOfDay(),
        ], $intervals);
        usort($days, static fn (array $a, array $b): int => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        // Merge overlapping / adjacent intervals.
        $merged = [$days[0]];
        foreach (array_slice($days, 1) as $interval) {
            $lastIndex = count($merged) - 1;
            $continues = $interval['start']->lessThanOrEqualTo($merged[$lastIndex]['end']->copy()->addDay());

            if ($continues) {
                if ($interval['end']->greaterThan($merged[$lastIndex]['end'])) {
                    $merged[$lastIndex]['end'] = $interval['end'];
                }
            } else {
                $merged[] = $interval;
            }
        }

        // The holes between consecutive merged stretches.
        $gaps = [];
        for ($i = 0; $i < count($merged) - 1; $i++) {
            $gapStart = $merged[$i]['end']->copy()->addDay();
            $gapEnd = $merged[$i + 1]['start']->copy()->subDay();

            if ($gapStart->lessThanOrEqualTo($gapEnd)) {
                $gaps[] = ['start' => $gapStart, 'end' => $gapEnd];
            }
        }

        return $gaps;
    }

    private function childReportsActive(DataSource $source): ?bool
    {
        $snapshot = MetricSnapshot::query()
            ->where('data_source_id', $source->id)
            ->latest('captured_at')
            ->first();

        $payload = $snapshot?->payload;
        $metrics = is_array($payload) && is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];

        if (! array_key_exists('mainwp.child_reports_active', $metrics)) {
            return null;
        }

        return (bool) $metrics['mainwp.child_reports_active'];
    }

    public function store(StoreDataSourceRequest $request, Site $site): JsonResponse
    {
        $source = $site->dataSources()->create($request->validated());

        // Mint the push token immediately so the install snippet is available right away.
        $this->decoratePush($source);

        return DataSourceResource::make($source)->response()->setStatusCode(201);
    }

    public function update(UpdateDataSourceRequest $request, DataSource $dataSource): DataSourceResource
    {
        $data = $request->validated();
        $changes = [];

        if (array_key_exists('type', $data) && $data['type'] !== null) {
            $changes['type'] = $data['type'];
        }

        if (array_key_exists('config', $data) && is_array($data['config'])) {
            $changes['config'] = $data['config'];
        }

        if (array_key_exists('credentials', $data) && is_array($data['credentials'])) {
            // Keep any existing secret the user left blank (the form can't show secrets back).
            $merged = $dataSource->credentials ?? [];
            foreach ($data['credentials'] as $key => $value) {
                // A blank field means "keep the current secret". Laravel's
                // ConvertEmptyStringsToNull turns "" into null, so treat both as blank.
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }
                $merged[$key] = $value;
            }
            $changes['credentials'] = $merged;
        }

        // Config/credentials changed → reset to pending so the operator re-tests/syncs.
        $changes['status'] = DataSourceStatus::Pending;
        $changes['last_error'] = null;

        $dataSource->update($changes);

        return new DataSourceResource($dataSource);
    }

    public function destroy(DataSource $dataSource): JsonResponse
    {
        // Snapshots cascade on delete (FK), so this removes the source and its history.
        $dataSource->delete();

        return response()->json(null, 204);
    }

    public function test(DataSource $dataSource): JsonResponse
    {
        $result = $this->registry->for($dataSource)->testConnection($dataSource);

        return response()->json([
            'successful' => $result->successful,
            'message' => $result->message,
        ]);
    }
}
