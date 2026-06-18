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

        return [
            'period_start' => $report->period_start->toIso8601String(),
            'period_end' => $report->period_end->toIso8601String(),
            'health_score' => $report->health_score,
            'status' => $report->status->value,
            'blocks' => $resolved['blocks'] ?? [],
            'data' => $resolved['data'] ?? [],
            'agency' => $agency === null ? null : [
                'name' => $agency->name,
                'logo_path' => $agency->logo_path,
                'brand_color' => $agency->brand_color,
                'locale' => $agency->default_locale,
            ],
        ];
    }
}
