<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Mailchimp\MailchimpConnector;
use App\Connectors\Period;
use App\Connectors\TikTokAds\TikTokAdsConnector;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketingConnectorsTest extends TestCase
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

    public function test_tiktok_ads_aggregates_totals_series_and_top_campaigns(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'AUCTION_CAMPAIGN')) {
                return Http::response(['code' => 0, 'data' => ['list' => [
                    ['metrics' => ['campaign_name' => 'Verano', 'spend' => '90', 'clicks' => '120', 'impressions' => '3000']],
                    ['metrics' => ['campaign_name' => 'Marca', 'spend' => '60', 'clicks' => '80', 'impressions' => '2000']],
                ]]]);
            }
            if (str_contains($url, 'stat_time_day')) {
                return Http::response(['code' => 0, 'data' => ['list' => [
                    // TikTok's own stamp, out of order.
                    ['dimensions' => ['stat_time_day' => '2026-06-02 00:00:00'], 'metrics' => ['spend' => '100', 'clicks' => '170']],
                    ['dimensions' => ['stat_time_day' => '2026-06-01 00:00:00'], 'metrics' => ['spend' => '50', 'clicks' => '80']],
                ]]]);
            }

            return Http::response(['code' => 0, 'data' => ['list' => [
                ['dimensions' => ['advertiser_id' => '1'], 'metrics' => ['spend' => '150', 'impressions' => '5000', 'clicks' => '250', 'conversion' => '12', 'ctr' => '5', 'cpc' => '0.6']],
            ]]]);
        });

        $set = (new TikTokAdsConnector)->fetch(
            $this->source(DataSourceType::TikTokAds, ['advertiser_id' => '1'], ['access_token' => 'tt-token']),
            $this->period(),
            [],
        );

        $this->assertTrue($set->isOk());
        $this->assertSame(150.0, $set->get('tiktok_ads.spend'));
        $this->assertSame(250, $set->get('tiktok_ads.clicks'));
        $this->assertSame(12.0, $set->get('tiktok_ads.conversions'));
        $this->assertSame([['date' => '2026-06-01', 'value' => 50.0], ['date' => '2026-06-02', 'value' => 100.0]], $set->get('tiktok_ads.spend_by_date'));
        $this->assertSame('Verano', $set->get('tiktok_ads.top_campaigns')[0]['campaign']);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Access-Token', 'tt-token'));
    }

    public function test_tiktok_ads_reports_failed_on_a_non_zero_code(): void
    {
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 40001, 'message' => 'Invalid token'])]);

        $set = (new TikTokAdsConnector)->fetch(
            $this->source(DataSourceType::TikTokAds, ['advertiser_id' => '1'], ['access_token' => 'bad']),
            $this->period(),
            [],
        );

        $this->assertTrue($set->isFailed());
    }

    public function test_mailchimp_aggregates_campaign_reports_over_the_period(): void
    {
        Http::fake(['us21.api.mailchimp.com/*' => Http::response(['reports' => [
            ['emails_sent' => 1000, 'campaign_title' => 'Boletín junio', 'bounces' => ['hard_bounces' => 30, 'soft_bounces' => 20, 'syntax_errors' => 0], 'opens' => ['unique_opens' => 300, 'open_rate' => 0.3158], 'clicks' => ['clicks_total' => 120, 'unique_subscriber_clicks' => 80, 'click_rate' => 0.0842], 'unsubscribed' => 5],
            ['emails_sent' => 500, 'campaign_title' => 'Oferta', 'bounces' => ['hard_bounces' => 0, 'soft_bounces' => 0, 'syntax_errors' => 0], 'opens' => ['unique_opens' => 100, 'open_rate' => 0.2], 'clicks' => ['clicks_total' => 30, 'unique_subscriber_clicks' => 20, 'click_rate' => 0.04], 'unsubscribed' => 2],
        ]])]);

        $set = (new MailchimpConnector)->fetch(
            $this->source(DataSourceType::Mailchimp, [], ['api_key' => 'abcdef-us21']),
            $this->period(),
            [],
        );

        $this->assertTrue($set->isOk());
        $this->assertSame(2, $set->get('mailchimp.campaigns_sent'));
        $this->assertSame(1500, $set->get('mailchimp.emails_sent'));
        $this->assertSame(400, $set->get('mailchimp.opens'));
        // Like Mailchimp: over delivered mail (1500 − 50 bounces), people counted once.
        $this->assertEqualsWithDelta(27.6, $set->get('mailchimp.open_rate'), 0.05); // 400/1450
        $this->assertEqualsWithDelta(6.9, $set->get('mailchimp.click_rate'), 0.05); // 100/1450
        $this->assertSame(150, $set->get('mailchimp.clicks')); // total clicks stay a total
        $this->assertSame('Boletín junio', $set->get('mailchimp.top_campaigns')[0]['campaign']);

        // The datacenter suffix of the key selects the API host.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'us21.api.mailchimp.com'));
    }

    public function test_mailchimp_fails_when_the_key_has_no_datacenter_suffix(): void
    {
        Http::fake(); // guard triggers before any request, but keep stray requests out

        $set = (new MailchimpConnector)->fetch(
            $this->source(DataSourceType::Mailchimp, [], ['api_key' => 'keywithoutdash']),
            $this->period(),
            [],
        );

        $this->assertTrue($set->isFailed());
        Http::assertNothingSent();
    }
}
