<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Report;

/**
 * Builds the agency-wide multi-site trends + comparisons (CLAUDE.md §13). Reads only
 * already-generated reports (ir_reports — frozen snapshots, §3.1), tenant-scoped via
 * the AgencyScope, so it never touches a live API. Powers the admin trends dashboard:
 * each site's health-score history plus an at-a-glance comparison, worst health first.
 *
 * @phpstan-type HealthPoint array{period_end: string, health_score: int|null}
 * @phpstan-type SiteTrend array{site_id: int, site_name: string, client_name: string|null, latest_health_score: int|null, reports_count: int, health_series: list<HealthPoint>}
 * @phpstan-type Trends array{summary: array{sites_count: int, reports_count: int, average_health_score: int|null}, sites: list<SiteTrend>}
 */
final readonly class AgencyTrends
{
    private const MAX_PERIODS = 12;

    /**
     * @return Trends
     */
    public function build(): array
    {
        $reports = Report::query()
            ->with('definition.site.client')
            ->orderBy('period_end')
            ->get();

        $siteById = [];
        $reportsBySite = [];

        foreach ($reports as $report) {
            $site = $report->definition?->site;

            if ($site === null) {
                continue;
            }

            $siteById[$site->id] = $site;
            $reportsBySite[$site->id][] = $report;
        }

        $sites = [];
        $latestScores = [];

        foreach ($siteById as $siteId => $site) {
            $siteReports = $reportsBySite[$siteId] ?? [];
            $latestScore = ($siteReports[count($siteReports) - 1] ?? null)?->health_score;

            if ($latestScore !== null) {
                $latestScores[] = $latestScore;
            }

            $sites[] = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'client_name' => $site->client?->name,
                'latest_health_score' => $latestScore,
                'reports_count' => count($siteReports),
                'health_series' => array_map(
                    static fn (Report $r): array => [
                        'period_end' => $r->period_end->toDateString(),
                        'health_score' => $r->health_score,
                    ],
                    array_slice($siteReports, -self::MAX_PERIODS),
                ),
            ];
        }

        // Worst health first — the sites that need attention rise to the top.
        usort($sites, static fn (array $a, array $b): int => [$a['latest_health_score'] ?? 101, $a['site_name']]
            <=> [$b['latest_health_score'] ?? 101, $b['site_name']]);

        $average = $latestScores === [] ? null : (int) round(array_sum($latestScores) / count($latestScores));

        return [
            'summary' => [
                'sites_count' => count($sites),
                'reports_count' => $reports->count(),
                'average_health_score' => $average,
            ],
            'sites' => $sites,
        ];
    }
}
