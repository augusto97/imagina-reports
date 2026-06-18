<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Report;
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

    public function test_it_lists_sibling_periods_for_the_selector(): void
    {
        $agency = Agency::factory()->create();
        $definition = \App\Models\ReportDefinition::factory()->create(['agency_id' => $agency->id]);

        $current = Report::factory()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id]);
        Report::factory()->create(['agency_id' => $agency->id, 'report_definition_id' => $definition->id]);
        // A report from a different definition must NOT appear.
        Report::factory()->create(['agency_id' => $agency->id]);

        $this->getJson("/api/v1/public/reports/{$current->public_token}/periods")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure([['public_token', 'period_start', 'period_end']]);
    }

    public function test_it_overlays_live_work_logs_onto_the_worklog_block(): void
    {
        $agency = Agency::factory()->create();
        $report = Report::factory()->create([
            'agency_id' => $agency->id,
            'resolved_blocks' => [
                'blocks' => [['id' => 'w1', 'type' => 'worklog_timeline', 'binding' => null, 'props' => [], 'style' => []]],
                'data' => ['w1' => []],
            ],
        ]);
        WorkLog::factory()->create([
            'agency_id' => $agency->id,
            'report_id' => $report->id,
            'description' => 'Actualizaciones aplicadas',
        ]);

        $this->getJson("/api/v1/public/reports/{$report->public_token}")
            ->assertOk()
            ->assertJsonPath('data.w1.0.description', 'Actualizaciones aplicadas');
    }
}
