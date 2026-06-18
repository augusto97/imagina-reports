<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkLogApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    private function report(): Report
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);
        $definition = ReportDefinition::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id]);

        return Report::factory()->create(['agency_id' => $this->agency->id, 'report_definition_id' => $definition->id]);
    }

    public function test_store_adds_a_work_log_to_the_report(): void
    {
        $report = $this->report();

        $this->postJson("/api/v1/reports/{$report->id}/work-logs", [
            'performed_at' => '2026-06-10',
            'description' => 'Plugins actualizados y backup verificado',
        ])
            ->assertCreated()
            ->assertJsonPath('description', 'Plugins actualizados y backup verificado');

        $this->assertDatabaseHas('ir_report_work_logs', [
            'report_id' => $report->id,
            'agency_id' => $this->agency->id,
        ]);
    }

    public function test_index_lists_the_reports_work_logs(): void
    {
        $report = $this->report();
        WorkLog::factory()->create([
            'agency_id' => $this->agency->id,
            'report_id' => $report->id,
            'site_id' => $report->definition?->site_id,
        ]);

        $this->getJson("/api/v1/reports/{$report->id}/work-logs")->assertOk()->assertJsonCount(1);
    }

    public function test_it_cannot_add_to_another_agencys_report(): void
    {
        $other = Report::factory()->create();

        $this->postJson("/api/v1/reports/{$other->id}/work-logs", [
            'performed_at' => '2026-06-10',
            'description' => 'x',
        ])->assertNotFound();
    }
}
