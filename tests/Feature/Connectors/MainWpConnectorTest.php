<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\MainWp\MainWpConnector;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MainWpConnectorTest extends TestCase
{
    private function source(): DataSource
    {
        return DataSource::factory()->make([
            'agency_id' => 1,
            'type' => DataSourceType::MainWp,
            'config' => ['dashboard_url' => 'https://dash.test'],
            'credentials' => ['token' => 'secret-token'],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sitesPayload(): array
    {
        return [
            [
                'name' => 'Site A',
                'url' => 'https://a.test',
                'update_counts' => ['plugins' => 3, 'themes' => 1, 'wp' => 0],
                'abandoned_plugins' => 1,
                'ssl' => ['expires_at' => CarbonImmutable::now()->addDays(10)->toIso8601String()],
            ],
            [
                'name' => 'Site B',
                'url' => 'https://b.test',
                'update_counts' => ['plugins' => 2, 'themes' => 0, 'wp' => 1],
                'abandoned_plugins' => 0,
                'ssl' => ['expires_at' => CarbonImmutable::now()->addDays(200)->toIso8601String()],
            ],
        ];
    }

    public function test_catalog_lists_mainwp_metrics(): void
    {
        $catalog = (new MainWpConnector)->metricCatalog($this->source());

        $this->assertTrue($catalog->has('mainwp.updates_available'));
        $this->assertTrue($catalog->has('mainwp.sites'));
        $this->assertFalse($catalog->has('ga4.sessions'));
    }

    public function test_fetch_aggregates_sites_at_the_source(): void
    {
        Http::fake(['*' => Http::response($this->sitesPayload())]);

        $set = (new MainWpConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isOk());
        $this->assertSame(2, $set->get('mainwp.sites_total'));
        $this->assertSame(5, $set->get('mainwp.plugin_updates'));
        $this->assertSame(1, $set->get('mainwp.theme_updates'));
        $this->assertSame(1, $set->get('mainwp.core_updates'));
        $this->assertSame(7, $set->get('mainwp.updates_available'));
        $this->assertSame(1, $set->get('mainwp.abandoned_plugins'));
        $this->assertSame(1, $set->get('mainwp.ssl_expiring'));
        $this->assertSame(2, $set->get('mainwp.sites_with_updates'));
        $this->assertCount(2, $set->get('mainwp.sites'));
        // Only Site A's cert (10 days out) is within the 30-day warning window.
        $this->assertCount(1, $set->get('mainwp.ssl_expiring_sites'));
        $this->assertSame('Site A', $set->get('mainwp.ssl_expiring_sites')[0]['label']);
    }

    public function test_fetch_returns_only_requested_metrics(): void
    {
        Http::fake(['*' => Http::response($this->sitesPayload())]);

        $set = (new MainWpConnector)->fetch(
            $this->source(),
            Period::make('2026-06-01', '2026-06-30'),
            ['mainwp.updates_available'],
        );

        $this->assertSame(['mainwp.updates_available'], $set->keys());
    }

    public function test_a_failed_http_response_yields_a_failed_set(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $set = (new MainWpConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isFailed());
        $this->assertNotNull($set->error);
    }

    public function test_test_connection_succeeds_on_2xx(): void
    {
        Http::fake(['*' => Http::response([])]);

        $this->assertTrue((new MainWpConnector)->testConnection($this->source())->successful);
    }

    public function test_test_connection_fails_on_error_status(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $this->assertFalse((new MainWpConnector)->testConnection($this->source())->successful);
    }
}
