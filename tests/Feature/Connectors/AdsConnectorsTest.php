<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\FacebookAds\FacebookAdsConnector;
use App\Connectors\GoogleAds\GoogleAdsConnector;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdsConnectorsTest extends TestCase
{
    // The Google Ads connector reads platform OAuth settings (PlatformSetting) as a fallback
    // for client_id/developer_token/MCC, so the schema must exist.
    use RefreshDatabase;

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

    public function test_google_ads_connectable_resources_include_the_account_name(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'ya29-fake']);
            }
            if (str_contains($request->url(), ':listAccessibleCustomers')) {
                return Http::response(['resourceNames' => ['customers/1234567890']]);
            }

            // ONE customer_client query resolves the names for every accessible account.
            return Http::response(['results' => [
                ['customerClient' => ['id' => '1234567890', 'descriptiveName' => 'Tienda Acme']],
            ]]);
        });

        $resources = (new GoogleAdsConnector)->connectableResources(
            $this->source(DataSourceType::GoogleAds, ['client_id' => 'cid'], ['developer_token' => 'd', 'client_secret' => 's', 'refresh_token' => 'r']),
        );

        $this->assertNotNull($resources);
        $this->assertSame('customer_id', $resources->field);
        $this->assertSame('1234567890', $resources->options[0]['value']);
        // Label shows the human name + the formatted id.
        $this->assertSame('Tienda Acme (123-456-7890)', $resources->options[0]['label']);
    }

    public function test_the_meta_campaign_dataset_splits_by_placement_so_instagram_can_be_isolated(): void
    {
        // Instagram ads are Meta ads: same account, told apart by publisher_platform.
        Http::fake(function (Request $request) {
            if (str_contains((string) ($request->data()['breakdowns'] ?? ''), 'publisher_platform')) {
                return Http::response(['data' => [
                    ['campaign_name' => 'Rebajas', 'publisher_platform' => 'instagram', 'spend' => '30.00', 'impressions' => '900', 'clicks' => '20', 'actions' => [['action_type' => 'purchase', 'value' => '3']]],
                    ['campaign_name' => 'Rebajas', 'publisher_platform' => 'facebook', 'spend' => '70.00', 'impressions' => '2100', 'clicks' => '50', 'actions' => []],
                ]]);
            }

            return Http::response(['data' => [[]]]);
        });

        $set = (new FacebookAdsConnector)->fetch(
            $this->source(DataSourceType::FacebookAds, ['ad_account_id' => '123'], ['access_token' => 'tok']),
            $this->period(),
            [],
        );

        $rows = $set->get('facebook_ads.campaigns');
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
        // Sorted by spend, so a truncated snapshot keeps the campaigns that matter.
        $this->assertSame('facebook', $rows[0]['platform']);
        $this->assertSame(70.0, $rows[0]['spend']);
        $this->assertSame('instagram', $rows[1]['platform']);
        // Float because conversion counting shares its summing with conversion VALUES,
        // which are monetary — same as the account-level facebook_ads.conversions scalar.
        $this->assertSame(3.0, $rows[1]['conversions']);
    }

    public function test_the_meta_campaign_dataset_declares_only_additive_measures(): void
    {
        // Summing a ratio across rows is silently wrong, so CTR/CPC stay account scalars.
        $definition = (new FacebookAdsConnector)
            ->metricCatalog($this->source(DataSourceType::FacebookAds, ['ad_account_id' => '123'], ['access_token' => 'tok']))
            ->get('facebook_ads.campaigns');

        $this->assertNotNull($definition);
        $this->assertSame(MetricType::Dataset, $definition->type);
        $this->assertSame(['campaign', 'platform'], $definition->dimensions);
        $this->assertSame(['spend', 'impressions', 'clicks', 'conversions'], array_column($definition->measures, 'key'));
    }

    public function test_meta_exposes_one_dataset_per_breakdown_axis(): void
    {
        // Country, device and age+gender can't be combined into one request — Meta refuses
        // most pairings and rows multiply — so each axis is its own bounded dataset.
        Http::fake(function (Request $request) {
            $breakdowns = (string) ($request->data()['breakdowns'] ?? '');

            if ($breakdowns === 'country') {
                return Http::response(['data' => [
                    ['campaign_name' => 'Rebajas', 'country' => 'CO', 'spend' => '40.00', 'impressions' => '800', 'clicks' => '12', 'actions' => []],
                ]]);
            }
            if ($breakdowns === 'impression_device') {
                return Http::response(['data' => [
                    ['campaign_name' => 'Rebajas', 'impression_device' => 'android_smartphone', 'spend' => '25.00', 'impressions' => '500', 'clicks' => '9', 'actions' => []],
                ]]);
            }
            if ($breakdowns === 'age,gender') {
                return Http::response(['data' => [
                    ['campaign_name' => 'Rebajas', 'age' => '25-34', 'gender' => 'female', 'spend' => '15.00', 'impressions' => '300', 'clicks' => '4', 'actions' => []],
                ]]);
            }

            return Http::response(['data' => [[]]]);
        });

        $set = (new FacebookAdsConnector)->fetch(
            $this->source(DataSourceType::FacebookAds, ['ad_account_id' => '123'], ['access_token' => 'tok']),
            $this->period(),
            [],
        );

        $this->assertSame('CO', $set->get('facebook_ads.by_country')[0]['country'] ?? null);
        $this->assertSame('android_smartphone', $set->get('facebook_ads.by_device')[0]['device'] ?? null);
        $this->assertSame('25-34', $set->get('facebook_ads.by_demographics')[0]['age'] ?? null);
        $this->assertSame('female', $set->get('facebook_ads.by_demographics')[0]['gender'] ?? null);
    }

    public function test_a_breakdown_meta_refuses_does_not_cost_the_others(): void
    {
        Http::fake(function (Request $request) {
            if ((string) ($request->data()['breakdowns'] ?? '') === 'age,gender') {
                return Http::response(['error' => ['message' => 'unsupported breakdown']], 400);
            }
            if (str_contains((string) ($request->data()['breakdowns'] ?? ''), 'publisher_platform')) {
                return Http::response(['data' => [
                    ['campaign_name' => 'Rebajas', 'publisher_platform' => 'instagram', 'spend' => '10.00', 'impressions' => '100', 'clicks' => '2', 'actions' => []],
                ]]);
            }

            return Http::response(['data' => []]);
        });

        $set = (new FacebookAdsConnector)->fetch(
            $this->source(DataSourceType::FacebookAds, ['ad_account_id' => '123'], ['access_token' => 'tok']),
            $this->period(),
            [],
        );

        // Degraded, not lost: the axes that worked are still there.
        $this->assertTrue($set->isPartial());
        $this->assertCount(1, $set->get('facebook_ads.campaigns'));
    }

    public function test_meta_ad_account_discovery_includes_business_portfolio_accounts(): void
    {
        // Agency shape: no ad account assigned to the person, all of them in the portfolio.
        Http::fake([
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => []]),
            'graph.facebook.com/*/me/businesses*' => Http::response(['data' => [['id' => 'biz-1']]]),
            'graph.facebook.com/*/biz-1/owned_ad_accounts*' => Http::response([
                'data' => [['name' => 'Cliente A', 'account_id' => '111']],
            ]),
            'graph.facebook.com/*/biz-1/client_ad_accounts*' => Http::response([
                'data' => [['name' => 'Cliente B', 'account_id' => '222']],
            ]),
        ]);

        $resources = (new FacebookAdsConnector)->connectableResources(
            $this->source(DataSourceType::FacebookAds, [], ['access_token' => 'meta-token']),
        );

        $this->assertNotNull($resources);
        $this->assertSame('ad_account_id', $resources->field);
        $this->assertSame(['111', '222'], array_column($resources->options, 'value'));
    }

    public function test_google_ads_aggregates_totals_series_and_top_campaigns(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'ya29-fake']);
            }

            $query = is_string($request->data()['query'] ?? null) ? $request->data()['query'] : '';

            if (str_contains($query, 'FROM campaign')) {
                return Http::response(['results' => [
                    ['campaign' => ['name' => 'Search - Marca'], 'metrics' => ['costMicros' => '20000000', 'clicks' => '30', 'impressions' => '600']],
                ]]);
            }

            if (str_contains($query, 'ORDER BY segments.date')) {
                return Http::response(['results' => [
                    ['segments' => ['date' => '2026-06-01'], 'metrics' => ['clicks' => '20', 'impressions' => '400']],
                    ['segments' => ['date' => '2026-06-02'], 'metrics' => ['clicks' => '30', 'impressions' => '600']],
                ]]);
            }

            return Http::response(['results' => [[
                'metrics' => ['impressions' => '1000', 'clicks' => '50', 'costMicros' => '25000000', 'conversions' => '5', 'conversionsValue' => '500', 'ctr' => '0.05', 'averageCpc' => '500000'],
            ]]]);
        });

        $set = (new GoogleAdsConnector)->fetch(
            $this->source(DataSourceType::GoogleAds, ['customer_id' => '1234567890', 'client_id' => 'cid'], ['developer_token' => 'devtok', 'client_secret' => 'sec', 'refresh_token' => 'ref']),
            $this->period(),
            [],
        );

        $this->assertTrue($set->isOk());
        $this->assertSame(1000, $set->get('google_ads.impressions'));
        $this->assertSame(50, $set->get('google_ads.clicks'));
        $this->assertSame(25.0, $set->get('google_ads.cost')); // 25_000_000 micros
        $this->assertSame(5.0, $set->get('google_ads.ctr')); // 0.05 ratio → 5%
        $this->assertSame(0.5, $set->get('google_ads.avg_cpc')); // 500_000 micros
        $this->assertCount(2, $set->get('google_ads.clicks_by_date'));
        $this->assertSame('Search - Marca', $set->get('google_ads.top_campaigns')[0]['campaign']);
        $this->assertSame(20.0, $set->get('google_ads.top_campaigns')[0]['cost']);

        // The developer token + OAuth bearer are sent on the correct search endpoint
        // (customers/{id}/googleAds:search — the '/googleAds' segment is required).
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'googleads.googleapis.com')
            && str_contains($request->url(), '/googleAds:search')
            && $request->hasHeader('developer-token', 'devtok')
            && $request->hasHeader('Authorization', 'Bearer ya29-fake'));
    }

    public function test_google_ads_reports_failed_when_the_token_cannot_be_obtained(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response([], 400)]);

        $set = (new GoogleAdsConnector)->fetch(
            $this->source(DataSourceType::GoogleAds, ['customer_id' => '1', 'client_id' => 'cid'], ['developer_token' => 'd', 'client_secret' => 's', 'refresh_token' => 'r']),
            $this->period(),
            [],
        );

        $this->assertTrue($set->isFailed());
    }

    public function test_facebook_ads_aggregates_totals_series_and_top_campaigns(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (! str_contains($url, '/insights')) {
                return Http::response(['account_id' => '1234567890']);
            }
            if (str_contains($url, 'level=campaign')) {
                return Http::response(['data' => [
                    ['campaign_name' => 'Reels', 'spend' => '80.00', 'clicks' => '40', 'impressions' => '900'],
                    ['campaign_name' => 'Feed', 'spend' => '120.50', 'clicks' => '60', 'impressions' => '1500'],
                ]]);
            }
            if (str_contains($url, 'time_increment')) {
                return Http::response(['data' => [
                    ['date_start' => '2026-06-01', 'clicks' => '20', 'impressions' => '400', 'spend' => '50.00'],
                    ['date_start' => '2026-06-02', 'clicks' => '30', 'impressions' => '600', 'spend' => '70.50'],
                ]]);
            }

            return Http::response(['data' => [[
                'impressions' => '2400', 'reach' => '1800', 'clicks' => '100', 'spend' => '200.50', 'ctr' => '4.17', 'cpc' => '2.00', 'cpm' => '83.5',
                'actions' => [['action_type' => 'purchase', 'value' => '7'], ['action_type' => 'landing_page_view', 'value' => '50']],
                'action_values' => [['action_type' => 'purchase', 'value' => '700']],
            ]]]);
        });

        $set = (new FacebookAdsConnector)->fetch(
            $this->source(DataSourceType::FacebookAds, ['ad_account_id' => '1234567890'], ['access_token' => 'meta-token']),
            $this->period(),
            [],
        );

        $this->assertTrue($set->isOk());
        $this->assertSame(2400, $set->get('facebook_ads.impressions'));
        $this->assertSame(100, $set->get('facebook_ads.clicks'));
        $this->assertSame(200.5, $set->get('facebook_ads.spend'));
        $this->assertSame(4.17, $set->get('facebook_ads.ctr'));
        // Only the conversion action types are summed (purchase), not landing_page_view.
        $this->assertSame(7.0, $set->get('facebook_ads.conversions'));
        $this->assertSame(700.0, $set->get('facebook_ads.conversions_value'));
        $this->assertCount(2, $set->get('facebook_ads.spend_by_date'));
        // Top campaigns sorted by spend desc.
        $this->assertSame('Feed', $set->get('facebook_ads.top_campaigns')[0]['campaign']);
        $this->assertSame(120.5, $set->get('facebook_ads.top_campaigns')[0]['spend']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com')
            && $request->hasHeader('Authorization', 'Bearer meta-token'));
    }

    public function test_facebook_ads_reports_failed_on_api_error(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

        $set = (new FacebookAdsConnector)->fetch(
            $this->source(DataSourceType::FacebookAds, ['ad_account_id' => '1'], ['access_token' => 'bad']),
            $this->period(),
            [],
        );

        $this->assertTrue($set->isFailed());
    }
}
