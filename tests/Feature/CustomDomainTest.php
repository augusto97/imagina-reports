<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Mail\ReportReadyMail;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\Site;
use App\Models\User;
use App\Services\DeliveryService;
use App\Services\Domains\DnsResolver;
use App\Services\Domains\DomainVerifier;
use App\Services\Pdf\PdfRenderer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\FakeDnsResolver;
use Tests\Support\FakePdfRenderer;
use Tests\TestCase;

class CustomDomainTest extends TestCase
{
    use RefreshDatabase;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://app.imagina.test']);
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
    }

    private function agency(bool $feature = true): Agency
    {
        $plan = Plan::factory()->create(['features' => ['custom_domain' => $feature, 'white_label' => true, 'remove_branding' => false, 'ai_builder' => false]]);
        $agency = Agency::factory()->create(['plan_id' => $plan->id, 'name' => 'Agencia Sol']);
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        return $agency;
    }

    private function report(Agency $agency): Report
    {
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id, 'name' => 'Hotel Sol']);
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id]);

        return Report::factory()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id, 'status' => ReportStatus::Approved, 'pdf_path' => null]);
    }

    private function goLive(): void
    {
        $this->dns->cnames['reportes.sol.test'] = 'app.imagina.test';
        Http::fake(['https://reportes.sol.test/api/v1/domain-check' => Http::response(['app' => 'imagina-reports', 'instance' => DomainVerifier::instance()])]);
    }

    public function test_the_plan_must_include_its_own_domain(): void
    {
        $this->agency(feature: false);

        $this->putJson('/api/v1/agency/domain', ['domain' => 'reportes.sol.test'])->assertForbidden();
    }

    public function test_the_domain_waits_for_dns_then_https_then_goes_live(): void
    {
        $agency = $this->agency();
        // First HTTPS check: no certificate yet. Second: answers as this installation.
        Http::fake(['https://reportes.sol.test/*' => Http::sequence()
            ->push('nope', 404)
            ->push(['app' => 'imagina-reports', 'instance' => DomainVerifier::instance()])]);

        // 1. No DNS record yet: says exactly which CNAME to create.
        $this->putJson('/api/v1/agency/domain', ['domain' => 'https://Reportes.Sol.test/'])
            ->assertOk()
            ->assertJsonPath('domain', 'reportes.sol.test')
            ->assertJsonPath('domain_status', 'pending_dns')
            ->assertJsonPath('domain_target', 'app.imagina.test')
            ->assertJsonPath('public_base_url', 'https://app.imagina.test');
        $this->assertStringContainsString('CNAME', (string) $agency->fresh()?->domain_error);

        // 2. DNS fine, but no certificate on the server yet.
        $this->dns->cnames['reportes.sol.test'] = 'app.imagina.test';
        $this->postJson('/api/v1/agency/domain/check')->assertOk()->assertJsonPath('domain_status', 'pending_ssl');

        // 3. HTTPS answers as this installation: live, and client links use it.
        $this->postJson('/api/v1/agency/domain/check')
            ->assertOk()
            ->assertJsonPath('domain_status', 'active')
            ->assertJsonPath('public_base_url', 'https://reportes.sol.test');
    }

    public function test_bad_or_taken_domains_are_refused(): void
    {
        Agency::factory()->create(['domain' => 'reportes.otra.test']);
        $this->agency();

        $this->putJson('/api/v1/agency/domain', ['domain' => 'no es un dominio'])->assertUnprocessable();
        $this->putJson('/api/v1/agency/domain', ['domain' => 'clientes.app.imagina.test'])->assertUnprocessable();
        $this->putJson('/api/v1/agency/domain', ['domain' => 'reportes.otra.test'])->assertUnprocessable();
    }

    public function test_the_domain_only_serves_its_agencys_client_pages(): void
    {
        // The pages are Blade shells for the SPA; CI's backend job has no Vite build.
        $this->withoutVite();
        $agency = $this->agency();
        $this->goLive();
        $this->putJson('/api/v1/agency/domain', ['domain' => 'reportes.sol.test'])->assertJsonPath('domain_status', 'active');
        $mine = $this->report($agency);
        $this->app['auth']->forgetGuards();

        $this->get("http://reportes.sol.test/portal/{$mine->public_token}")->assertOk();
        $this->getJson("http://reportes.sol.test/api/v1/public/reports/{$mine->public_token}")->assertOk();

        // Another agency's report can't be opened through this agency's domain.
        $other = $this->report(Agency::factory()->create());
        $this->getJson("http://reportes.sol.test/api/v1/public/reports/{$other->public_token}")->assertNotFound();
        $this->getJson("http://app.imagina.test/api/v1/public/reports/{$other->public_token}")->assertOk();

        // The panel lives on the platform's address; the bare domain is the agency's portal page.
        $this->get('http://reportes.sol.test/admin?x=1')->assertRedirect('https://app.imagina.test/admin?x=1');
        $this->get('http://reportes.sol.test/')->assertOk()->assertSee('Agencia Sol');
    }

    public function test_the_email_link_uses_the_live_domain(): void
    {
        Storage::fake();
        $agency = $this->agency();
        $this->goLive();
        $this->putJson('/api/v1/agency/domain', ['domain' => 'reportes.sol.test'])->assertJsonPath('domain_status', 'active');
        app(TenantContext::class)->set($agency->id);
        $report = $this->report($agency);

        (new ReportReadyMail($report->fresh() ?? $report))->assertSeeInHtml('https://reportes.sol.test/portal/'.$report->public_token);
    }

    public function test_a_domain_that_stops_answering_falls_back_to_the_platform(): void
    {
        $agency = $this->agency();
        $this->goLive();
        $this->putJson('/api/v1/agency/domain', ['domain' => 'reportes.sol.test'])->assertJsonPath('domain_status', 'active');
        Cache::flush();

        unset($this->dns->cnames['reportes.sol.test']);
        $this->artisan('domains:check', ['--all' => true])->assertSuccessful();

        $this->assertSame('pending_dns', $agency->fresh()?->domain_status);
        $this->assertSame('https://app.imagina.test', $agency->fresh()?->publicBaseUrl());
    }

    public function test_the_agency_sender_is_stored_encrypted_and_used_for_reports(): void
    {
        Storage::fake();
        $this->app->instance(PdfRenderer::class, new FakePdfRenderer);
        $agency = $this->agency();

        $this->putJson('/api/v1/agency/sender', [
            'from_address' => 'informes@sol.test', 'from_name' => 'Agencia Sol', 'host' => 'smtp.sol.test', 'port' => 587, 'username' => 'informes@sol.test', 'password' => 's3creta',
        ])->assertOk()->assertJsonPath('sender.password_set', true)->assertJsonMissingPath('sender.password');

        $stored = $agency->fresh()?->settings['sender']['password'] ?? '';
        $this->assertNotSame('s3creta', $stored);
        $this->assertSame('s3creta', Crypt::decryptString($stored));

        // Sending goes through the agency's own server, from its own address.
        app(TenantContext::class)->set($agency->id);
        $report = $this->report($agency);
        $report->definition?->forceFill(['recipients' => ['ana@cliente.test']])->save();
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->withArgs(function (ReportReadyMail $mail): bool {
            $from = $mail->envelope()->from;

            return $from !== null && $from->address === 'informes@sol.test' && $from->name === 'Agencia Sol';
        });
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('to')->with('ana@cliente.test')->andReturn($pending);
        Mail::shouldReceive('build')->once()->withArgs(fn (array $config): bool => $config['host'] === 'smtp.sol.test' && $config['password'] === 's3creta')->andReturn($mailer);

        app(DeliveryService::class)->deliver($report->fresh() ?? $report);
    }
}
