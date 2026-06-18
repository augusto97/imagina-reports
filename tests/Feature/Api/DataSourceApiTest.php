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
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DataSourceApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    private function site(): Site
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);

        return Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);
    }

    public function test_store_configures_a_data_source_without_leaking_credentials(): void
    {
        $site = $this->site();

        $this->postJson("/api/v1/sites/{$site->id}/data-sources", [
            'type' => 'mainwp',
            'config' => ['dashboard_url' => 'https://dash.test'],
            'credentials' => ['token' => 'super-secret'],
        ])
            ->assertCreated()
            ->assertJsonPath('type', 'mainwp')
            ->assertJsonMissingPath('credentials');

        $this->assertDatabaseHas('ir_data_sources', [
            'site_id' => $site->id,
            'agency_id' => $this->agency->id,
        ]);
    }

    public function test_test_connection_runs_the_connector(): void
    {
        Http::fake(['*' => Http::response([])]);
        $site = $this->site();
        $source = DataSource::factory()->create([
            'agency_id' => $this->agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::MainWp,
            'config' => ['dashboard_url' => 'https://dash.test'],
            'credentials' => ['token' => 'x'],
        ]);

        $this->postJson("/api/v1/data-sources/{$source->id}/test")
            ->assertOk()
            ->assertJsonPath('successful', true);
    }
}
