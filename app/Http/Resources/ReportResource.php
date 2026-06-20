<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Report;
use App\Models\WorkLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * Public report payload for the portal/PDF (CLAUDE.md §8 public endpoint). Exposes
 * only the frozen resolved blocks + branding — no tenant internals.
 */
final class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource;

        if (! $report instanceof Report) {
            throw new LogicException('ReportResource expects a Report.');
        }

        $resolved = $report->resolved_blocks;
        $agency = $report->agency;
        $site = $report->definition?->site;
        $client = $site?->client;

        $blocks = $resolved['blocks'] ?? [];
        $data = $resolved['data'] ?? [];

        return [
            'period_start' => $report->period_start->toIso8601String(),
            'period_end' => $report->period_end->toIso8601String(),
            'health_score' => $report->health_score,
            'status' => $report->status->value,
            // Site reporting currency — amounts render as-is, no FX conversion (§5).
            'currency' => $site !== null ? $site->currency : 'USD',
            'blocks' => $blocks,
            // Merge-field context for dynamic {{tokens}} in text blocks (§11.3).
            'context' => [
                'agency' => $agency !== null ? $agency->name : '',
                'site' => $site !== null ? $site->name : '',
                'client' => $client !== null ? $client->name : '',
                'period' => $report->period_start->format('d/m/Y').' – '.$report->period_end->format('d/m/Y'),
                'score' => (string) ($report->health_score ?? ''),
            ],
            // Overlay live work logs onto any worklog_timeline block (the frozen
            // layout stays; the "what we did" list reflects entries added later).
            'data' => $this->withWorkLogs($report, $blocks, is_array($data) ? $data : []),
            'agency' => $agency === null ? null : [
                'name' => $agency->name,
                'logo_path' => $agency->logo_path,
                'logo_url' => $agency->logoUrl(),
                'brand_color' => $agency->brand_color,
                'locale' => $agency->default_locale,
            ],
        ];
    }

    /**
     * Overlay the "what we did" timeline from the SITE's work logs within the report
     * period (includes the daily quick-add entries, report-independent, §11.5) with
     * their time + category — so the timeline reflects entries added after generation.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function withWorkLogs(Report $report, mixed $blocks, array $data): array
    {
        if (! is_array($blocks)) {
            return $data;
        }

        $site = $report->definition?->site;

        if ($site === null) {
            return $data;
        }

        // Tenant-safe by site_id (resolved from the token's report); no auth context here.
        $entries = WorkLog::query()
            ->withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->whereBetween('performed_at', [$report->period_start, $report->period_end])
            ->orderByDesc('performed_at')
            ->get()
            ->map(static fn (WorkLog $log): array => [
                'performed_at' => $log->performed_at->toDateString(),
                'description' => $log->description,
                'minutes' => $log->minutes,
                'category' => $log->category,
                'screenshot_path' => $log->screenshot_path,
            ])->all();

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'worklog_timeline' && is_string($block['id'] ?? null)) {
                $data[$block['id']] = $entries;
            }
        }

        return $data;
    }
}
