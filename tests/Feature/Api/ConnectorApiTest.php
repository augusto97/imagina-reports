<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConnectorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_connectors_with_their_config_schema(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $response = $this->getJson('/api/v1/connectors')->assertOk();

        $keys = array_column($response->json(), 'key');
        $this->assertContains('mainwp', $keys);
        $this->assertContains('ga4', $keys);
        $this->assertContains('gsc', $keys);

        $mainwp = collect($response->json())->firstWhere('key', 'mainwp');
        $this->assertNotNull($mainwp);
        $this->assertArrayHasKey('config_schema', $mainwp);
        $this->assertContains('dashboard_url', array_column($mainwp['config_schema'], 'key'));
    }

    public function test_it_includes_a_setup_guide_for_connectors_that_ship_one(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $ga4 = collect($this->getJson('/api/v1/connectors')->json())->firstWhere('key', 'ga4');

        $this->assertNotNull($ga4['guide']);
        $this->assertNotEmpty($ga4['guide']['intro']);
        $this->assertNotEmpty($ga4['guide']['steps']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/connectors')->assertUnauthorized();
    }
}
