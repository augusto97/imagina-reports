<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ReportStatus;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    private function definition(): ReportDefinition
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);

        return ReportDefinition::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id]);
    }

    public function test_generate_queues_and_produces_a_report(): void
    {
        $definition = $this->definition();

        $this->postJson('/api/v1/reports/generate', [
            'report_definition_id' => $definition->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ])->assertStatus(202);

        // QUEUE is sync in tests, so the report is generated immediately.
        $this->assertDatabaseHas('ir_reports', [
            'report_definition_id' => $definition->id,
            'agency_id' => $this->agency->id,
        ]);
    }

    public function test_it_cannot_generate_for_another_agencys_definition(): void
    {
        $other = ReportDefinition::factory()->create();

        $this->postJson('/api/v1/reports/generate', [
            'report_definition_id' => $other->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ])->assertNotFound();
    }

    public function test_index_and_approve(): void
    {
        $definition = $this->definition();
        $report = Report::factory()->create([
            'agency_id' => $this->agency->id,
            'report_definition_id' => $definition->id,
            'status' => ReportStatus::Draft,
        ]);

        $this->getJson('/api/v1/reports')->assertOk()->assertJsonCount(1);

        $this->postJson("/api/v1/reports/{$report->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertSame(ReportStatus::Approved, $report->fresh()?->status);
    }
}
