<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AnomalyAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class AnomalyAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $alert = $this->resource;

        if (! $alert instanceof AnomalyAlert) {
            throw new LogicException('AnomalyAlertResource expects an AnomalyAlert.');
        }

        return [
            'id' => $alert->id,
            'site_id' => $alert->site_id,
            'site_name' => $alert->site?->name,
            'report_id' => $alert->report_id,
            'type' => $alert->type->value,
            'metric' => $alert->metric,
            'current' => $alert->current_value,
            'previous' => $alert->previous_value,
            'change_percent' => $alert->change_percent,
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            'detected_at' => $alert->created_at?->toIso8601String(),
        ];
    }
}
