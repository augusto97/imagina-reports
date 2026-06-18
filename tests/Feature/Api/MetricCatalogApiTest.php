<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetricCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_combined_catalog_of_a_sites_sources(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);

        DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id, 'type' => DataSourceType::MainWp]);
        DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id, 'type' => DataSourceType::Ga4]);

        $response = $this->getJson("/api/v1/sites/{$site->id}/metric-catalog")->assertOk();

        $keys = array_column($response->json(), 'key');
        $this->assertContains('mainwp.updates_available', $keys);
        $this->assertContains('ga4.sessions', $keys);

        $sessions = collect($response->json())->firstWhere('key', 'ga4.sessions');
        $this->assertSame('ga4', $sessions['source']);
        $this->assertSame('sessions', $sessions['metric']);
    }
}
