<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Mail\ReportReadyMail;
use App\Models\Agency;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\ReportDelivery;
use App\Models\User;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePdfRenderer;
use Tests\TestCase;

class ReportDeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    private function report(?Agency $agency = null): Report
    {
        $agency ??= $this->agency;
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'recipients' => ['ana@cliente.test']]);

        return Report::factory()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id, 'pdf_path' => 'reports/x.pdf']);
    }

    private function delivery(Report $report, DeliveryStatus $status): ReportDelivery
    {
        return ReportDelivery::query()->create([
            'agency_id' => $report->agency_id,
            'report_id' => $report->id,
            'channel' => DeliveryChannel::Email,
            'recipient' => 'ana@cliente.test',
            'status' => $status,
            'error' => $status === DeliveryStatus::Failed ? 'SMTP timeout' : null,
        ]);
    }

    public function test_index_lists_a_reports_deliveries(): void
    {
        $report = $this->report();
        $this->delivery($report, DeliveryStatus::Sent);
        $this->delivery($report, DeliveryStatus::Failed);

        $this->getJson("/api/v1/reports/{$report->id}/deliveries")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.recipient', 'ana@cliente.test');
    }

    public function test_retry_resends_and_records_a_fresh_attempt(): void
    {
        Mail::fake();
        Storage::fake();
        $this->app->instance(PdfRenderer::class, new FakePdfRenderer);

        $report = $this->report();
        $failed = $this->delivery($report, DeliveryStatus::Failed);

        $this->postJson("/api/v1/report-deliveries/{$failed->id}/retry")
            ->assertStatus(201)
            ->assertJsonPath('status', 'sent');

        Mail::assertSent(ReportReadyMail::class, 1);
        $this->assertSame(2, ReportDelivery::query()->where('report_id', $report->id)->count());
    }

    public function test_retry_failed_resends_all_failed_recipients(): void
    {
        Mail::fake();
        Storage::fake();
        $this->app->instance(PdfRenderer::class, new FakePdfRenderer);

        $report = $this->report();
        $this->delivery($report, DeliveryStatus::Failed);
        $this->delivery($report, DeliveryStatus::Sent);

        $this->postJson("/api/v1/reports/{$report->id}/deliveries/retry-failed")
            ->assertOk()
            ->assertJsonCount(1);

        Mail::assertSent(ReportReadyMail::class, 1);
    }

    public function test_it_cannot_read_another_agencys_deliveries(): void
    {
        $report = $this->report(Agency::factory()->create());

        $this->getJson("/api/v1/reports/{$report->id}/deliveries")->assertNotFound();
    }
}
