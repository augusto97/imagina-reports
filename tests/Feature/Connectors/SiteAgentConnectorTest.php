<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Period;
use App\Connectors\SiteAgent\SiteAgentConnector;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteAgentConnectorTest extends TestCase
{
    private function source(string $siteUrl = 'https://a.test', ?string $configUrl = null): DataSource
    {
        $source = DataSource::factory()->make([
            'agency_id' => 1,
            'type' => DataSourceType::SiteAgent,
            'config' => $configUrl !== null ? ['url' => $configUrl] : [],
            'credentials' => ['api_key' => 'secret-agent-key'],
        ]);

        $source->setRelation('site', (new Site)->forceFill(['url' => $siteUrl]));

        return $source;
    }

    /**
     * The agent payload shape (mirror of the WordPress plugin).
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'success' => true,
            'generated_at' => '2026-06-25T12:00:00+00:00',
            'agent_version' => '1.0.0',
            'site' => [
                'url' => 'https://a.test',
                'wp_version' => '6.5.2',
                'php_version' => '8.2.18',
                'mysql_version' => '10.6.16-MariaDB',
                'locale' => 'es_ES',
                'https' => true,
                'multisite' => false,
                'active_theme' => 'Astra',
            ],
            'plugins' => ['active' => 12, 'inactive' => 3, 'total' => 15],
            'updates' => ['core' => 0, 'plugins' => 2, 'themes' => 1, 'total' => 3],
            'storage' => ['db_size_mb' => 42.5, 'uploads_size_mb' => 880.2],
            'backups' => [
                'provider' => 'WPvivid',
                'providers' => ['WPvivid'],
                'last_backup_at' => '2026-06-22T03:00:00+00:00',
                'last_backup_age_days' => 3,
                'last_backup_size_mb' => 120.4,
                'total_size_mb' => 540.0,
                'count_total' => 18,
                'count_in_period' => 4,
                'recent' => [
                    ['date' => '2026-06-22 03:00', 'size_mb' => 120.4, 'provider' => 'WPvivid'],
                    ['date' => '2026-06-15 03:00', 'size_mb' => 118.9, 'provider' => 'WPvivid'],
                ],
            ],
        ];
    }

    public function test_catalog_lists_backup_and_site_health_metrics(): void
    {
        $catalog = (new SiteAgentConnector)->metricCatalog($this->source());

        $this->assertTrue($catalog->has('site_agent.backups_count'));
        $this->assertTrue($catalog->has('site_agent.last_backup_days'));
        $this->assertTrue($catalog->has('site_agent.backup_status'));
        $this->assertTrue($catalog->has('site_agent.site_health'));
        $this->assertTrue($catalog->has('site_agent.plugins_active'));
        $this->assertFalse($catalog->has('mainwp.sites'));
    }

    public function test_fetch_maps_the_agent_payload_to_the_metric_bag(): void
    {
        Http::fake(['a.test/wp-json/imagina-reports/v1/metrics*' => Http::response($this->payload())]);

        $set = (new SiteAgentConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isOk());
        $this->assertSame(4, $set->get('site_agent.backups_count'));
        $this->assertSame(18, $set->get('site_agent.backups_total'));
        $this->assertSame(3, $set->get('site_agent.last_backup_days'));
        $this->assertSame(120.4, $set->get('site_agent.last_backup_size_mb'));
        $this->assertSame(12, $set->get('site_agent.plugins_active'));
        $this->assertSame(3, $set->get('site_agent.updates_pending'));
        $this->assertSame(42.5, $set->get('site_agent.db_size_mb'));

        $status = $set->get('site_agent.backup_status');
        $this->assertSame(['Concepto' => 'Proveedor de respaldo', 'Valor' => 'WPvivid'], $status[0]);
        $this->assertSame('3 días', $status[2]['Valor']);

        $recent = $set->get('site_agent.recent_backups');
        $this->assertCount(2, $recent);
        $this->assertSame('22/06/2026 03:00', $recent[0]['Fecha']);
        $this->assertSame('120.4 MB', $recent[0]['Tamaño']);

        $health = $set->get('site_agent.site_health');
        $this->assertContains(['Concepto' => 'WordPress', 'Valor' => '6.5.2'], $health);
        $this->assertContains(['Concepto' => 'HTTPS', 'Valor' => 'Activo'], $health);
    }

    public function test_fetch_sends_the_api_key_header_and_period(): void
    {
        Http::fake(['*' => Http::response($this->payload())]);

        (new SiteAgentConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('X-Imagina-Key', 'secret-agent-key')
                && str_contains($request->url(), 'from=2026-06-01')
                && str_contains($request->url(), 'to=2026-06-30');
        });
    }

    public function test_config_url_overrides_the_site_url(): void
    {
        Http::fake(['*' => Http::response($this->payload())]);

        (new SiteAgentConnector)->fetch(
            $this->source('https://a.test', 'https://www.other.test/'),
            Period::make('2026-06-01', '2026-06-30'),
            [],
        );

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://www.other.test/wp-json/imagina-reports/v1/metrics'));
    }

    public function test_fetch_returns_only_requested_metrics(): void
    {
        Http::fake(['*' => Http::response($this->payload())]);

        $set = (new SiteAgentConnector)->fetch(
            $this->source(),
            Period::make('2026-06-01', '2026-06-30'),
            ['site_agent.backups_count'],
        );

        $this->assertSame(['site_agent.backups_count'], $set->keys());
    }

    public function test_null_last_backup_stays_null_so_the_block_hides(): void
    {
        $payload = $this->payload();
        $payload['backups']['last_backup_age_days'] = null;
        $payload['backups']['last_backup_size_mb'] = null;

        Http::fake(['*' => Http::response($payload)]);

        $set = (new SiteAgentConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertNull($set->get('site_agent.last_backup_days'));
        $this->assertNull($set->get('site_agent.last_backup_size_mb'));
    }

    public function test_a_failed_http_response_yields_a_failed_set(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $set = (new SiteAgentConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isFailed());
        $this->assertNotNull($set->error);
    }

    public function test_test_connection_succeeds_when_agent_responds_ok(): void
    {
        Http::fake(['*' => Http::response($this->payload())]);

        $this->assertTrue((new SiteAgentConnector)->testConnection($this->source())->successful);
    }

    public function test_test_connection_reports_an_invalid_key(): void
    {
        Http::fake(['*' => Http::response(['code' => 'imagina_reports_forbidden'], 403)]);

        $result = (new SiteAgentConnector)->testConnection($this->source());

        $this->assertFalse($result->successful);
        $this->assertStringContainsString('Clave', $result->message);
    }
}
