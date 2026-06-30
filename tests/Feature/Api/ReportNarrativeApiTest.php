<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AiClient;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\Site;
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

    private function reportWithAdvisory(Agency $agency): Report
    {
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id]);

        return Report::factory()->create([
            'agency_id' => $agency->id,
            'report_definition_id' => $definition->id,
            'health_score' => 88,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30 23:59:59',
            'resolved_blocks' => [
                'blocks' => [
                    ['id' => 'adv', 'type' => 'advisory', 'binding' => null, 'props' => ['title' => 'Diagnóstico'], 'style' => []],
                ],
                'data' => ['adv' => 'Diagnóstico original.'],
            ],
        ]);
    }

    public function test_it_saves_an_edited_advisory_and_injects_it_into_the_block(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $report = $this->reportWithAdvisory($agency);

        $this->putJson("/api/v1/reports/{$report->id}/advisory", ['text' => '  Diagnóstico editado.  '])
            ->assertOk()
            ->assertJsonPath('advisory', 'Diagnóstico editado.');

        $this->assertSame('Diagnóstico editado.', $report->fresh()?->resolved_blocks['data']['adv'] ?? null);
    }

    public function test_it_regenerates_the_advisory_with_the_ai(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $this->app->instance(AiClient::class, new FakeAiClient('Diagnóstico regenerado por IA.'));
        $report = $this->reportWithAdvisory($agency);

        $this->postJson("/api/v1/reports/{$report->id}/advisory/regenerate")
            ->assertOk()
            ->assertJsonPath('advisory', 'Diagnóstico regenerado por IA.');

        $this->assertSame('Diagnóstico regenerado por IA.', $report->fresh()?->resolved_blocks['data']['adv'] ?? null);
    }

    public function test_regenerate_advisory_422_when_the_report_has_no_advisory_block(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $report = $this->reportWithSummary($agency); // has a summary block, no advisory block

        $this->postJson("/api/v1/reports/{$report->id}/advisory/regenerate")->assertStatus(422);
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
