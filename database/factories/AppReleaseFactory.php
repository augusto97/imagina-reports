<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AppRelease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppRelease>
 */
class AppReleaseFactory extends Factory
{
    protected $model = AppRelease::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version' => '2.0.0',
            'channel' => 'stable',
            'bundle_url' => 'https://updater.imagina.cloud/releases/2.0.0.zip',
            'checksum' => fake()->sha256(),
            'released_at' => now(),
        ];
    }
}
