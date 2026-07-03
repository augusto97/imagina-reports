<?php

declare(strict_types=1);

namespace App\Reports;

use App\Connectors\Period;
use App\Models\DataSource;
use App\Models\Report;

/**
 * Builds the agency-wide upsell opportunities view (CLAUDE.md §13). For each site
 * with a generated report, it re-evaluates the UpsellDetector against that site's
 * most recent report period — reading only frozen snapshots (§3.1), tenant-scoped
 * via the AgencyScope, so it never touches a live API. Powers the admin
 * "Oportunidades" screen: commercial signals (plan upgrades / new services)
 * grouped by site, most opportunities first. Internal-only — never shown to the
 * client (mirrors the `upsell.detected` webhook the generator already emits).
 *
 * @phpstan-type OpportunityView array{type: string, label: string, context: array<string, mixed>}
 * @phpstan-type SiteUpsell array{site_id: int, site_name: string, client_name: string|null, period_end: string, opportunities: list<OpportunityView>}
 * @phpstan-type Upsell array{summary: array{sites_count: int, sites_with_opportunities: int, opportunities_count: int}, sites: list<SiteUpsell>}
 */
final readonly class AgencyUpsell
{
    public function __construct(
        private MetricBagLoader $bags,
        private UpsellDetector $detector,
    ) {}

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
            $current = $this->bags->forSite($siteId, $period);
            $previous = $this->bags->forSite($siteId, $period->previous());

            $connected = $connectedBySite[$siteId] ?? [];

            $opportunities = array_map(
                static fn (UpsellOpportunity $opportunity): array => [
                    'type' => $opportunity->type->value,
                    'label' => $opportunity->type->label(),
                    'context' => $opportunity->context,
                ],
                $this->detector->detect($current, $previous, $connected),
            );

            if ($opportunities === []) {
                continue;
            }

            $opportunitiesTotal += count($opportunities);

            $sites[] = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'client_name' => $site->client?->name,
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
            ],
            'sites' => $sites,
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
