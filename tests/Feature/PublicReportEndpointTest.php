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
