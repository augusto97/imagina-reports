<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plan = $this->resource;

        if (! $plan instanceof Plan) {
            throw new LogicException('PlanResource expects a Plan.');
        }

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'is_active' => $plan->is_active,
            'sort' => $plan->sort,
            'max_sites' => $plan->max_sites,
            'max_data_sources' => $plan->max_data_sources,
            'max_clients' => $plan->max_clients,
            'max_users' => $plan->max_users,
            'max_reports_per_month' => $plan->max_reports_per_month,
            'retention_months' => $plan->retention_months,
            'allowed_connectors' => $plan->allowed_connectors,
            'features' => $plan->features ?? [],
            'monthly_price' => $plan->monthly_price !== null ? (float) $plan->monthly_price : null,
            'currency' => $plan->currency,
        ];
    }
}
