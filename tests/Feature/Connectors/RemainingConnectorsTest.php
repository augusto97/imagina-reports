<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\BetterUptime\BetterUptimeConnector;
use App\Connectors\Cloudflare\CloudflareConnector;
use App\Connectors\CrowdSec\CrowdSecConnector;
use App\Connectors\Period;
use App\Connectors\Virusdie\VirusdieConnector;
use App\Connectors\WooCommerce\WooCommerceConnector;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemainingConnectorsTest extends TestCase
{
    private function source(DataSourceType $type, array $config, array $credentials): DataSource
    {
        return DataSource::factory()->make([
            'agency_id' => 1,
            'type' => $type,
            'config' => $config,
            'credentials' => $credentials,
        ]);
    }

    private function period(): Period
    {
        return Period::make('2026-06-01', '2026-06-30');
    }

    public function test_woocommerce_aggregates_sales_and_top_products(): void
    {
        Http::fake([
            '*/reports/sales*' => Http::response([[
                'total_sales' => '1500.50',
                'net_sales' => '1320.00',
                'total_orders' => 12,
                'total_items' => 30,
                'total_tax' => '120.00',
                'total_customers' => 8,
                'totals' => [
                    '2026-06-01' => ['sales' => '500.50', 'orders' => '4'],
                    '2026-06-02' => ['sales' => '1000.00', 'orders' => '8'],
                ],
            ]]),
            '*/reports/top_sellers*' => Http::response([['name' => 'Camiseta', 'quantity' => 5]]),
        ]);

        $set = (new WooCommerceConnector)->fetch(
            $this->source(DataSourceType::WooCommerce, ['store_url' => 'https://shop.test'], ['consumer_key' => 'ck', 'consumer_secret' => 'cs']),
            $this->period(),
            [],
        );

        $this->assertTrue($set->isOk());
        $this->assertSame(1500.5, $set->get('woocommerce.revenue'));
        $this->assertSame(1320.0, $set->get('woocommerce.net_revenue'));
        $this->assertSame(12, $set->get('woocommerce.orders'));
        $this->assertSame(30, $set->get('woocommerce.items_sold'));
        $this->assertSame(120.0, $set->get('woocommerce.tax'));
        $this->assertSame(8, $set->get('woocommerce.new_customers'));
        $this->assertSame(
            [['date' => '2026-06-01', 'value' => 500.5], ['date' => '2026-06-02', 'value' => 1000.0]],
            $set->get('woocommerce.revenue_by_date'),
        );
        $this->assertSame([['name' => 'Camiseta', 'quantity' => 5]], $set->get('woocommerce.top_products'));
    }

    public function test_cloudflare_sums_graphql_groups(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['viewer' => ['zones' => [[
                'httpRequests1dGroups' => [
                    [
                        'dimensions' => ['date' => '2026-06-01'],
                        'uniq' => ['uniques' => 120],
                        'sum' => [
                            'requests' => 1000, 'cachedRequests' => 800, 'threats' => 30, 'bytes' => 5000,
                            'countryMap' => [['clientCountryName' => 'ES', 'requests' => 600, 'threats' => 20]],
                        ],
                    ],
                    [
                        'dimensions' => ['date' => '2026-06-02'],
                        'uniq' => ['uniques' => 80],
                        'sum' => [
                            'requests' => 500, 'cachedRequests' => 400, 'threats' => 10, 'bytes' => 2500,
                            'countryMap' => [['clientCountryName' => 'ES', 'requests' => 300, 'threats' => 5]],
                        ],
                    ],
                ],
            ]]]],
        ])]);

        $set = (new CloudflareConnector)->fetch(
            $this->source(DataSourceType::Cloudflare, ['zone_id' => 'z1'], ['api_token' => 't']),
            $this->period(),
            [],
        );

        $this->assertSame(1500, $set->get('cloudflare.requests'));
        $this->assertSame(40, $set->get('cloudflare.threats_blocked'));
        $this->assertSame(200, $set->get('cloudflare.unique_visitors'));
        $this->assertSame(80.0, $set->get('cloudflare.cache_ratio')); // 1200/1500 → 80 %
        $this->assertSame(7500, $set->get('cloudflare.bandwidth'));
        $this->assertSame(
            [['date' => '2026-06-01', 'value' => 30], ['date' => '2026-06-02', 'value' => 10]],
            $set->get('cloudflare.threats_by_date'),
        );
        $this->assertSame([['label' => 'ES', 'value' => 25]], $set->get('cloudflare.threats_by_country'));
    }

    public function test_crowdsec_counts_alerts_and_decisions(): void
    {
        Http::fake(['*' => Http::response([
            ['scenario' => 'ssh-bf', 'events_count' => 6, 'source' => ['value' => '1.1.1.1', 'cn' => 'CN'], 'decisions' => [['id' => 1]]],
            ['scenario' => 'ssh-bf', 'events_count' => 4, 'source' => ['value' => '2.2.2.2', 'cn' => 'CN'], 'decisions' => [['id' => 2], ['id' => 3]]],
        ])]);

        $set = (new CrowdSecConnector)->fetch(
            $this->source(DataSourceType::CrowdSec, ['api_url' => 'https://crowdsec.test'], ['token' => 't']),
            $this->period(),
            [],
        );

        $this->assertSame(2, $set->get('crowdsec.alerts'));
        $this->assertSame(3, $set->get('crowdsec.attacks_blocked'));
        $this->assertSame(10, $set->get('crowdsec.events'));
        $this->assertSame(2, $set->get('crowdsec.unique_ips'));
        $this->assertSame([['label' => 'ssh-bf', 'value' => 2]], $set->get('crowdsec.attack_types'));
        $this->assertSame([['label' => 'CN', 'value' => 2]], $set->get('crowdsec.attacks_by_country'));
    }

    public function test_better_uptime_reads_sla(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['attributes' => [
                'availability' => 99.95,
                'number_of_incidents' => 2,
                'total_downtime' => 180,
                'longest_incident' => 120,
                'average_incident' => 90,
            ]],
        ])]);

        $set = (new BetterUptimeConnector)->fetch(
            $this->source(DataSourceType::BetterUptime, ['monitor_id' => '123'], ['api_token' => 't']),
            $this->period(),
            [],
        );

        $this->assertSame(99.95, $set->get('betteruptime.uptime_percent'));
        $this->assertSame(2, $set->get('betteruptime.incidents'));
        $this->assertSame(180, $set->get('betteruptime.total_downtime'));
        $this->assertSame(120, $set->get('betteruptime.longest_incident'));
        $this->assertSame(90, $set->get('betteruptime.average_incident'));
    }

    public function test_better_uptime_builds_a_daily_response_time_series_in_ms(): void
    {
        Http::fake([
            '*/response-times*' => Http::response(['data' => ['attributes' => ['regions' => [[
                'region' => 'us',
                'response_times' => [
                    ['at' => '2026-06-01T10:00:00.000Z', 'response_time' => 0.02],
                    ['at' => '2026-06-01T11:00:00.000Z', 'response_time' => 0.04],
                    ['at' => '2026-06-02T10:00:00.000Z', 'response_time' => 0.10],
                ],
            ]]]]]),
            '*/sla*' => Http::response(['data' => ['attributes' => ['availability' => 99.9, 'number_of_incidents' => 0]]]),
            '*' => Http::response(['data' => ['attributes' => []]]),
        ]);

        $set = (new BetterUptimeConnector)->fetch(
            $this->source(DataSourceType::BetterUptime, ['monitor_id' => '123'], ['api_token' => 't']),
            $this->period(),
            [],
        );

        $this->assertTrue($set->isOk());
        // Day 1 averages (20ms + 40ms)/2 = 30ms; day 2 = 100ms.
        $this->assertSame(
            [['date' => '2026-06-01', 'value' => 30.0], ['date' => '2026-06-02', 'value' => 100.0]],
            $set->get('betteruptime.response_times'),
        );
        // Overall mean of the 3 points: (20+40+100)/3 = 53.3 ms.
        $this->assertSame(53.3, $set->get('betteruptime.avg_response_time'));
    }

    public function test_better_uptime_builds_an_incidents_table(): void
    {
        Http::fake([
            '*/incidents*' => Http::response(['data' => [
                [
                    'id' => '1',
                    'attributes' => [
                        'cause' => 'Timeout (no headers received)',
                        'started_at' => '2026-06-10T10:00:00.000Z',
                        'resolved_at' => '2026-06-10T10:32:00.000Z',
                        'status' => 'Resolved',
                    ],
                ],
                [   // May → outside the June period, must be filtered out
                    'id' => '2',
                    'attributes' => [
                        'cause' => 'Timeout',
                        'started_at' => '2026-05-06T16:47:10.000Z',
                        'resolved_at' => '2026-05-06T17:19:28.000Z',
                        'status' => 'Resolved',
                    ],
                ],
            ]]),
            '*' => Http::response(['data' => ['attributes' => ['availability' => 99.9]]]),
        ]);

        $set = (new BetterUptimeConnector)->fetch(
            $this->source(DataSourceType::BetterUptime, ['monitor_id' => '123'], ['api_token' => 't']),
            $this->period(),
            [],
        );

        $incidents = $set->get('betteruptime.incidents_list');
        $this->assertCount(1, $incidents);
        $this->assertSame(
            ['Inicio' => '10/06/2026 10:00 UTC', 'Duración' => '32 min', 'Causa' => 'Timeout (no headers received)', 'Estado' => 'Resuelto'],
            $incidents[0],
        );
    }

    public function test_virusdie_reads_mainwp_summary(): void
    {
        Http::fake(['*' => Http::response([
            'malware_found' => 3,
            'threats_removed' => 5,
            'infected_sites' => 1,
            'clean_sites' => 4,
            'scanned_sites' => 5,
            'firewall_active' => true,
            'sites' => [
                ['name' => 'Site A', 'malware' => 3],
                ['name' => 'Site B', 'malware' => 0],
            ],
        ])]);

        $set = (new VirusdieConnector)->fetch(
            $this->source(DataSourceType::Virusdie, ['dashboard_url' => 'https://dash.test'], ['token' => 't']),
            $this->period(),
            [],
        );

        $this->assertSame(3, $set->get('virusdie.malware_found'));
        $this->assertSame(5, $set->get('virusdie.threats_removed'));
        $this->assertSame(1, $set->get('virusdie.infected_sites'));
        $this->assertSame(4, $set->get('virusdie.clean_sites'));
        $this->assertSame(1, $set->get('virusdie.firewall_active'));
        $this->assertSame([['label' => 'Site A', 'value' => 3]], $set->get('virusdie.infected_sites_list'));
    }

    public function test_a_failed_http_response_yields_a_failed_set(): void
    {
        Http::fake(['*' => Http::response('error', 500)]);

        $set = (new CloudflareConnector)->fetch(
            $this->source(DataSourceType::Cloudflare, ['zone_id' => 'z1'], ['api_token' => 't']),
            $this->period(),
            [],
        );

        $this->assertTrue($set->isFailed());
    }
}
