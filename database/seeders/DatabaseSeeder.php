<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        // The founding agency runs on the top (unlimited) plan.
        $plan = Plan::query()->where('slug', 'agency')->first();

        $agency = Agency::factory()->create([
            'name' => 'Imagina WP',
            'slug' => 'imagina-wp',
            'plan_id' => $plan?->id,
        ]);

        User::factory()->create([
            'agency_id' => $agency->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => UserRole::Owner,
        ]);
    }
}
