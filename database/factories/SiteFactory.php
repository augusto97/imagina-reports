<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'client_id' => Client::factory(),
            'name' => fake()->domainWord(),
            'url' => fake()->url(),
            'hosting' => 'ServerAvatar',
            'support_plan' => 'care',
            'status' => 'active',
        ];
    }
}
