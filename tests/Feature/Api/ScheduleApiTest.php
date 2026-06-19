<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\ReportDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    public function test_store_creates_a_schedule_with_a_future_next_run(): void
    {
        $definition = ReportDefinition::factory()->create(['agency_id' => $this->agency->id]);

        $this->postJson('/api/v1/schedules', [
            'report_definition_id' => $definition->id,
            'cadence' => 'monthly',
        ])
            ->assertCreated()
            ->assertJsonPath('cadence', 'monthly');

        $this->assertDatabaseHas('ir_schedules', [
            'report_definition_id' => $definition->id,
            'agency_id' => $this->agency->id,
        ]);
    }

    public function test_it_cannot_schedule_another_agencys_definition(): void
    {
        $other = ReportDefinition::factory()->create();

        $this->postJson('/api/v1/schedules', [
            'report_definition_id' => $other->id,
            'cadence' => 'weekly',
        ])->assertNotFound();
    }
}
