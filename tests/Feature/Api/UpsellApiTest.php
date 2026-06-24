<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DataSourceType;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UpsellApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingFor(Agency $agency): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function snapshot(Agency $agency, DataSource $source, string $start, string $end, array $metrics): void
    {
        MetricSnapshot::factory()->create([
            'agency_id' => $agency->id,
            'data_source_id' => $source->id,
            'period_start' => $start,
            'period_end' => $end,
            'payload' => ['metrics' => $metrics],
            'captured_at' => $start,
        ]);
    }

    public function test_it_surfaces_traffic_growth_opportunity_for_the_latest_report(): void
    {
        $agency = Agency::factory()->create();
        $this->actingFor($agency);

        $client = Client::factory()->create(['agency_id' => $agency->id, 'name' => 'Cliente A']);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id, 'name' => 'Alpha']);

        $ga4 = DataSource::factory()->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::Ga4,
        ]);

        // June (current) well above May (previous) → a traffic-growth signal (config: +40%).
        $this->snapshot($agency, $ga4, '2026-06-15 00:00:00', '2026-06-15 23:59:59', ['ga4.sessions' => 1500]);
        $this->snapshot($agency, $ga4, '2026-05-15 00:00:00', '2026-05-15 23:59:59', ['ga4.sessions' => 1000]);

        $definition = ReportDefinition::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id]);
        Report::factory()->create([
            'agency_id' => $agency->id,
            'report_definition_id' => $definition->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ]);

        $response = $this->getJson('/api/v1/upsell')->assertOk();

        $response->assertJsonPath('summary.sites_count', 1);
        $response->assertJsonPath('summary.sites_with_opportunities', 1);
        $response->assertJsonPath('sites.0.site_name', 'Alpha');
        $response->assertJsonPath('sites.0.client_name', 'Cliente A');
        // The traffic-growth opportunity is present among this site's signals.
        $this->assertContains('traffic_growth', array_column($response->json('sites.0.opportunities'), 'type'));
    }

    public function test_it_is_scoped_to_the_authenticated_agency(): void
    {
        $other = Agency::factory()->create();
        $otherClient = Client::factory()->create(['agency_id' => $other->id]);
        $otherSite = Site::factory()->create(['agency_id' => $other->id, 'client_id' => $otherClient->id]);
        $otherDef = ReportDefinition::factory()->create(['agency_id' => $other->id, 'site_id' => $otherSite->id]);
        Report::factory()->create([
            'agency_id' => $other->id,
            'report_definition_id' => $otherDef->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ]);

        $agency = Agency::factory()->create();
        $this->actingFor($agency);

        $this->getJson('/api/v1/upsell')
            ->assertOk()
            ->assertExactJson([
                'summary' => ['sites_count' => 0, 'sites_with_opportunities' => 0, 'opportunities_count' => 0],
                'sites' => [],
            ]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/upsell')->assertUnauthorized();
    }
}
