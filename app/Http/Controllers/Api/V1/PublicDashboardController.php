<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\Period;
use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\ReportDefinition;
use App\Reports\ReportGenerator;
use App\Reports\Sharing\ShareGate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live dashboard endpoint (CLAUDE.md §11.2/Etapa D): serves a published definition by
 * its dashboard token, re-resolved from the latest stored snapshots for a client-chosen
 * date range. Still decoupled (§3.1) — it reads only snapshots, never a live API. Gated
 * by the same visibility/password rules as the frozen report.
 */
final class PublicDashboardController extends Controller
{
    public function __construct(private readonly ReportGenerator $generator) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $definition = ReportDefinition::query()
            ->withoutGlobalScopes()
            ->with(['agency', 'site.client', 'template'])
            ->where('dashboard_token', $token)
            ->where('dashboard_enabled', true)
            ->firstOrFail();

        if (($denied = ShareGate::deny($definition, $request)) !== null) {
            return $denied;
        }

        // The windows that actually have data (one per stored snapshot period). The client
        // picks one of these — there is nothing to show BETWEEN them, and a snapshot is a
        // pre-aggregated total that can't be re-sliced to an arbitrary sub-range (§3.1/§3.3),
        // so a free day-range picker would silently show the wrong (overlapping) snapshot.
        $periods = $this->availablePeriods($definition);

        $period = $this->resolvePeriod($request, $periods);
        $resolved = $this->generator->resolveLive($definition, $period);

        $agency = $definition->agency;
        $site = $definition->site;
        $client = $site?->client;
        $score = $resolved['health_score'];

        return response()->json([
            'period_start' => $period->start->toIso8601String(),
            'period_end' => $period->end->toIso8601String(),
            'health_score' => $score,
            'status' => 'live',
            'currency' => $site !== null ? $site->currency : 'USD',
            'theme' => $resolved['theme'],
            'blocks' => $resolved['blocks'],
            'pages' => $resolved['pages'],
            'context' => [
                'agency' => $agency !== null ? $agency->name : '',
                'site' => $site !== null ? $site->name : '',
                'client' => $client !== null ? $client->name : '',
                'period' => $period->start->format('d/m/Y').' – '.$period->end->format('d/m/Y'),
                'score' => (string) $score,
            ],
            'data' => $resolved['data'],
            'agency' => $agency === null ? null : [
                'name' => $agency->name,
                'logo_path' => $agency->logo_path,
                'logo_url' => $agency->logoUrl(),
                'brand_color' => $agency->brand_color,
                'locale' => $agency->default_locale,
            ],
            // The selectable bounds (kept for back-compat).
            'range' => $this->availableRange($definition),
            // The exact windows that have data — the client's period selector lists only these.
            'periods' => $periods,
        ]);
    }

    /**
     * The distinct snapshot windows for the site (one entry per period that has data), most
     * recent first. The dashboard offers exactly these so the client can never pick a date
     * range with no data — and each selection maps to a real snapshot.
     *
     * @return list<array{start: string, end: string, label: string}>
     */
    private function availablePeriods(ReportDefinition $definition): array
    {
        $sourceIds = DataSource::query()
            ->withoutGlobalScopes()
            ->where('site_id', $definition->site_id)
            ->pluck('id');

        if ($sourceIds->isEmpty()) {
            return [];
        }

        $snapshots = MetricSnapshot::query()
            ->withoutGlobalScopes()
            ->whereIn('data_source_id', $sourceIds)
            ->orderByDesc('period_end')
            ->get(['period_start', 'period_end']);

        $seen = [];
        $periods = [];

        foreach ($snapshots as $snapshot) {
            $start = $snapshot->period_start;
            $end = $snapshot->period_end;
            $key = $start->toDateString().'|'.$end->toDateString();

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $periods[] = [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
                'label' => $start->format('d/m/Y').' – '.$end->format('d/m/Y'),
            ];

            if (count($periods) >= 36) {
                break;
            }
        }

        return $periods;
    }

    /**
     * The requested window (validated `from`/`to` query dates); with neither, defaults to the
     * LATEST available snapshot window (not the full span across all months) so the dashboard
     * opens on a single real period's data.
     *
     * @param  list<array{start: string, end: string, label: string}>  $periods
     */
    private function resolvePeriod(Request $request, array $periods): Period
    {
        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));

        if ($from !== null && $to !== null) {
            // Guard against an inverted range from a hand-edited URL.
            return $to->lessThan($from) ? new Period($to, $from) : new Period($from, $to);
        }

        if ($periods !== []) {
            return new Period(CarbonImmutable::parse($periods[0]['start']), CarbonImmutable::parse($periods[0]['end']));
        }

        // No snapshots yet — fall back to the last 30 days so the page still renders.
        $now = CarbonImmutable::now();

        return new Period($now->subDays(30), $now);
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The full span of stored snapshots for the definition's site (earliest start →
     * latest end), so the client date picker stays within available data.
     *
     * @return array{start: string, end: string}|null
     */
    private function availableRange(ReportDefinition $definition): ?array
    {
        $sourceIds = DataSource::query()
            ->withoutGlobalScopes()
            ->where('site_id', $definition->site_id)
            ->pluck('id');

        if ($sourceIds->isEmpty()) {
            return null;
        }

        $snapshot = MetricSnapshot::query()
            ->withoutGlobalScopes()
            ->whereIn('data_source_id', $sourceIds);

        $start = (clone $snapshot)->min('period_start');
        $end = (clone $snapshot)->max('period_end');

        if (! is_string($start) || ! is_string($end)) {
            return null;
        }

        return [
            'start' => CarbonImmutable::parse($start)->toIso8601String(),
            'end' => CarbonImmutable::parse($end)->toIso8601String(),
        ];
    }
}
