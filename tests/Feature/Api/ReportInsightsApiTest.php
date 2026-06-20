<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AiClient;
use App\Models\Agency;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class ReportInsightsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_ai_insights_from_the_resolved_metrics(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $fake = new FakeAiClient((string) json_encode(['Las visitas crecieron un 12%.', 'Cero malware detectado este mes.']));
        $this->app->instance(AiClient::class, $fake);

        $report = Report::factory()->create([
            'agency_id' => $agency->id,
            'health_score' => 92,
            'resolved_blocks' => [
                'blocks' => [
                    ['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions'], 'props' => ['label' => 'Visitas'], 'style' => []],
                ],
                'data' => ['k1' => 6000],
            ],
        ]);

        $this->postJson("/api/v1/reports/{$report->id}/insights")
            ->assertOk()
            ->assertJsonPath('insights.0', 'Las visitas crecieron un 12%.')
            ->assertJsonCount(2, 'insights');

        // The AI saw the named metric (label) and the health score, not raw block ids.
        $this->assertStringContainsString('Visitas', (string) $fake->lastPrompt);
        $this->assertStringContainsString('health_score', (string) $fake->lastPrompt);
    }

    public function test_it_cannot_read_another_agencys_report(): void
    {
        $mine = Agency::factory()->create();
        $other = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $mine->id]));

        $report = Report::factory()->create(['agency_id' => $other->id]);

        $this->postJson("/api/v1/reports/{$report->id}/insights")->assertNotFound();
    }
}
