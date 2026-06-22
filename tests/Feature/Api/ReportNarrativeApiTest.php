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

class ReportNarrativeApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $data
     */
    private function reportWithSummary(Agency $agency, array $data = ['k1' => 6000]): Report
    {
        return Report::factory()->create([
            'agency_id' => $agency->id,
            'health_score' => 92,
            'executive_summary' => 'Resumen original.',
            'resolved_blocks' => [
                'blocks' => [
                    ['id' => 'summary', 'type' => 'narrative', 'binding' => null, 'props' => ['variant' => 'executive_summary'], 'style' => []],
                    ['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions'], 'props' => ['label' => 'Visitas'], 'style' => []],
                ],
                'data' => $data,
            ],
        ]);
    }

    public function test_it_saves_an_edited_summary_and_injects_it_into_the_block(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $report = $this->reportWithSummary($agency);

        $this->putJson("/api/v1/reports/{$report->id}/narrative", ['text' => '  Texto editado por el equipo.  '])
            ->assertOk()
            ->assertJsonPath('executive_summary', 'Texto editado por el equipo.');

        $fresh = $report->fresh();
        $this->assertSame('Texto editado por el equipo.', $fresh?->executive_summary);
        // Injected into the executive-summary block so the portal/PDF reflect the edit.
        $this->assertSame('Texto editado por el equipo.', $fresh?->resolved_blocks['data']['summary'] ?? null);
    }

    public function test_it_requires_text(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $report = $this->reportWithSummary($agency);

        $this->putJson("/api/v1/reports/{$report->id}/narrative", ['text' => ''])
            ->assertStatus(422);
    }

    public function test_it_regenerates_the_summary_with_the_ai(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $this->app->instance(AiClient::class, new FakeAiClient('Resumen regenerado por IA.'));
        $report = $this->reportWithSummary($agency);

        $this->postJson("/api/v1/reports/{$report->id}/narrative/regenerate")
            ->assertOk()
            ->assertJsonPath('executive_summary', 'Resumen regenerado por IA.');

        $fresh = $report->fresh();
        $this->assertSame('Resumen regenerado por IA.', $fresh?->resolved_blocks['data']['summary'] ?? null);
    }

    public function test_regenerate_is_a_noop_when_there_are_no_facts(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        // No resolved data → nothing to summarize → AI is not called, summary unchanged.
        $report = $this->reportWithSummary($agency, data: []);

        $this->postJson("/api/v1/reports/{$report->id}/narrative/regenerate")
            ->assertOk()
            ->assertJsonPath('executive_summary', 'Resumen original.');
    }

    public function test_it_cannot_edit_another_agencys_report(): void
    {
        $mine = Agency::factory()->create();
        $other = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $mine->id]));
        $report = $this->reportWithSummary($other);

        $this->putJson("/api/v1/reports/{$report->id}/narrative", ['text' => 'Intruso'])->assertNotFound();
        $this->postJson("/api/v1/reports/{$report->id}/narrative/regenerate")->assertNotFound();
    }
}
