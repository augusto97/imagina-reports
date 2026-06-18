<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Report;
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

        $blocks = $resolved['blocks'] ?? [];
        $data = $resolved['data'] ?? [];

        return [
            'period_start' => $report->period_start->toIso8601String(),
            'period_end' => $report->period_end->toIso8601String(),
            'health_score' => $report->health_score,
            'status' => $report->status->value,
            'blocks' => $blocks,
            // Overlay live work logs onto any worklog_timeline block (the frozen
            // layout stays; the "what we did" list reflects entries added later).
            'data' => $this->withWorkLogs($report, $blocks, is_array($data) ? $data : []),
            'agency' => $agency === null ? null : [
                'name' => $agency->name,
                'logo_path' => $agency->logo_path,
                'brand_color' => $agency->brand_color,
                'locale' => $agency->default_locale,
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function withWorkLogs(Report $report, mixed $blocks, array $data): array
    {
        if (! is_array($blocks)) {
            return $data;
        }

        $entries = $report->workLogs->map(static fn ($log): array => [
            'performed_at' => $log->performed_at->toDateString(),
            'description' => $log->description,
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
