<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrendsApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingFor(Agency $agency): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));
    }

    private function site(Agency $agency, string $siteName, string $clientName): Site
    {
        $client = Client::factory()->create(['agency_id' => $agency->id, 'name' => $clientName]);

        return Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id, 'name' => $siteName]);
    }

    private function report(Agency $agency, ReportDefinition $definition, string $periodEnd, int $health): void
    {
        Report::factory()->create([
            'agency_id' => $agency->id,
            'report_definition_id' => $definition->id,
            'period_start' => $periodEnd, // exact dates don't matter for the trend, only ordering
            'period_end' => $periodEnd,
            'health_score' => $health,
        ]);
    }

    public function test_it_returns_per_site_health_trends_worst_first(): void
    {
        $agency = Agency::factory()->create();
        $this->actingFor($agency);

        $siteA = $this->site($agency, 'Alpha', 'Cliente A');
        $defA = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'site_id' => $siteA->id]);
        $this->report($agency, $defA, '2026-05-31', 60);
        $this->report($agency, $defA, '2026-06-30', 90);

        $siteB = $this->site($agency, 'Bravo', 'Cliente B');
        $defB = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'site_id' => $siteB->id]);
        $this->report($agency, $defB, '2026-06-30', 40);

        $response = $this->getJson('/api/v1/trends')->assertOk();

        $response->assertJsonPath('summary.sites_count', 2);
        $response->assertJsonPath('summary.reports_count', 3);
        $response->assertJsonPath('summary.average_health_score', 65); // (90 + 40) / 2

        // Worst health first: Bravo (40) before Alpha (90).
        $response->assertJsonPath('sites.0.site_name', 'Bravo');
        $response->assertJsonPath('sites.0.client_name', 'Cliente B');
        $response->assertJsonPath('sites.0.latest_health_score', 40);
        $response->assertJsonPath('sites.0.reports_count', 1);

        $response->assertJsonPath('sites.1.site_name', 'Alpha');
        $response->assertJsonPath('sites.1.latest_health_score', 90);
        $response->assertJsonPath('sites.1.reports_count', 2);
        // Chronological series.
        $response->assertJsonPath('sites.1.health_series.0.health_score', 60);
        $response->assertJsonPath('sites.1.health_series.1.health_score', 90);
    }

    public function test_it_is_scoped_to_the_authenticated_agency(): void
    {
        $other = Agency::factory()->create();
        $otherSite = $this->site($other, 'Foreign', 'Cliente X');
        $otherDef = ReportDefinition::factory()->create(['agency_id' => $other->id, 'site_id' => $otherSite->id]);
        $this->report($other, $otherDef, '2026-06-30', 10);

        $agency = Agency::factory()->create();
        $this->actingFor($agency);

        $this->getJson('/api/v1/trends')
            ->assertOk()
            ->assertJsonPath('summary.sites_count', 0)
            ->assertJsonPath('summary.reports_count', 0)
            ->assertJsonPath('summary.average_health_score', null)
            ->assertExactJson(['summary' => ['sites_count' => 0, 'reports_count' => 0, 'average_health_score' => null], 'sites' => []]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/trends')->assertUnauthorized();
    }
}
