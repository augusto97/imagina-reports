<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\ReceivesPushedData;
use App\Connectors\Period;
use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * Push ingest endpoint (CLAUDE.md §9 — CrowdSec push model). Each client VPS runs the
 * engine's CLI locally and POSTs its already-aggregated data here, outbound over HTTPS,
 * so the client server never opens an inbound port. Authenticated by the source's
 * per-source push token (no Sanctum). The connector normalizes the payload and the
 * result is stored as a snapshot exactly like a polled sync, so GENERATE is unchanged.
 */
final class IngestController extends Controller
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly SyncService $sync,
    ) {}

    public function store(Request $request, string $token): JsonResponse
    {
        // The token is the only credential. No tenant is bound on this public route, so
        // the AgencyScope is a no-op and the lookup spans agencies by design — the token
        // itself scopes to exactly one source. Unknown token → 404 (reveal nothing).
        $source = DataSource::query()->where('push_token', $token)->first();

        if ($source === null) {
            return response()->json(['message' => 'Unknown push token.'], 404);
        }

        $connector = $this->registry->for($source);

        if (! $connector instanceof ReceivesPushedData) {
            return response()->json(['message' => 'This source does not accept pushed data.'], 422);
        }

        /** @var array<array-key, mixed> $payload */
        $payload = $request->all();

        $metricSet = $connector->fromPushedPayload($payload);

        $snapshot = $this->sync->record($source, $this->resolvePeriod($request), $metricSet);

        return response()->json([
            'status' => $metricSet->status->value,
            'metrics' => count($metricSet->metrics),
            'period_start' => $snapshot->period_start->toIso8601String(),
            'period_end' => $snapshot->period_end->toIso8601String(),
        ]);
    }

    /**
     * The period this push covers. The VPS script reports its data over a window; if it
     * names one (period_start/period_end), honour it, otherwise default to the current
     * calendar month — the natural reporting window and what the install script sends.
     */
    private function resolvePeriod(Request $request): Period
    {
        $start = $request->input('period_start');
        $end = $request->input('period_end');

        if (is_string($start) && is_string($end) && $start !== '' && $end !== '') {
            return Period::make($start, $end);
        }

        $now = Date::now();

        return Period::make($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
    }
}
