<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\DataSourceStatus;
use App\Enums\ReportMessageKind;
use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Models\AnomalyAlert;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\ReportMessage;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * The agency's "what needs me today" summary for the Inicio screen, plus a per-site health
 * map the client tree uses for its status dots. Tenant-scoped by the models' global scope.
 */
final class OverviewController extends Controller
{
    private const LIST_LIMIT = 6;

    public function __invoke(): JsonResponse
    {
        $sites = Site::query()->with('client:id,name')->get(['id', 'client_id', 'name', 'url']);
        $sources = DataSource::query()->get(['id', 'site_id', 'type', 'status', 'last_error', 'last_synced_at']);
        $definitions = ReportDefinition::query()->get(['id', 'site_id', 'name']);

        // Latest report per definition (the one the agency is working on this cycle).
        $latest = Report::query()
            ->orderByDesc('period_start')
            ->get(['id', 'report_definition_id', 'period_start', 'period_end', 'status', 'health_score'])
            ->unique('report_definition_id');

        $siteNames = $sites->mapWithKeys(fn (Site $site): array => [$site->id => [
            'site' => $site->name,
            'client' => $site->client?->name,
        ]]);
        $definitionSite = $definitions->mapWithKeys(fn (ReportDefinition $definition): array => [$definition->id => $definition->site_id]);

        $failing = $sources->filter(fn (DataSource $source): bool => $source->status === DataSourceStatus::Error)->values();
        $lastMonth = Carbon::now()->subMonthNoOverflow()->startOfMonth();

        $reportRow = function (Report $report) use ($definitionSite, $siteNames): array {
            $siteId = $definitionSite[$report->report_definition_id] ?? null;

            return [
                'id' => $report->id,
                'site_id' => $siteId,
                'site' => $siteId !== null ? ($siteNames[$siteId]['site'] ?? '') : '',
                'client' => $siteId !== null ? ($siteNames[$siteId]['client'] ?? null) : null,
                'period_start' => $report->period_start->toDateString(),
                'status' => $report->status->value,
                'health_score' => $report->health_score,
            ];
        };

        $drafts = $latest->filter(fn (Report $report): bool => $report->status === ReportStatus::Draft)->values();
        $approved = $latest->filter(fn (Report $report): bool => $report->status === ReportStatus::Approved)->values();

        // Definitions whose last complete month has no report yet.
        $covered = Report::query()
            ->whereDate('period_start', $lastMonth->toDateString())
            ->pluck('report_definition_id')
            ->all();
        $missing = $definitions->reject(fn (ReportDefinition $definition): bool => in_array($definition->id, $covered, true))->values();

        $siteHealth = [];
        foreach ($sites as $site) {
            $own = $sources->where('site_id', $site->id);
            $score = null;
            foreach ($latest as $report) {
                if (($definitionSite[$report->report_definition_id] ?? null) === $site->id) {
                    $score = $report->health_score;
                    break;
                }
            }
            $siteHealth[$site->id] = [
                'sources' => $own->count(),
                'errors' => $own->filter(fn (DataSource $source): bool => $source->status === DataSourceStatus::Error)->count(),
                'health_score' => $score,
            ];
        }

        $openAlerts = AnomalyAlert::query()->whereNull('acknowledged_at')->count();
        $pendingQuestions = ReportMessage::query()->where('kind', ReportMessageKind::Client->value)->whereNull('handled_at')->count();

        return response()->json([
            'counts' => [
                'clients' => Client::query()->count(),
                'sites' => $sites->count(),
                'sources' => $sources->count(),
                'sources_failing' => $failing->count(),
                'definitions' => $definitions->count(),
                'drafts' => $drafts->count(),
                'approved' => $approved->count(),
                'missing_reports' => $missing->count(),
                'open_alerts' => $openAlerts,
                'pending_questions' => $pendingQuestions,
                'sites_without_sources' => collect($siteHealth)->where('sources', 0)->count(),
            ],
            'last_month' => $lastMonth->toDateString(),
            'failing_sources' => $failing->take(self::LIST_LIMIT)->map(fn (DataSource $source): array => [
                'id' => $source->id,
                'site_id' => $source->site_id,
                'site' => $source->site_id !== null ? ($siteNames[$source->site_id]['site'] ?? '') : '',
                'client' => $source->site_id !== null ? ($siteNames[$source->site_id]['client'] ?? null) : null,
                'type' => $source->type->value,
                'label' => $source->type->label(),
                'error' => $source->last_error,
            ])->values(),
            'drafts' => $drafts->take(self::LIST_LIMIT)->map($reportRow)->values(),
            'approved' => $approved->take(self::LIST_LIMIT)->map($reportRow)->values(),
            'missing_reports' => $missing->take(self::LIST_LIMIT)->map(fn (ReportDefinition $definition): array => [
                'definition_id' => $definition->id,
                'site_id' => $definition->site_id,
                'name' => $definition->name,
                'site' => $siteNames[$definition->site_id]['site'] ?? '',
                'client' => $siteNames[$definition->site_id]['client'] ?? null,
            ])->values(),
            'site_health' => $siteHealth,
        ]);
    }
}
