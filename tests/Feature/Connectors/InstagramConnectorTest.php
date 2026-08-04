<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Instagram\InstagramConnector;
use App\Connectors\MetricType;
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

    public function test_the_media_dataset_exposes_post_level_rows_the_editor_can_filter(): void
    {
        Http::fake([
            'graph.facebook.com/*/178414/insights*' => Http::response(['data' => []]),
            'graph.facebook.com/*/178414/media*' => Http::response(['data' => [
                [
                    'caption' => "Lanzamiento de la colección\nsegunda línea",
                    'media_type' => 'IMAGE',
                    'permalink' => 'https://instagram.com/p/abc',
                    'like_count' => 40,
                    'comments_count' => 5,
                    // Inline field expansion: one request, not one per post.
                    'insights' => ['data' => [
                        ['name' => 'reach', 'values' => [['value' => 900]]],
                        ['name' => 'saved', 'values' => [['value' => 12]]],
                    ]],
                ],
                // No caption and no insights at all — must still produce a usable row.
                ['media_type' => 'VIDEO', 'permalink' => 'https://instagram.com/p/xyz', 'like_count' => 3, 'comments_count' => 0],
            ]]),
            'graph.facebook.com/*/178414*' => Http::response(['followers_count' => 100, 'media_count' => 2]),
        ]);

        $rows = (new InstagramConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), [])->get('instagram.media');

        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
        $this->assertSame('Lanzamiento de la colección', $rows[0]['media']);
        $this->assertSame('IMAGE', $rows[0]['media_type']);
        $this->assertSame(900, $rows[0]['reach']);
        $this->assertSame(12, $rows[0]['saved']);
        // Falls back to the permalink, and missing insights read as zero rather than blowing up.
        $this->assertSame('https://instagram.com/p/xyz', $rows[1]['media']);
        $this->assertSame(0, $rows[1]['reach']);
    }

    public function test_the_media_catalog_entry_declares_its_dimensions_and_measures(): void
    {
        $definition = (new InstagramConnector)->metricCatalog($this->source())->get('instagram.media');

        $this->assertNotNull($definition);
        $this->assertSame(MetricType::Dataset, $definition->type);
        $this->assertSame(['media', 'media_type'], $definition->dimensions);
        $this->assertSame(['reach', 'likes', 'comments', 'saved'], array_column($definition->measures, 'key'));
    }

    public function test_connectable_resources_also_finds_accounts_held_in_a_business_portfolio(): void
    {
        // The agency case: nothing is assigned to the person directly — the client's page
        // lives in a Business portfolio they administer. /me/accounts alone finds nothing.
        Http::fake([
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => []]),
            'graph.facebook.com/*/me/businesses*' => Http::response(['data' => [['id' => 'biz-1']]]),
            'graph.facebook.com/*/biz-1/owned_pages*' => Http::response([
                'data' => [['name' => 'Cliente A', 'instagram_business_account' => ['id' => '111', 'username' => 'cliente_a']]],
            ]),
            'graph.facebook.com/*/biz-1/client_pages*' => Http::response([
                'data' => [['name' => 'Cliente B', 'instagram_business_account' => ['id' => '222', 'username' => 'cliente_b']]],
            ]),
        ]);

        $resources = (new InstagramConnector)->connectableResources($this->source());

        $this->assertNotNull($resources);
        $this->assertCount(2, $resources->options);
        $this->assertSame(['111', '222'], array_column($resources->options, 'value'));
    }

    public function test_the_same_account_reached_two_ways_is_offered_once(): void
    {
        Http::fake([
            'graph.facebook.com/*/me/accounts*' => Http::response([
                'data' => [['name' => 'Acme', 'instagram_business_account' => ['id' => '111', 'username' => 'acme']]],
            ]),
            'graph.facebook.com/*/me/businesses*' => Http::response(['data' => [['id' => 'biz-1']]]),
            'graph.facebook.com/*/biz-1/owned_pages*' => Http::response([
                'data' => [['name' => 'Acme', 'instagram_business_account' => ['id' => '111', 'username' => 'acme']]],
            ]),
            'graph.facebook.com/*/biz-1/client_pages*' => Http::response(['data' => []]),
        ]);

        $resources = (new InstagramConnector)->connectableResources($this->source());

        $this->assertCount(1, $resources?->options ?? []);
    }

    public function test_a_denied_business_permission_still_returns_the_personal_pages(): void
    {
        // business_management not granted yet: the business edges 403, and that must not
        // wipe out the pages the personal edge did find.
        Http::fake([
            'graph.facebook.com/*/me/accounts*' => Http::response([
                'data' => [['name' => 'Acme', 'instagram_business_account' => ['id' => '111', 'username' => 'acme']]],
            ]),
            'graph.facebook.com/*/me/businesses*' => Http::response(['error' => ['message' => 'no permission']], 403),
        ]);

        $resources = (new InstagramConnector)->connectableResources($this->source());

        $this->assertCount(1, $resources?->options ?? []);
        $this->assertSame('111', $resources?->options[0]['value']);
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
