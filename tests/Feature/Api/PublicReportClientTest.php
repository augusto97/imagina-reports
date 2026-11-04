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
use App\Services\Pdf\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePdfRenderer;
use Tests\TestCase;

/** What the client sees and does on the portal: visits are counted, the PDF downloads. */
class PublicReportClientTest extends TestCase
{
    use RefreshDatabase;

    private function report(Agency $agency): Report
    {
        $client = Client::factory()->create(['agency_id' => $agency->id, 'name' => 'Hotel Sol']);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id, 'name' => 'hotelsol.com']);
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id, 'name' => 'Informe Hotel Sol']);

        return Report::factory()->create([
            'agency_id' => $agency->id,
            'report_definition_id' => $definition->id,
            'status' => ReportStatus::Sent,
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-10-31 23:59:59',
        ]);
    }

    public function test_a_client_visit_is_counted_once_per_visit(): void
    {
        $report = $this->report(Agency::factory()->create());

        $this->getJson("/api/v1/public/reports/{$report->public_token}")
            ->assertOk()
            ->assertJsonPath('period_label', 'octubre 2026')
            ->assertJsonPath('context.period', 'octubre 2026');
        $this->getJson("/api/v1/public/reports/{$report->public_token}")->assertOk();

        $fresh = $report->fresh();
        $this->assertSame(1, $fresh?->view_count);
        $this->assertNotNull($fresh->first_viewed_at);

        // The agency sees it in its reports list.
        $this->actingAs(User::factory()->create(['agency_id' => $report->agency_id]))
            ->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonPath('0.view_count', 1);
        $this->app['auth']->forgetGuards();

        // Coming back later is a new visit.
        $this->travel(2)->hours();
        $this->getJson("/api/v1/public/reports/{$report->public_token}")->assertOk();
        $this->assertSame(2, $report->fresh()?->view_count);
    }

    public function test_the_agency_previewing_and_the_pdf_renderer_are_not_visits(): void
    {
        $agency = Agency::factory()->create();
        $report = $this->report($agency);

        $this->getJson("/api/v1/public/reports/{$report->public_token}?print={$report->printToken()}")->assertOk();
        $this->actingAs(User::factory()->create(['agency_id' => $agency->id]), 'web')
            ->getJson("/api/v1/public/reports/{$report->public_token}")->assertOk();

        $this->assertSame(0, $report->fresh()?->view_count);
        $this->assertNull($report->fresh()?->first_viewed_at);
    }

    public function test_the_agency_contact_reaches_the_portal(): void
    {
        $agency = Agency::factory()->create(['settings' => ['contact_email' => 'hola@agencia.test', 'contact_phone' => '+57 300 1234567']]);
        $report = $this->report($agency);

        $this->getJson("/api/v1/public/reports/{$report->public_token}")
            ->assertOk()
            ->assertJsonPath('agency.contact.email', 'hola@agencia.test')
            ->assertJsonPath('agency.contact.phone', '+57 300 1234567')
            ->assertJsonPath('site.name', 'hotelsol.com');
    }

    public function test_the_client_downloads_the_pdf_rendered_once(): void
    {
        Storage::fake();
        $renderer = new FakePdfRenderer;
        $this->app->instance(PdfRenderer::class, $renderer);
        $report = $this->report(Agency::factory()->create());

        $response = $this->get("/api/v1/public/reports/{$report->public_token}/pdf")->assertOk();
        $this->assertStringContainsString('informe-hotel-sol-2026-10.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertNotNull($renderer->lastUrl);

        // A second download reuses the stored file instead of launching Chromium again.
        $renderer->lastUrl = null;
        $this->get("/api/v1/public/reports/{$report->public_token}/pdf")->assertOk();
        $this->assertNull($renderer->lastUrl);
    }

    public function test_a_password_protected_report_keeps_its_pdf_locked(): void
    {
        $report = $this->report(Agency::factory()->create());
        $report->definition?->forceFill(['visibility' => 'password', 'password_hash' => bcrypt('secreto')])->save();

        $this->getJson("/api/v1/public/reports/{$report->public_token}/pdf")->assertStatus(401);
    }
}
