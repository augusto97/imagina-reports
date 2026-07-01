<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Default SaaS plans (Fase 1). Idempotent — safe to re-run. Limits are illustrative
 * starting points the platform admin can edit; null = unlimited.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter', 'slug' => 'starter', 'sort' => 1, 'monthly_price' => 29,
                'max_sites' => 5, 'max_data_sources' => 15, 'max_clients' => 5, 'max_users' => 1, 'max_reports_per_month' => 20,
                'features' => ['ai_builder' => false, 'white_label' => true, 'remove_branding' => false, 'custom_domain' => false],
            ],
            [
                'name' => 'Pro', 'slug' => 'pro', 'sort' => 2, 'monthly_price' => 79,
                'max_sites' => 25, 'max_data_sources' => 100, 'max_clients' => 25, 'max_users' => 5, 'max_reports_per_month' => 100,
                'features' => ['ai_builder' => true, 'white_label' => true, 'remove_branding' => true, 'custom_domain' => false],
            ],
            [
                'name' => 'Agency', 'slug' => 'agency', 'sort' => 3, 'monthly_price' => 199,
                'max_sites' => null, 'max_data_sources' => null, 'max_clients' => null, 'max_users' => null, 'max_reports_per_month' => null,
                'features' => ['ai_builder' => true, 'white_label' => true, 'remove_branding' => true, 'custom_domain' => true],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
