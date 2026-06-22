<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ReportStatus;
use App\Jobs\DeliverReportJob;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\Site;
use App\Models\User;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePdfRenderer;
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

    public function test_send_queues_delivery_for_an_approved_report(): void
    {
        Queue::fake();
        $definition = $this->definition();
        $report = Report::factory()->create([
            'agency_id' => $this->agency->id,
            'report_definition_id' => $definition->id,
            'status' => ReportStatus::Approved,
        ]);

        $this->postJson("/api/v1/reports/{$report->id}/send")->assertStatus(202);

        Queue::assertPushed(DeliverReportJob::class, fn (DeliverReportJob $job): bool => $job->reportId === $report->id);
    }

    public function test_send_is_blocked_for_a_draft(): void
    {
        Queue::fake();
        $definition = $this->definition();
        $report = Report::factory()->create([
            'agency_id' => $this->agency->id,
            'report_definition_id' => $definition->id,
            'status' => ReportStatus::Draft,
        ]);

        $this->postJson("/api/v1/reports/{$report->id}/send")->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_definition_stores_recipients(): void
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);

        $this->postJson('/api/v1/report-definitions', [
            'site_id' => $site->id,
            'name' => 'Mensual',
            'recipients' => ['cliente@example.com', 'pm@example.com'],
        ])->assertCreated()->assertJsonPath('recipients', ['cliente@example.com', 'pm@example.com']);
    }

    public function test_definition_rejects_an_invalid_recipient(): void
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);

        $this->postJson('/api/v1/report-definitions', [
            'site_id' => $site->id,
            'name' => 'Mensual',
            'recipients' => ['no-es-un-email'],
        ])->assertStatus(422)->assertJsonValidationErrors('recipients.0');
    }

    public function test_a_report_can_be_deleted(): void
    {
        $report = Report::factory()->create([
            'agency_id' => $this->agency->id,
            'report_definition_id' => $this->definition()->id,
        ]);

        $this->deleteJson("/api/v1/reports/{$report->id}")->assertOk();
        $this->assertDatabaseMissing('ir_reports', ['id' => $report->id]);
    }

    public function test_it_cannot_delete_another_agencys_report(): void
    {
        $other = Report::factory()->create();

        $this->deleteJson("/api/v1/reports/{$other->id}")->assertNotFound();
        $this->assertDatabaseHas('ir_reports', ['id' => $other->id]);
    }

    public function test_deleting_a_definition_cascades_its_reports(): void
    {
        $definition = $this->definition();
        $report = Report::factory()->create([
            'agency_id' => $this->agency->id,
            'report_definition_id' => $definition->id,
        ]);

        $this->deleteJson("/api/v1/report-definitions/{$definition->id}")->assertOk();
        $this->assertDatabaseMissing('ir_report_definitions', ['id' => $definition->id]);
        $this->assertDatabaseMissing('ir_reports', ['id' => $report->id]);
    }

    public function test_it_downloads_a_report_pdf(): void
    {
        $this->app->instance(PdfRenderer::class, new FakePdfRenderer);

        $report = Report::factory()->create([
            'agency_id' => $this->agency->id,
            'report_definition_id' => $this->definition()->id,
        ]);

        $this->get("/api/v1/reports/{$report->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
