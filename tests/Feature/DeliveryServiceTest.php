<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\ReportStatus;
use App\Mail\ReportReadyMail;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\ReportDelivery;
use App\Models\Site;
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
        $mail->assertSeeInHtml('report is ready');
        $mail->assertSeeInHtml('View the full report');
        $mail->assertDontSeeInHtml('está listo');
    }

    public function test_the_email_is_white_label_and_carries_the_headline_figures(): void
    {
        Storage::fake();
        $agency = Agency::factory()->create(['name' => 'Agencia Sol', 'settings' => ['contact_email' => 'hola@sol.test']]);
        app(TenantContext::class)->set($agency->id);
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id, 'name' => 'Hotel Mirador']);
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id, 'locale' => 'es']);
        $report = Report::factory()->create([
            'agency_id' => $agency->id,
            'report_definition_id' => $definition->id,
            'pdf_path' => null,
            'health_score' => 91,
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
            'executive_summary' => 'Este mes tu sitio recibió 4737 visitas.',
            'resolved_blocks' => [
                'blocks' => [
                    ['id' => 'v', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions'], 'props' => ['label' => 'Visitas'], 'style' => []],
                    ['id' => 'u', 'type' => 'kpi', 'binding' => ['source' => 'betteruptime', 'metric' => 'uptime_percent'], 'props' => ['label' => 'Disponibilidad'], 'style' => ['format' => 'percent']],
                ],
                'data' => ['v' => ['value' => 4737, 'previous' => 4518, 'change_percent' => 4.8], 'u' => 99.9],
            ],
        ]);

        $mail = new ReportReadyMail($report);

        $mail->assertHasSubject('Hotel Mirador · tu informe de octubre 2026 está listo');
        $mail->assertFrom((string) config('mail.from.address'), 'Agencia Sol');
        $mail->assertHasReplyTo('hola@sol.test');
        $mail->assertSeeInHtml('91');
        $mail->assertSeeInHtml('Excelente');
        $mail->assertSeeInHtml('4737');
        $mail->assertSeeInHtml('+4,8 %');
        $mail->assertSeeInHtml('99,9 %');
        $mail->assertSeeInHtml('/portal/'.$report->public_token);
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
