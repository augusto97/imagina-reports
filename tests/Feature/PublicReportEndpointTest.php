<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PublicReportEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_report_by_public_token_without_auth(): void
    {
        $agency = Agency::factory()->create(['name' => 'Imagina WP']);
        $report = Report::factory()->create([
            'agency_id' => $agency->id,
            'health_score' => 88,
            'resolved_blocks' => [
                'blocks' => [['id' => 'h', 'type' => 'header', 'binding' => null, 'props' => [], 'style' => []]],
                'data' => ['h' => null],
            ],
        ]);

        $this->getJson("/api/v1/public/reports/{$report->public_token}")
            ->assertOk()
            ->assertJsonPath('health_score', 88)
            ->assertJsonPath('agency.name', 'Imagina WP')
            ->assertJsonPath('blocks.0.id', 'h');
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->getJson('/api/v1/public/reports/does-not-exist')->assertNotFound();
    }

    public function test_a_suspended_agencys_public_report_goes_dark(): void
    {
        // An unpaid agency must stop consuming the platform: its whole public surface
        // (portal data, period selector, embeds) returns 402 until it reactivates.
        $agency = Agency::factory()->create(['status' => 'suspended']);
        $report = Report::factory()->create(['agency_id' => $agency->id]);

        $this->getJson("/api/v1/public/reports/{$report->public_token}")->assertStatus(402);
        $this->getJson("/api/v1/public/reports/{$report->public_token}/periods")->assertStatus(402);
        $this->get("/embed/{$report->public_token}")->assertStatus(402);
    }

    public function test_reactivating_the_agency_restores_public_access(): void
    {
        $agency = Agency::factory()->create(['status' => 'suspended']);
        $report = Report::factory()->create(['agency_id' => $agency->id]);

        $agency->update(['status' => 'active']);

        $this->getJson("/api/v1/public/reports/{$report->public_token}")->assertOk();
    }

    public function test_it_exposes_merge_field_context(): void
    {
        $agency = Agency::factory()->create(['name' => 'Imagina WP']);
        $client = Client::factory()->create(['agency_id' => $agency->id, 'name' => 'Acme']);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id, 'name' => 'acme.com', 'currency' => 'COP']);
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id]);
        $report = Report::factory()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id]);

        $this->getJson("/api/v1/public/reports/{$report->public_token}")
            ->assertOk()
            ->assertJsonPath('context.agency', 'Imagina WP')
            ->assertJsonPath('context.client', 'Acme')
            ->assertJsonPath('context.site', 'acme.com')
            // The site reports in its own currency (no FX conversion).
            ->assertJsonPath('currency', 'COP');
    }

    public function test_it_lists_sibling_periods_for_the_selector(): void
    {
        $agency = Agency::factory()->create();
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id]);

        $current = Report::factory()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id]);
        Report::factory()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id]);
        // A report from a different definition must NOT appear.
        Report::factory()->create(['agency_id' => $agency->id]);

        $this->getJson("/api/v1/public/reports/{$current->public_token}/periods")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure([['public_token', 'period_start', 'period_end']]);
    }

    public function test_it_exposes_the_report_theme(): void
    {
        $report = Report::factory()->create([
            'agency_id' => Agency::factory()->create()->id,
            'resolved_blocks' => [
                'blocks' => [],
                'data' => [],
                'theme' => ['accent' => '#10b981', 'density' => 'compact'],
            ],
        ]);

        $this->getJson("/api/v1/public/reports/{$report->public_token}")
            ->assertOk()
            ->assertJsonPath('theme.accent', '#10b981')
            ->assertJsonPath('theme.density', 'compact');
    }

    public function test_a_private_report_is_forbidden_via_the_public_token(): void
    {
        $agency = Agency::factory()->create();
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'visibility' => 'private']);
        $report = Report::factory()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id]);

        $this->getJson("/api/v1/public/reports/{$report->public_token}")
            ->assertForbidden();
    }

    public function test_a_private_report_renders_with_the_server_print_token(): void
    {
        $agency = Agency::factory()->create();
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'visibility' => 'private']);
        $report = Report::factory()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id]);

        // Browsershot carries the server-only print token, so the PDF still renders.
        $this->getJson("/api/v1/public/reports/{$report->public_token}", ['X-Print-Token' => $report->printToken()])
            ->assertOk()
            ->assertJsonPath('status', $report->status->value);
    }

    public function test_a_password_protected_report_demands_the_password(): void
    {
        $agency = Agency::factory()->create();
        $definition = ReportDefinition::factory()->create([
            'agency_id' => $agency->id,
            'visibility' => 'password',
            'password_hash' => Hash::make('s3cret'),
        ]);
        $report = Report::factory()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id]);

        // No password → 401 with the prompt flag.
        $this->getJson("/api/v1/public/reports/{$report->public_token}")
            ->assertUnauthorized()
            ->assertJsonPath('requires_password', true);

        // Wrong password → still 401.
        $this->getJson("/api/v1/public/reports/{$report->public_token}", ['X-Report-Password' => 'nope'])
            ->assertUnauthorized();

        // Correct password → the report.
        $this->getJson("/api/v1/public/reports/{$report->public_token}", ['X-Report-Password' => 's3cret'])
            ->assertOk();
    }

    public function test_an_agency_can_update_its_definition_sharing_settings(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id]);

        $this->actingAs($user)
            ->putJson("/api/v1/report-definitions/{$definition->id}/sharing", [
                'visibility' => 'password',
                'password' => 'letmein',
                'embed_domains' => ['https://acme.com/dashboard', ' ACME.com ', 'partner.io'],
            ])
            ->assertOk()
            ->assertJsonPath('visibility', 'password')
            ->assertJsonPath('has_password', true)
            // Domains are normalised to bare, de-duplicated hosts.
            ->assertJsonPath('embed_domains', ['acme.com', 'partner.io']);

        $definition->refresh();
        $this->assertTrue(Hash::check('letmein', (string) $definition->password_hash));
    }

    public function test_enabling_dashboard_mode_mints_a_stable_token(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id]);

        $this->actingAs($user)
            ->putJson("/api/v1/report-definitions/{$definition->id}/sharing", [
                'visibility' => 'public',
                'dashboard_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('dashboard_enabled', true);

        $token = $definition->refresh()->dashboard_token;
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Disabling then re-enabling reuses the same URL (stable token).
        $this->actingAs($user)
            ->putJson("/api/v1/report-definitions/{$definition->id}/sharing", ['visibility' => 'public', 'dashboard_enabled' => false])
            ->assertOk()
            ->assertJsonPath('dashboard_enabled', false);

        $this->actingAs($user)
            ->putJson("/api/v1/report-definitions/{$definition->id}/sharing", ['visibility' => 'public', 'dashboard_enabled' => true])
            ->assertOk();

        $this->assertSame($token, $definition->refresh()->dashboard_token);
    }

    public function test_rotating_the_dashboard_token_revokes_the_old_link(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        $definition = ReportDefinition::factory()->create([
            'agency_id' => $agency->id,
            'dashboard_enabled' => true,
            'dashboard_token' => 'old-token',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/report-definitions/{$definition->id}/sharing/dashboard-token")
            ->assertOk();

        $fresh = $definition->refresh()->dashboard_token;
        $this->assertIsString($fresh);
        $this->assertNotSame('old-token', $fresh);

        // The old link no longer resolves; the new one does.
        $this->getJson('/api/v1/public/dashboards/old-token')->assertNotFound();
        $this->getJson("/api/v1/public/dashboards/{$fresh}")->assertOk();
    }

    public function test_switching_away_from_password_visibility_clears_the_password(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        $definition = ReportDefinition::factory()->create([
            'agency_id' => $agency->id,
            'visibility' => 'password',
            'password_hash' => Hash::make('old'),
        ]);

        $this->actingAs($user)
            ->putJson("/api/v1/report-definitions/{$definition->id}/sharing", ['visibility' => 'public'])
            ->assertOk()
            ->assertJsonPath('has_password', false);

        $this->assertNull($definition->refresh()->password_hash);
    }

    public function test_it_overlays_site_work_logs_in_period_onto_the_worklog_block(): void
    {
        $agency = Agency::factory()->create();
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id]);
        $report = Report::factory()->create([
            'agency_id' => $agency->id,
            'report_definition_id' => $definition->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'resolved_blocks' => [
                'blocks' => [['id' => 'w1', 'type' => 'worklog_timeline', 'binding' => null, 'props' => [], 'style' => []]],
                'data' => ['w1' => []],
            ],
        ]);

        // A daily quick-add log (report_id null) inside the period — must appear with its time.
        WorkLog::factory()->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'report_id' => null,
            'performed_at' => '2026-06-15',
            'description' => 'Actualizaciones aplicadas',
            'minutes' => 60,
        ]);
        // A log outside the period must NOT appear.
        WorkLog::factory()->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'performed_at' => '2026-05-15',
            'description' => 'Mes anterior',
        ]);
        // A planned (not-done) task inside the period must NOT reach the client report.
        WorkLog::factory()->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'performed_at' => '2026-06-20',
            'description' => 'Tarea planificada',
            'status' => 'planned',
        ]);

        $this->getJson("/api/v1/public/reports/{$report->public_token}")
            ->assertOk()
            ->assertJsonCount(1, 'data.w1')
            ->assertJsonPath('data.w1.0.description', 'Actualizaciones aplicadas')
            ->assertJsonPath('data.w1.0.minutes', 60);
    }
}
