<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\ReportStatus;
use App\Mail\ReportReadyMail;
use App\Models\Agency;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\ReportDelivery;
use App\Services\DeliveryService;
use App\Services\Pdf\PdfRenderer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePdfRenderer;
use Tests\TestCase;

class DeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_the_pdf_emails_recipients_and_records_deliveries(): void
    {
        Mail::fake();
        Storage::fake();
        $this->app->instance(PdfRenderer::class, new FakePdfRenderer);

        $agency = Agency::factory()->create();
        app(TenantContext::class)->set($agency->id);
        $definition = ReportDefinition::factory()->create([
            'agency_id' => $agency->id,
            'recipients' => ['ana@cliente.test', 'beto@cliente.test'],
        ]);
        $report = Report::factory()->create([
            'agency_id' => $agency->id,
            'report_definition_id' => $definition->id,
            'pdf_path' => null,
        ]);

        app(DeliveryService::class)->deliver($report);

        Mail::assertSent(ReportReadyMail::class, 2);
        $this->assertSame(2, ReportDelivery::query()->where('status', DeliveryStatus::Sent)->count());

        $report->refresh();
        $this->assertSame(ReportStatus::Sent, $report->status);
        $this->assertNotNull($report->pdf_path);
    }

    public function test_the_email_is_localized_to_the_report_locale(): void
    {
        Storage::fake();
        $agency = Agency::factory()->create(['name' => 'Acme']);
        app(TenantContext::class)->set($agency->id);

        $english = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'locale' => 'en']);
        $report = Report::factory()->create(['agency_id' => $agency->id, 'report_definition_id' => $english->id, 'pdf_path' => null]);

        $mail = new ReportReadyMail($report);
        $mail->assertSeeInHtml('Your report is ready');
        $mail->assertDontSeeInHtml('Tu reporte está listo');
    }

    public function test_it_does_not_mark_sent_when_every_email_fails(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        Storage::fake();
        $this->app->instance(PdfRenderer::class, new FakePdfRenderer);

        $agency = Agency::factory()->create();
        app(TenantContext::class)->set($agency->id);
        $definition = ReportDefinition::factory()->create([
            'agency_id' => $agency->id,
            'recipients' => ['ana@cliente.test'],
        ]);
        $report = Report::factory()->create([
            'agency_id' => $agency->id,
            'report_definition_id' => $definition->id,
            'status' => ReportStatus::Approved,
            'pdf_path' => null,
        ]);

        app(DeliveryService::class)->deliver($report);

        // The failed attempt is recorded, but the report stays Approved (not a false "Sent").
        $this->assertSame(1, ReportDelivery::query()->where('status', DeliveryStatus::Failed)->count());
        $this->assertSame(ReportStatus::Approved, $report->refresh()->status);
    }
}
