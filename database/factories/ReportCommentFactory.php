<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CommentVisibility;
use App\Models\Agency;
use App\Models\Report;
use App\Models\ReportComment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportComment>
 */
class ReportCommentFactory extends Factory
{
    protected $model = ReportComment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'report_id' => Report::factory(),
            'author_user_id' => null,
            'body' => fake()->sentence(),
            'visibility' => CommentVisibility::Internal,
        ];
    }

    public function client(): static
    {
        return $this->state(fn (): array => ['visibility' => CommentVisibility::Client]);
    }
}
