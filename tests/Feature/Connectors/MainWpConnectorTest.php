<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\MainWp\MainWpConnector;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MainWpConnectorTest extends TestCase
{
    /**
     * A MainWP data source already scoped to the site at https://a.test.
     */
    private function source(string $siteUrl = 'https://a.test'): DataSource
    {
        $source = DataSource::factory()->make([
            'agency_id' => 1,
            'type' => DataSourceType::MainWp,
            'config' => ['dashboard_url' => 'https://dash.test'],
            'credentials' => ['token' => 'secret-token'],
        ]);

        $source->setRelation('site', (new Site)->forceFill(['url' => $siteUrl]));

        return $source;
    }

    /**
     * Mirrors the real `/wp-json/mainwp/v2/sites` shape: upgrade/inventory fields are
     * JSON-ENCODED STRINGS, plugin/theme upgrades are objects keyed by slug.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    private function sitesPayload(): array
    {
        return ['data' => [
            [
                'name' => 'Site A',
                'url' => 'https://a.test',
                'health_score' => 86,
                'plugins' => json_encode([
                    ['name' => 'Yoast', 'slug' => 'wordpress-seo', 'version' => '21.0', 'active' => '1'],
                    ['name' => 'Akismet', 'slug' => 'akismet', 'version' => '5.0', 'active' => '0'],
                    ['name' => 'WP Rocket', 'slug' => 'wp-rocket', 'version' => '3.1', 'active' => true],
                ]),
                'plugin_upgrades' => json_encode([
                    'wordpress-seo/wp-seo.php' => ['Name' => 'Yoast SEO', 'Version' => '21.0', 'update' => ['new_version' => '22.1']],
                    'wp-rocket/wp-rocket.php' => ['Name' => 'WP Rocket', 'Version' => '3.1', 'update' => ['new_version' => '3.15']],
                ]),
                'theme_upgrades' => json_encode([
                    'astra' => ['Name' => 'Astra', 'Version' => '4.0', 'update' => ['new_version' => '4.6']],
                ]),
                'wp_upgrades' => json_encode(['current' => '6.4.2', 'new' => '6.5']),
            ],
            [
                'name' => 'Site B',
                'url' => 'https://b.test',
                'health_score' => 90,
                'plugins' => json_encode([]),
                'plugin_upgrades' => json_encode([]),
                'theme_upgrades' => '[]',
                'wp_upgrades' => '[]',
            ],
        ]];
    }

    public function test_catalog_lists_per_site_mainwp_metrics(): void
    {
        $catalog = (new MainWpConnector)->metricCatalog($this->source());

        $this->assertTrue($catalog->has('mainwp.updates_available'));
        $this->assertTrue($catalog->has('mainwp.pending_updates'));
        $this->assertTrue($catalog->has('mainwp.health_score'));
        $this->assertFalse($catalog->has('mainwp.sites')); // no longer agency-wide
        $this->assertFalse($catalog->has('ga4.sessions'));
    }

    public function test_fetch_scopes_to_the_matching_site_and_decodes_upgrades(): void
    {
        Http::fake(['*' => Http::response($this->sitesPayload())]);

        $set = (new MainWpConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isOk());
        $this->assertSame(2, $set->get('mainwp.plugin_updates'));
        $this->assertSame(1, $set->get('mainwp.theme_updates'));
        $this->assertSame(1, $set->get('mainwp.core_updates'));
        $this->assertSame(4, $set->get('mainwp.updates_available'));
        $this->assertSame(3, $set->get('mainwp.plugins_total'));
        $this->assertSame(2, $set->get('mainwp.plugins_active'));
        $this->assertSame(86, $set->get('mainwp.health_score'));

        $pending = $set->get('mainwp.pending_updates');
        $this->assertCount(4, $pending); // 2 plugins + 1 theme + core
        $this->assertSame(
            ['Tipo' => 'Plugin', 'Elemento' => 'Yoast SEO', 'Actual' => '21.0', 'Nueva' => '22.1'],
            $pending[0],
        );
        $this->assertSame('WordPress', $pending[3]['Tipo']);
        $this->assertSame('6.5', $pending[3]['Nueva']);
    }

    public function test_url_matching_ignores_scheme_www_and_trailing_slash(): void
    {
        Http::fake(['*' => Http::response($this->sitesPayload())]);

        $set = (new MainWpConnector)->fetch($this->source('http://www.a.test/'), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isOk());
        $this->assertSame(86, $set->get('mainwp.health_score'));
    }

    public function test_fetch_builds_work_log_from_pro_reports_history(): void
    {
        Http::fake([
            '*/pro-reports/*/plugins*' => Http::response(['success' => 1, 'data' => ['sections_data' => [[
                [
                    '[plugin.name]' => 'JetFormBuilder',
                    '[plugin.updated.utime]' => '2026-06-22 15:32:56',
                    '[plugin.updated.date]' => 'junio 22, 2026',
                    '[plugin.old.version]' => '3.6.2.1',
                    '[plugin.current.version]' => '3.6.2.2',
                ],
                [
                    '[plugin.name]' => 'WooCommerce',
                    '[plugin.updated.utime]' => '2026-06-01 16:22:29',
                    '[plugin.old.version]' => '10.7.0',
                    '[plugin.current.version]' => '10.8.1',
                ],
                [   // outside the period — must be filtered out
                    '[plugin.name]' => 'Old Plugin',
                    '[plugin.updated.utime]' => '2026-05-05 10:00:00',
                    '[plugin.old.version]' => '1.0',
                    '[plugin.current.version]' => '1.1',
                ],
            ]], 'other_tokens_data' => ['[plugin.updated.count]' => 3]]]),
            '*/pro-reports/*/themes*' => Http::response(['success' => 1, 'data' => ['sections_data' => [[
                [
                    '[theme.name]' => 'Astra',
                    '[theme.updated.utime]' => '2026-06-10 09:00:00',
                    '[theme.old.version]' => '4.0',
                    '[theme.current.version]' => '4.6',
                ],
            ]]]]),
            '*/pro-reports/*/wordpress*' => Http::response(['success' => 1, 'data' => []]),
            '*' => Http::response($this->sitesPayload()),
        ]);

        $set = (new MainWpConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isOk());
        // 2 plugins in-period + 1 theme; the May plugin is excluded.
        $this->assertSame(3, $set->get('mainwp.updates_applied'));

        $log = $set->get('mainwp.work_log');
        $this->assertCount(3, $log);
        // Sorted most-recent first → JetFormBuilder (jun 22) leads.
        $this->assertSame(
            ['Fecha' => '22/06/2026', 'Tipo' => 'Plugin', 'Elemento' => 'JetFormBuilder', 'Versión' => '3.6.2.1 → 3.6.2.2'],
            $log[0],
        );
        $this->assertSame('Tema', $log[1]['Tipo']);
        $this->assertSame('Astra', $log[1]['Elemento']);
        $this->assertSame('WooCommerce', $log[2]['Elemento']);
        $this->assertNotContains('Old Plugin', array_column($log, 'Elemento'));
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

    public function test_fetch_fails_when_no_managed_site_matches(): void
    {
        Http::fake(['*' => Http::response($this->sitesPayload())]);

        $set = (new MainWpConnector)->fetch($this->source('https://unknown.test'), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isFailed());
        $this->assertNotNull($set->error);
    }

    public function test_a_failed_http_response_yields_a_failed_set(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $set = (new MainWpConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isFailed());
        $this->assertNotNull($set->error);
    }

    public function test_test_connection_succeeds_when_site_is_found(): void
    {
        Http::fake(['*' => Http::response($this->sitesPayload())]);

        $this->assertTrue((new MainWpConnector)->testConnection($this->source())->successful);
    }

    public function test_test_connection_fails_when_site_not_managed(): void
    {
        Http::fake(['*' => Http::response($this->sitesPayload())]);

        $this->assertFalse((new MainWpConnector)->testConnection($this->source('https://unknown.test'))->successful);
    }

    public function test_test_connection_fails_on_error_status(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $this->assertFalse((new MainWpConnector)->testConnection($this->source())->successful);
    }
}
