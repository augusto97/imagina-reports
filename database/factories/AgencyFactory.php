<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    protected $model = Agency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'logo_path' => null,
            'brand_color' => fake()->hexColor(),
            'default_locale' => 'es',
            'domain' => null,
            'settings' => null,
        ];
    }
}
