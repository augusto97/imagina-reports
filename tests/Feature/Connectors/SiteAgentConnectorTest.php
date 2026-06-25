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
            'ssl' => [
                'checked' => true,
                'valid' => true,
                'expires_at' => '2026-09-01T00:00:00+00:00',
                'days_until_expiry' => 68,
                'issuer' => "Let's Encrypt",
                'common_name' => 'a.test',
            ],
            'security' => [
                'admins' => 2,
                'users_total' => 540,
                'users_added' => 18,
                'spam_blocked_total' => 9821,
                'spam_blocked_period' => 412,
                'search_engines_blocked' => false,
                'file_editing_disabled' => true,
                'debug_off' => true,
                'https' => true,
            ],
            'performance' => [
                'object_cache' => true,
                'object_cache_type' => 'Redis',
                'page_cache' => 'WP Rocket',
                'cron_overdue' => 0,
                'autoload_mb' => 1.4,
                'revisions' => 320,
                'trashed_posts' => 5,
                'spam_comments' => 88,
                'trash_comments' => 2,
                'expired_transients' => 140,
                'disk_free_mb' => 51200.0,
                'disk_total_mb' => 102400.0,
            ],
            'content' => [
                'posts_published' => 6,
                'pages_published' => 1,
                'comments_received' => 47,
                'comments_approved' => 40,
            ],
            'leads' => [
                'provider' => 'Contact Form 7',
                'count_total' => 530,
                'count_period' => 23,
            ],
            'ecommerce' => [
                'active' => true,
                'out_of_stock' => 7,
                'low_stock' => 12,
                'pending_orders' => 4,
                'processing_orders' => 9,
            ],
            'logins' => [
                'provider' => 'Wordfence',
                'failed_period' => 34,
                'blocked_period' => 5,
                'blocked_total' => 0,
            ],
            'images' => [
                'provider' => 'ShortPixel',
                'optimized' => 1848,
                'saved_mb' => 512.4,
            ],
            'backups' => [
                'provider' => 'WPvivid',
                'providers' => ['WPvivid'],
                'last_backup_at' => '2026-06-22T03:00:00+00:00',
                'last_backup_age_days' => 3,
                'last_backup_size_mb' => 120.4,
                'last_backup_location' => 'Google Drive',
                'total_size_mb' => 540.0,
                'count_total' => 18,
                'count_in_period' => 4,
                'recent' => [
                    ['date' => '2026-06-22 03:00', 'size_mb' => 120.4, 'provider' => 'WPvivid', 'location' => 'Google Drive'],
                    ['date' => '2026-06-15 03:00', 'size_mb' => 118.9, 'provider' => 'WPvivid', 'location' => 'Local'],
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
        $this->assertSame(['Concepto' => 'Destino', 'Valor' => 'Google Drive'], $status[1]);
        $this->assertSame('3 días', $status[3]['Valor']);

        $recent = $set->get('site_agent.recent_backups');
        $this->assertCount(2, $recent);
        $this->assertSame('22/06/2026 03:00', $recent[0]['Fecha']);
        $this->assertSame('120.4 MB', $recent[0]['Tamaño']);
        $this->assertSame('Google Drive', $recent[0]['Destino']);

        $health = $set->get('site_agent.site_health');
        $this->assertContains(['Concepto' => 'WordPress', 'Valor' => '6.5.2'], $health);
        $this->assertContains(['Concepto' => 'HTTPS', 'Valor' => 'Activo'], $health);
    }

    public function test_fetch_maps_security_performance_content_leads_and_ecommerce(): void
    {
        Http::fake(['a.test/wp-json/imagina-reports/v1/metrics*' => Http::response($this->payload())]);

        $set = (new SiteAgentConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isOk());
        // SSL.
        $this->assertSame(68, $set->get('site_agent.ssl_days_remaining'));
        $ssl = $set->get('site_agent.ssl_status');
        $this->assertContains(['Concepto' => 'Estado', 'Valor' => 'Válido ✓'], $ssl);
        $this->assertContains(['Concepto' => 'Emisor', 'Valor' => "Let's Encrypt"], $ssl);
        // Security.
        $this->assertSame(412, $set->get('site_agent.spam_blocked'));
        $this->assertSame(9821, $set->get('site_agent.spam_blocked_total'));
        $this->assertSame(2, $set->get('site_agent.admin_users'));
        $this->assertSame(18, $set->get('site_agent.users_new'));
        $audit = $set->get('site_agent.security_audit');
        $this->assertContains(['Comprobación' => 'Indexable por buscadores', 'Estado' => '✓ Correcto'], $audit);
        // Performance.
        $this->assertSame(0, $set->get('site_agent.cron_overdue'));
        $this->assertSame(320, $set->get('site_agent.revisions'));
        $this->assertEquals(51200, $set->get('site_agent.disk_free_mb'));
        $perf = $set->get('site_agent.performance_status');
        $this->assertContains(['Concepto' => 'Caché de objetos', 'Valor' => 'Redis'], $perf);
        $this->assertContains(['Concepto' => 'Caché de página', 'Valor' => 'WP Rocket'], $perf);
        // Content.
        $this->assertSame(6, $set->get('site_agent.posts_published'));
        $this->assertSame(47, $set->get('site_agent.comments_received'));
        // Leads.
        $this->assertSame(23, $set->get('site_agent.leads'));
        $this->assertSame(530, $set->get('site_agent.leads_total'));
        // E-commerce.
        $this->assertSame(7, $set->get('site_agent.out_of_stock'));
        $this->assertSame(4, $set->get('site_agent.pending_orders'));
        // Logins (Wordfence) + imágenes (ShortPixel).
        $this->assertSame(34, $set->get('site_agent.failed_logins'));
        $this->assertSame(5, $set->get('site_agent.logins_blocked'));
        $this->assertSame(1848, $set->get('site_agent.images_optimized'));
        $this->assertSame(512.4, $set->get('site_agent.images_saved_mb'));
    }

    public function test_leads_and_ecommerce_hide_when_absent(): void
    {
        $payload = $this->payload();
        $payload['leads'] = ['provider' => '', 'count_total' => 0, 'count_period' => 0];
        $payload['ecommerce'] = ['active' => false];
        $payload['ssl'] = ['checked' => false];
        $payload['logins'] = ['provider' => '', 'failed_period' => 0, 'blocked_period' => 0];
        $payload['images'] = ['provider' => '', 'optimized' => 0, 'saved_mb' => 0.0];

        Http::fake(['*' => Http::response($payload)]);

        $set = (new SiteAgentConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertNull($set->get('site_agent.leads'));
        $this->assertNull($set->get('site_agent.leads_total'));
        $this->assertNull($set->get('site_agent.out_of_stock'));
        $this->assertNull($set->get('site_agent.pending_orders'));
        $this->assertNull($set->get('site_agent.ssl_days_remaining'));
        $this->assertNull($set->get('site_agent.ssl_status'));
        $this->assertNull($set->get('site_agent.failed_logins'));
        $this->assertNull($set->get('site_agent.images_optimized'));
    }

    public function test_detects_abandoned_plugins_via_wporg(): void
    {
        $payload = $this->payload();
        $payload['plugins']['list'] = [
            ['slug' => 'old-plugin', 'name' => 'Old Plugin', 'version' => '1.0', 'active' => true],
            ['slug' => 'akismet', 'name' => 'Akismet', 'version' => '5.3', 'active' => true],
            ['slug' => 'acme-pro', 'name' => 'Acme Pro', 'version' => '2.0', 'active' => true],
        ];

        Http::fake([
            'a.test/wp-json/*' => Http::response($payload),
            'api.wordpress.org/plugins/info/1.0/old-plugin.json' => Http::response(['slug' => 'old-plugin', 'name' => 'Old Plugin', 'last_updated' => '2021-01-15 3:00pm GMT']),
            'api.wordpress.org/plugins/info/1.0/akismet.json' => Http::response(['slug' => 'akismet', 'name' => 'Akismet', 'last_updated' => '2026-05-20 3:00pm GMT']),
            // Not in the directory (premium) — must NOT be flagged as abandoned.
            'api.wordpress.org/plugins/info/1.0/acme-pro.json' => Http::response(['error' => 'Plugin not found.']),
        ]);

        $set = (new SiteAgentConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertSame(1, $set->get('site_agent.abandoned_count'));
        $table = $set->get('site_agent.abandoned_plugins');
        $this->assertCount(1, $table);
        $this->assertSame('Old Plugin', $table[0]['Plugin']);
    }

    public function test_abandoned_plugins_skipped_when_not_requested(): void
    {
        Http::preventStrayRequests();
        Http::fake(['a.test/wp-json/*' => Http::response($this->payload())]);

        // Requesting only an unrelated metric must not trigger any wp.org call.
        $set = (new SiteAgentConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), ['site_agent.plugins_total']);

        $this->assertSame(15, $set->get('site_agent.plugins_total'));
        $this->assertNull($set->get('site_agent.abandoned_count'));
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
