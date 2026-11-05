<?php

declare(strict_types=1);

namespace App\Reports;

use App\Connectors\Period;
use App\Models\Agency;
use App\Models\DataSource;
use App\Models\Report;
use App\Models\Site;
use App\Models\SiteGoal;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Builds the agency-wide upsell opportunities view (CLAUDE.md §13). For each site
 * with a generated report, it re-evaluates the UpsellDetector against that site's
 * most recent report period — reading only frozen snapshots (§3.1), tenant-scoped
 * via the AgencyScope, so it never touches a live API. Powers the admin
 * "Oportunidades" screen: commercial signals (plan upgrades / new services)
 * grouped by site, most opportunities first. Internal-only — never shown to the
 * client (mirrors the `upsell.detected` webhook the generator already emits).
 *
 * Uses the agency's own thresholds (Etapa 5) and hides what the agency already offered in the
 * last OFFERED_DAYS, so the list is what's still worth a conversation.
 *
 * @phpstan-type OpportunityView array{type: string, label: string, context: array<string, mixed>}
 * @phpstan-type SiteUpsell array{site_id: int, site_name: string, client_name: string|null, client_email: string|null, period_end: string, opportunities: list<OpportunityView>}
 * @phpstan-type Upsell array{summary: array{sites_count: int, sites_with_opportunities: int, opportunities_count: int, offered_count: int}, sites: list<SiteUpsell>, thresholds: array<string, float>}
 */
final readonly class AgencyUpsell
{
    /** An opportunity marked «ya la ofrecí» stays hidden this long. */
    public const OFFERED_DAYS = 90;

    public function __construct(
        private MetricBagLoader $bags,
        private UpsellDetector $detector,
        private WorkLogMetrics $workLogs,
        private TenantContext $tenant,
    ) {}

    /**
     * What one site's latest period says, with the agency's thresholds — shared with the
     * generation-time listener so the webhook and the screen agree.
     *
     * @param  list<string>  $connected
     * @param  array<array-key, mixed>  $thresholds
     * @return list<UpsellOpportunity>
     */
    public function forSite(Site $site, Period $period, array $connected, array $thresholds): array
    {
        $current = $this->bags->forSite($site->id, $period);
        $previous = $this->bags->forSite($site->id, $period->previous());
        $worklog = $this->workLogs->forSite($site, $period);
        if ($worklog !== []) {
            $current['worklog'] = $worklog;
        }
        $goals = array_map(
            static fn (array $goal): array => ['met' => $goal['met']],
            SiteGoals::measure(SiteGoal::query()->withoutGlobalScopes()->where('site_id', $site->id)->get(), $current),
        );

        return $this->detector->detect($current, $previous, $connected, $thresholds, $goals);
    }

    /**
     * @return array<string, mixed>
     */
    public static function agencyThresholds(?Agency $agency): array
    {
        $raw = $agency?->settings['upsell_thresholds'] ?? null;
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** Key for an offered opportunity in `settings.upsell_offered`. */
    public static function offeredKey(int $siteId, string $type): string
    {
        return $siteId.':'.$type;
    }

    /**
     * @return Upsell
     */
    public function build(): array
    {
        // Latest generated report per site (ascending order → last write wins). Only the light
        // columns — the metric bags come from snapshots (below), not resolved_blocks (PERF).
        $reports = Report::query()
            ->select(['id', 'agency_id', 'report_definition_id', 'period_start', 'period_end'])
            ->with('definition.site.client')
            ->orderBy('period_end')
            ->get();

        /** @var array<int, Report> $latestBySite */
        $latestBySite = [];

        foreach ($reports as $report) {
            $site = $report->definition?->site;

            if ($site !== null) {
                $latestBySite[$site->id] = $report;
            }
        }

        // Connected source types per site in ONE query instead of one per site (PERF — was N+1).
        $connectedBySite = $this->connectedTypes(array_keys($latestBySite));
        $agency = $this->tenant->id() !== null ? Agency::query()->find($this->tenant->id()) : $reports->first()?->agency;
        $thresholds = self::agencyThresholds($agency);
        $offeredRaw = $agency !== null ? ($agency->settings['upsell_offered'] ?? null) : null;
        $offered = is_array($offeredRaw) ? $offeredRaw : [];
        $offeredCount = 0;

        $sites = [];
        $opportunitiesTotal = 0;

        foreach ($latestBySite as $siteId => $report) {
            $site = $report->definition?->site;

            if ($site === null) {
                continue;
            }

            $period = new Period($report->period_start, $report->period_end);
            // Same period basis as the DetectUpsellOpportunities listener, so the
            // screen mirrors the opportunities the `upsell.detected` webhook fired.
            $opportunities = [];
            foreach ($this->forSite($site, $period, $connectedBySite[$siteId] ?? [], $thresholds) as $opportunity) {
                $offeredAt = $offered[self::offeredKey($siteId, $opportunity->type->value)] ?? null;
                if (is_string($offeredAt) && Carbon::parse($offeredAt)->gt(now()->subDays(self::OFFERED_DAYS))) {
                    $offeredCount++;

                    continue;
                }
                $opportunities[] = [
                    'type' => $opportunity->type->value,
                    'label' => $opportunity->type->label(),
                    'context' => $opportunity->context,
                ];
            }

            if ($opportunities === []) {
                continue;
            }

            $opportunitiesTotal += count($opportunities);

            $sites[] = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'client_name' => $site->client?->name,
                'client_email' => $site->client?->contact_email,
                'period_end' => $report->period_end->toDateString(),
                'opportunities' => $opportunities,
            ];
        }

        // Most opportunities first — the hottest accounts rise to the top.
        usort($sites, static fn (array $a, array $b): int => count($b['opportunities']) <=> count($a['opportunities'])
            ?: strcmp($a['site_name'], $b['site_name']));

        return [
            'summary' => [
                'sites_count' => count($latestBySite),
                'sites_with_opportunities' => count($sites),
                'opportunities_count' => $opportunitiesTotal,
                'offered_count' => $offeredCount,
            ],
            'sites' => $sites,
            'thresholds' => UpsellDetector::thresholds($thresholds),
        ];
    }

    /**
     * Connected source-type values grouped by site id, fetched in a single query.
     *
     * @param  list<int>  $siteIds
     * @return array<int, list<string>>
     */
    private function connectedTypes(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $map = [];

        foreach (DataSource::query()->whereIn('site_id', $siteIds)->get() as $source) {
            $siteId = $source->site_id;

            if ($siteId !== null) {
                $map[$siteId][] = $source->type->value;
            }
        }

        return $map;
    }
}
