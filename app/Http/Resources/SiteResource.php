<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class SiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $site = $this->resource;

        if (! $site instanceof Site) {
            throw new LogicException('SiteResource expects a Site.');
        }

        return [
            'id' => $site->id,
            'client_id' => $site->client_id,
            'name' => $site->name,
            'url' => $site->url,
            'hosting' => $site->hosting,
            'support_plan' => $site->support_plan,
            'status' => $site->status,
            'currency' => $site->currency,
            'plan_hours' => $site->plan_hours,
            'calculated_metrics' => $site->calculated_metrics ?? [],
        ];
    }
}
