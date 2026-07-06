<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\FacebookAds\FacebookAdsConnector;
use App\Connectors\GoogleAds\GoogleAdsConnector;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdsConnectorsTest extends TestCase
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

        // The developer token + OAuth bearer are sent on the API call.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'googleads.googleapis.com')
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
