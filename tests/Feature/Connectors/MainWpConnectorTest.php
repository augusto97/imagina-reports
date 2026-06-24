<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\MainWp\MainWpConnector;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use App\Models\Site;
use Carbon\CarbonImmutable;
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
                    ['name' => 'MainWP Child Reports', 'slug' => 'mainwp-child-reports/mainwp-child-reports.php', 'version' => '2.3', 'active' => 1],
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
        $this->assertSame(4, $set->get('mainwp.plugins_total'));
        $this->assertSame(3, $set->get('mainwp.plugins_active'));
        $this->assertSame(86, $set->get('mainwp.health_score'));
        $this->assertSame(1, $set->get('mainwp.child_reports_active'));

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

    public function test_fetch_derives_ssl_and_domain_expiry_from_the_extensions(): void
    {
        CarbonImmutable::setTestNow('2026-06-24 12:00:00');

        Http::fake([
            'dash.test/wp-json/mainwp/v2/ssl-monitor/info' => Http::response(['success' => 1, 'data' => [
                '7' => ['id' => '7', 'url' => 'https://a.test', 'issuer_o' => "Let's Encrypt", 'valid_from' => '01/06/2026', 'valid_to' => '24/07/2026'],
            ]]),
            'dash.test/wp-json/mainwp/v2/domain-monitor/profiles' => Http::response(['success' => 1, 'data' => [
                '7' => ['id' => '7', 'url' => 'https://a.test', 'registrar' => 'GoDaddy.com, LLC', 'expiry_date' => '24/09/2026'],
            ]]),
            'dash.test/wp-json/mainwp/v2/sites' => Http::response($this->sitesPayload()),
        ]);

        $set = (new MainWpConnector)->fetch(
            $this->source(),
            Period::make('2026-06-01', '2026-06-30'),
            ['mainwp.ssl_days_remaining', 'mainwp.domain_days_remaining', 'mainwp.ssl_domain'],
        );

        $this->assertTrue($set->isOk());
        $this->assertSame(30, $set->get('mainwp.ssl_days_remaining'));
        $this->assertSame(92, $set->get('mainwp.domain_days_remaining'));

        $table = $set->get('mainwp.ssl_domain');
        $this->assertSame(
            ['Concepto' => 'Certificado SSL', 'Proveedor' => "Let's Encrypt", 'Caduca' => '24/07/2026', 'Días restantes' => 30],
            $table[0],
        );
        $this->assertSame('Dominio', $table[1]['Concepto']);
        $this->assertSame('GoDaddy.com, LLC', $table[1]['Proveedor']);

        CarbonImmutable::setTestNow();
    }

    public function test_fetch_parses_vulnerability_checker_count_and_list(): void
    {
        Http::fake([
            'dash.test/wp-json/mainwp/v2/pro-reports/*' => Http::response(['success' => 1, 'data' => ['other_tokens_data' => [
                '[vulnerable.plugins]' => '',
                '[vulnerable.themes]' => 'kadence: 18/06/2026 1:16 am<br/>Some CVE description here<br/>elementor: 04/04/2026 4:16 am<br/>Another CVE description',
                '[vulnerable.checkdate]' => '22/06/2026 10:58 am',
                '[vulnerabilities.count]' => 37,
            ]]]),
            'dash.test/wp-json/mainwp/v2/sites' => Http::response($this->sitesPayload()),
        ]);

        $set = (new MainWpConnector)->fetch(
            $this->source(),
            Period::make('2026-06-01', '2026-06-30'),
            ['mainwp.vulnerabilities_count', 'mainwp.vulnerabilities_list'],
        );

        $this->assertTrue($set->isOk());
        $this->assertSame(37, $set->get('mainwp.vulnerabilities_count'));

        $list = $set->get('mainwp.vulnerabilities_list');
        $this->assertCount(2, $list); // header lines only; CVE descriptions skipped
        $this->assertSame(['Elemento' => 'kadence', 'Detectada' => '18/06/2026 1:16 am'], $list[0]);
        $this->assertSame('elementor', $list[1]['Elemento']);
    }

    public function test_fetch_parses_wordfence_scan_log(): void
    {
        Http::fake([
            'dash.test/wp-json/mainwp/v2/pro-reports/*' => Http::response(['success' => 1, 'data' => ['sections_data' => [[
                ['[wordfence.scan.result]' => '', '[wordfence.scan.date]' => 'junio 21, 2026', '[wordfence.scan.time]' => '4:56 pm', '[wordfence.scan.details]' => 'Exploración completa. Tienes 6 nuevos problemas.'],
                ['[wordfence.scan.result]' => '', '[wordfence.scan.date]' => 'junio 20, 2026', '[wordfence.scan.time]' => '5:56 pm', '[wordfence.scan.details]' => 'Exploración completa. Tienes 9 nuevos problemas.'],
            ]]]]),
            'dash.test/wp-json/mainwp/v2/sites' => Http::response($this->sitesPayload()),
        ]);

        $set = (new MainWpConnector)->fetch(
            $this->source(),
            Period::make('2026-06-01', '2026-06-30'),
            ['mainwp.wordfence_scans_count', 'mainwp.wordfence_scans'],
        );

        $this->assertTrue($set->isOk());
        $this->assertSame(2, $set->get('mainwp.wordfence_scans_count'));

        $scans = $set->get('mainwp.wordfence_scans');
        $this->assertSame(
            ['Fecha' => 'junio 21, 2026 4:56 pm', 'Detalle' => 'Exploración completa. Tienes 6 nuevos problemas.'],
            $scans[0],
        );
    }

    public function test_fetch_reads_maintenance_and_malware_counters(): void
    {
        Http::fake([
            'dash.test/wp-json/mainwp/v2/pro-reports/*/maintenance*' => Http::response(['success' => 1, 'data' => ['sections_data' => [[]], 'other_tokens_data' => ['[maintenance.process.count]' => 5]]]),
            'dash.test/wp-json/mainwp/v2/pro-reports/*/virusdie*' => Http::response(['success' => 1, 'data' => ['sections_data' => [[]], 'other_tokens_data' => ['[virusdie.scan.count]' => 2]]]),
            'dash.test/wp-json/mainwp/v2/sites' => Http::response($this->sitesPayload()),
        ]);

        $set = (new MainWpConnector)->fetch(
            $this->source(),
            Period::make('2026-06-01', '2026-06-30'),
            ['mainwp.maintenance_count', 'mainwp.malware_found'],
        );

        $this->assertTrue($set->isOk());
        $this->assertSame(5, $set->get('mainwp.maintenance_count'));
        $this->assertSame(2, $set->get('mainwp.malware_found')); // Virusdie folded into MainWP
    }

    public function test_fetch_builds_the_security_checklist(): void
    {
        Http::fake([
            'dash.test/wp-json/mainwp/v2/sites/*/security' => Http::response(['data' => [
                'wp_uptodate' => 'Y',
                'sslprotocol' => 'Y',
                'debug_disabled' => 'Y',
                'db_reporting' => 'Y_UNABLE',
                'sec_outdated_plugins' => 'N',
                'sec_inactive_plugins' => 'N',
                'sec_outdated_themes' => 'Y',
            ], 'site' => ['id' => '7']]),
            'dash.test/wp-json/mainwp/v2/sites' => Http::response($this->sitesPayload()),
        ]);

        $set = (new MainWpConnector)->fetch(
            $this->source(),
            Period::make('2026-06-01', '2026-06-30'),
            ['mainwp.security_issues_count', 'mainwp.security_checklist'],
        );

        $this->assertTrue($set->isOk());
        $this->assertSame(2, $set->get('mainwp.security_issues_count')); // two "N" flags

        $checklist = $set->get('mainwp.security_checklist');
        $this->assertContains(['Comprobación' => 'WordPress al día', 'Estado' => '✓ Seguro'], $checklist);
        $this->assertContains(['Comprobación' => 'Sin plugins obsoletos', 'Estado' => '⚠ Revisar'], $checklist);
        $this->assertContains(['Comprobación' => 'Errores de base de datos ocultos', 'Estado' => '—'], $checklist);
    }

    public function test_stale_past_ssl_date_is_hidden_not_shown_as_negative(): void
    {
        CarbonImmutable::setTestNow('2026-06-24 12:00:00');

        Http::fake([
            'dash.test/wp-json/mainwp/v2/ssl-monitor/info' => Http::response(['success' => 1, 'data' => [
                '7' => ['id' => '7', 'url' => 'https://a.test', 'issuer_o' => "Let's Encrypt", 'valid_to' => '16/01/2025'],
            ]]),
            'dash.test/wp-json/mainwp/v2/domain-monitor/profiles' => Http::response(['success' => 1, 'data' => [
                '7' => ['id' => '7', 'url' => 'https://a.test', 'registrar' => 'GoDaddy.com, LLC', 'expiry_date' => '18/06/2027'],
            ]]),
            'dash.test/wp-json/mainwp/v2/sites' => Http::response($this->sitesPayload()),
        ]);

        $set = (new MainWpConnector)->fetch(
            $this->source(),
            Period::make('2026-06-01', '2026-06-30'),
            ['mainwp.ssl_days_remaining', 'mainwp.domain_days_remaining', 'mainwp.ssl_domain'],
        );

        $this->assertTrue($set->isOk());
        // SSL date is in the past (stale scan) → hidden, never shown as a negative count.
        $this->assertNull($set->get('mainwp.ssl_days_remaining'));
        // Domain date is still valid → kept.
        $this->assertGreaterThan(0, $set->get('mainwp.domain_days_remaining'));

        $table = $set->get('mainwp.ssl_domain');
        $this->assertCount(1, $table); // only the domain row
        $this->assertSame('Dominio', $table[0]['Concepto']);

        CarbonImmutable::setTestNow();
    }

    public function test_ssl_domain_no_data_sentinel_is_ignored(): void
    {
        Http::fake([
            'dash.test/wp-json/mainwp/v2/ssl-monitor/info' => Http::response(['success' => 1, 'data' => [
                ['url' => 'https://a.test', 'valid_to' => '31/12/1969'],
            ]]),
            'dash.test/wp-json/mainwp/v2/domain-monitor/profiles' => Http::response(['success' => 1, 'data' => [
                ['url' => 'https://a.test', 'expiry_date' => '31/12/1969'],
            ]]),
            'dash.test/wp-json/mainwp/v2/sites' => Http::response($this->sitesPayload()),
        ]);

        $set = (new MainWpConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), ['mainwp.ssl_domain']);

        $this->assertTrue($set->isOk());
        $this->assertNull($set->get('mainwp.ssl_days_remaining'));
        $this->assertNull($set->get('mainwp.ssl_domain'));
    }
}
