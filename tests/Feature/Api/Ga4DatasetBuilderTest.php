<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Connectors\Google\GoogleTokenProvider;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Connectors\FakeGoogleTokenProvider;
use Tests\TestCase;

class Ga4DatasetBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private function ga4Source(): DataSource
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $this->site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);

        return DataSource::factory()->create([
            'agency_id' => $agency->id,
            'site_id' => $this->site->id,
            'type' => DataSourceType::Ga4,
            'config' => ['property_id' => '123456789'],
            'credentials' => ['type' => 'service_account', 'client_email' => 'sa@example.iam'],
        ]);
    }

    public function test_storing_a_custom_dataset_persists_it_and_surfaces_it_in_the_catalog(): void
    {
        $ga4 = $this->ga4Source();

        $payload = [
            'key' => 'campaigns',
            'label' => 'Campañas',
            'dimensions' => [['key' => 'campaign', 'label' => 'Campaña', 'api' => 'sessionCampaignName']],
            'measures' => [['key' => 'sessions', 'label' => 'Sesiones', 'api' => 'sessions', 'unit' => 'count', 'cast' => 'int', 'scale' => 1]],
            'limit' => 100,
        ];

        $this->postJson("/api/v1/data-sources/{$ga4->id}/ga4/datasets", $payload)
            ->assertOk()
            ->assertJsonPath('custom_datasets.0.key', 'campaigns');

        $ga4->refresh();
        $this->assertSame('campaigns', $ga4->config['custom_datasets'][0]['key']);

        // The custom dataset now appears in the site's metric catalog like a factory one.
        $catalog = $this->getJson("/api/v1/sites/{$this->site->id}/metric-catalog")->json();
        $this->assertContains('ga4.custom.campaigns', array_column($catalog, 'key'));

        // And it can be removed.
        $this->deleteJson("/api/v1/data-sources/{$ga4->id}/ga4/datasets/campaigns")
            ->assertOk()
            ->assertExactJson(['custom_datasets' => []]);
    }

    public function test_storing_rejects_an_invalid_spec(): void
    {
        $ga4 = $this->ga4Source();

        // No measures → 422.
        $this->postJson("/api/v1/data-sources/{$ga4->id}/ga4/datasets", [
            'key' => 'bad',
            'label' => 'Bad',
            'dimensions' => [['key' => 'x', 'api' => 'sessionSource']],
            'measures' => [],
        ])->assertStatus(422);
    }

    public function test_metadata_endpoint_returns_the_normalized_dictionary(): void
    {
        $this->app->instance(GoogleTokenProvider::class, new FakeGoogleTokenProvider);
        Http::fake(['analyticsdata.googleapis.com/*' => Http::response([
            'dimensions' => [['apiName' => 'sessionSource', 'uiName' => 'Session source', 'category' => 'Traffic']],
            'metrics' => [['apiName' => 'sessions', 'uiName' => 'Sessions', 'type' => 'TYPE_INTEGER']],
        ])]);

        $ga4 = $this->ga4Source();

        $this->getJson("/api/v1/data-sources/{$ga4->id}/ga4/metadata")
            ->assertOk()
            ->assertJsonPath('dimensions.0.api', 'sessionSource')
            ->assertJsonPath('metrics.0.api', 'sessions');
    }

    public function test_test_endpoint_orders_top_n_by_the_chosen_measure_for_the_given_period(): void
    {
        $this->app->instance(GoogleTokenProvider::class, new FakeGoogleTokenProvider);
        Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['rows' => []])]);

        $ga4 = $this->ga4Source();

        $this->postJson("/api/v1/data-sources/{$ga4->id}/ga4/datasets/test", [
            'key' => 'preview',
            'label' => 'Preview',
            'dimensions' => [['key' => 'campaign', 'api' => 'sessionCampaignName']],
            'measures' => [
                ['key' => 'sessions', 'api' => 'sessions'],
                ['key' => 'revenue', 'api' => 'totalRevenue'],
            ],
            'order_by' => 'revenue',
            'from' => '2026-05-01',
            'to' => '2026-05-31',
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return str_contains($request->url(), 'analyticsdata.googleapis.com')
                && ($body['orderBys'][0]['metric']['metricName'] ?? null) === 'totalRevenue'
                && ($body['dateRanges'][0]['startDate'] ?? null) === '2026-05-01'
                && ($body['dateRanges'][0]['endDate'] ?? null) === '2026-05-31';
        });
    }

    public function test_a_non_ga4_source_404s(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $client = Client::factory()->create(['agency_id' => $agency->id]);
        $site = Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
        $mainwp = DataSource::factory()->create(['agency_id' => $agency->id, 'site_id' => $site->id, 'type' => DataSourceType::MainWp]);

        $this->getJson("/api/v1/data-sources/{$mainwp->id}/ga4/metadata")->assertNotFound();
    }
}
