<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->word();

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'is_active' => true,
            'sort' => 0,
            'max_sites' => 10,
            'max_data_sources' => 30,
            'max_clients' => 10,
            'max_users' => 3,
            'max_reports_per_month' => 50,
            'allowed_connectors' => null,
            'features' => ['ai_builder' => true, 'white_label' => true, 'remove_branding' => false, 'custom_domain' => false],
            'monthly_price' => 49,
            'currency' => 'USD',
        ];
    }

    public function unlimited(): self
    {
        return $this->state(fn (): array => [
            'max_sites' => null,
            'max_data_sources' => null,
            'max_clients' => null,
            'max_users' => null,
            'max_reports_per_month' => null,
        ]);
    }
}
