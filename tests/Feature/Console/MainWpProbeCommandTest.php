<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MainWpProbeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dumps_routes_and_site_fields(): void
    {
        Storage::fake('local');

        $agency = Agency::factory()->create();
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id, 'url' => 'https://acme.test']);

        $source = DataSource::factory()->create([
            'agency_id' => $agency->id,
            'site_id' => $site->id,
            'type' => DataSourceType::MainWp,
            'config' => ['dashboard_url' => 'https://dash.test'],
            'credentials' => ['token' => 'secret'],
        ]);

        Http::fake([
            'dash.test/wp-json/mainwp/v2' => Http::response([
                'routes' => [
                    '/mainwp/v2/sites' => [],
                    '/mainwp/v2/ssl-monitor/summary' => [],
                ],
            ]),
            'dash.test/wp-json/mainwp/v2/sites' => Http::response([
                ['url' => 'https://acme.test', 'health_score' => 90, 'sslmonitor' => ['days_left' => 42]],
            ]),
        ]);

        $this->artisan('mainwp:probe', ['source' => (string) $source->id])
            ->assertSuccessful();

        Storage::disk('local')->assertExists("mainwp-probe/source-{$source->id}.json");

        $dump = json_decode((string) Storage::disk('local')->get("mainwp-probe/source-{$source->id}.json"), true);
        $this->assertIsArray($dump);
        $this->assertContains('/mainwp/v2/ssl-monitor/summary', $dump['routes']);
        $this->assertSame(42, data_get($dump, 'sample_site.sslmonitor.days_left'));
    }
}
