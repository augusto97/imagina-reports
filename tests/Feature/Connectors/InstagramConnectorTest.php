<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Instagram\InstagramConnector;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramConnectorTest extends TestCase
{
    private function source(): DataSource
    {
        return DataSource::factory()->make([
            'agency_id' => 1,
            'type' => DataSourceType::Instagram,
            'config' => ['ig_user_id' => '178414'],
            'credentials' => ['access_token' => 'meta-token'],
        ]);
    }

    public function test_catalog_lists_the_instagram_metrics(): void
    {
        $catalog = (new InstagramConnector)->metricCatalog($this->source());

        $this->assertTrue($catalog->has('instagram.followers'));
        $this->assertTrue($catalog->has('instagram.reach'));
        $this->assertTrue($catalog->has('instagram.new_followers'));
        $this->assertTrue($catalog->has('instagram.reach_by_date'));
    }

    public function test_fetch_maps_account_fields_and_period_insights(): void
    {
        Http::fake([
            'graph.facebook.com/*/178414/insights*' => Http::response([
                'data' => [
                    ['name' => 'reach', 'values' => [['value' => 100, 'end_time' => '2026-06-01T07:00:00+0000'], ['value' => 150, 'end_time' => '2026-06-02T07:00:00+0000']]],
                    ['name' => 'follower_count', 'values' => [['value' => 5], ['value' => 8]]],
                    ['name' => 'profile_views', 'values' => [['value' => 20], ['value' => 25]]],
                    ['name' => 'website_clicks', 'values' => [['value' => 2], ['value' => 3]]],
                ],
            ]),
            'graph.facebook.com/*/178414*' => Http::response(['followers_count' => 4820, 'media_count' => 312]),
        ]);

        $set = (new InstagramConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isOk());
        $this->assertSame(4820, $set->get('instagram.followers'));
        $this->assertSame(312, $set->get('instagram.media_count'));
        $this->assertSame(250, $set->get('instagram.reach')); // 100 + 150
        $this->assertSame(13, $set->get('instagram.new_followers')); // 5 + 8
        $this->assertSame(45, $set->get('instagram.profile_views'));

        $series = $set->get('instagram.reach_by_date');
        $this->assertSame(['date' => '2026-06-01', 'value' => 100], $series[0]);
    }

    public function test_insights_failure_degrades_to_partial_but_keeps_followers(): void
    {
        Http::fake([
            'graph.facebook.com/*/178414/insights*' => Http::response(['error' => ['message' => 'nope']], 400),
            'graph.facebook.com/*/178414*' => Http::response(['followers_count' => 100, 'media_count' => 10]),
        ]);

        $set = (new InstagramConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isPartial());
        $this->assertSame(100, $set->get('instagram.followers'));
    }

    public function test_connectable_resources_lists_linked_instagram_accounts(): void
    {
        Http::fake([
            'graph.facebook.com/*/me/accounts*' => Http::response([
                'data' => [
                    ['name' => 'Acme Page', 'instagram_business_account' => ['id' => '178414', 'username' => 'acme']],
                    ['name' => 'No IG Page'], // a page without a linked IG account is skipped
                ],
            ]),
        ]);

        $resources = (new InstagramConnector)->connectableResources($this->source());

        $this->assertNotNull($resources);
        $this->assertSame('ig_user_id', $resources->field);
        $this->assertCount(1, $resources->options);
        $this->assertSame('178414', $resources->options[0]['value']);
        $this->assertStringContainsString('@acme', $resources->options[0]['label']);
    }

    public function test_missing_ig_user_id_fails_cleanly(): void
    {
        $source = DataSource::factory()->make([
            'agency_id' => 1,
            'type' => DataSourceType::Instagram,
            'config' => [],
            'credentials' => ['access_token' => 'x'],
        ]);

        $this->assertTrue((new InstagramConnector)->fetch($source, Period::make('2026-06-01', '2026-06-30'), [])->isFailed());
    }
}
