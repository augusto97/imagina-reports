<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\Site;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->getJson("/api/v1/public/reports/{$report->public_token}")
            ->assertOk()
            ->assertJsonCount(1, 'data.w1')
            ->assertJsonPath('data.w1.0.description', 'Actualizaciones aplicadas')
            ->assertJsonPath('data.w1.0.minutes', 60);
    }
}
