<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\ReportDefinition;
use App\Models\Schedule;
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

    public function test_monthly_schedule_fires_on_the_designated_day(): void
    {
        $definition = ReportDefinition::factory()->create(['agency_id' => $this->agency->id]);

        $this->postJson('/api/v1/schedules', [
            'report_definition_id' => $definition->id,
            'cadence' => 'monthly',
            'send_day' => 5,
        ])
            ->assertCreated()
            ->assertJsonPath('send_day', 5)
            ->assertJsonPath('next_run_at', fn (string $value): bool => str_contains($value, '-05T') || str_contains($value, '-05T00'));

        $schedule = Schedule::query()->firstOrFail();
        $this->assertSame(5, $schedule->send_day);
        $this->assertSame(5, $schedule->next_run_at->day);
    }

    public function test_it_cannot_schedule_another_agencys_definition(): void
    {
        $other = ReportDefinition::factory()->create();

        $this->postJson('/api/v1/schedules', [
            'report_definition_id' => $other->id,
            'cadence' => 'weekly',
        ])->assertNotFound();
    }

    public function test_store_replaces_an_existing_schedule_for_the_definition(): void
    {
        $definition = ReportDefinition::factory()->create(['agency_id' => $this->agency->id]);

        $this->postJson('/api/v1/schedules', ['report_definition_id' => $definition->id, 'cadence' => 'monthly'])->assertCreated();
        $this->postJson('/api/v1/schedules', ['report_definition_id' => $definition->id, 'cadence' => 'weekly'])->assertCreated();

        // Only one schedule remains, with the latest cadence — no stale duplicate.
        $this->assertSame(1, Schedule::query()->where('report_definition_id', $definition->id)->count());
        $this->assertDatabaseHas('ir_schedules', ['report_definition_id' => $definition->id, 'cadence' => 'weekly']);
    }

    public function test_destroy_removes_a_schedule(): void
    {
        $definition = ReportDefinition::factory()->create(['agency_id' => $this->agency->id]);
        $schedule = Schedule::factory()->create(['agency_id' => $this->agency->id, 'report_definition_id' => $definition->id]);

        $this->deleteJson("/api/v1/schedules/{$schedule->id}")->assertOk();

        $this->assertDatabaseMissing('ir_schedules', ['id' => $schedule->id]);
    }

    public function test_it_cannot_delete_another_agencys_schedule(): void
    {
        $schedule = Schedule::factory()->create();

        $this->deleteJson("/api/v1/schedules/{$schedule->id}")->assertNotFound();
    }
}
