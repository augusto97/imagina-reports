<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Gsc\GscConnector;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\Connectors\FakeGoogleTokenProvider;
use Tests\TestCase;

class GscConnectorTest extends TestCase
{
    private const QUERY = 'searchconsole.googleapis.com/*';

    private function connector(?FakeGoogleTokenProvider $tokens = null): GscConnector
    {
        return new GscConnector($tokens ?? new FakeGoogleTokenProvider);
    }

    private function source(): DataSource
    {
        return DataSource::factory()->make([
            'agency_id' => 1,
            'type' => DataSourceType::Gsc,
            'config' => ['site_url' => 'https://example.com/'],
            'credentials' => ['type' => 'service_account', 'client_email' => 'sa@example.iam'],
        ]);
    }

    private function period(): Period
    {
        return Period::make('2026-06-01', '2026-06-30');
    }

    public function test_catalog_lists_gsc_metrics(): void
    {
        $catalog = $this->connector()->metricCatalog($this->source());

        $this->assertTrue($catalog->has('gsc.clicks'));
        $this->assertTrue($catalog->has('gsc.position'));
        $this->assertTrue($catalog->has('gsc.top_queries'));
    }

    public function test_fetch_reads_totals_in_a_single_query(): void
    {
        Http::fake([self::QUERY => Http::response([
            'rows' => [['clicks' => 120, 'impressions' => 3400, 'ctr' => 0.035, 'position' => 12.5]],
        ])]);

        $set = $this->connector()->fetch(
            $this->source(),
            $this->period(),
            ['gsc.clicks', 'gsc.impressions', 'gsc.ctr', 'gsc.position'],
        );

        $this->assertTrue($set->isOk());
        $this->assertSame(120, $set->get('gsc.clicks'));
        $this->assertSame(3400, $set->get('gsc.impressions'));
        $this->assertSame(3.5, $set->get('gsc.ctr')); // 0.035 ratio → 3.5 %
        $this->assertSame(12.5, $set->get('gsc.position'));
        Http::assertSentCount(1);
    }

    public function test_fetch_parses_a_clicks_series(): void
    {
        Http::fake([self::QUERY => Http::response([
            'rows' => [
                ['keys' => ['2026-06-01'], 'clicks' => 10, 'impressions' => 200],
                ['keys' => ['2026-06-02'], 'clicks' => 14, 'impressions' => 260],
            ],
        ])]);

        $set = $this->connector()->fetch($this->source(), $this->period(), ['gsc.clicks_by_date']);

        $this->assertTrue($set->isOk());
        $this->assertSame([
            ['date' => '2026-06-01', 'value' => 10],
            ['date' => '2026-06-02', 'value' => 14],
        ], $set->get('gsc.clicks_by_date'));
    }

    public function test_fetch_parses_a_top_queries_table(): void
    {
        Http::fake([self::QUERY => Http::response([
            'rows' => [
                ['keys' => ['imagina wp'], 'clicks' => 80, 'impressions' => 1000],
                ['keys' => ['reportes'], 'clicks' => 20, 'impressions' => 600],
            ],
        ])]);

        $set = $this->connector()->fetch($this->source(), $this->period(), ['gsc.top_queries']);

        $this->assertSame([
            ['label' => 'imagina wp', 'clicks' => 80, 'impressions' => 1000],
            ['label' => 'reportes', 'clicks' => 20, 'impressions' => 600],
        ], $set->get('gsc.top_queries'));
    }

    public function test_fetch_parses_the_search_dataset_into_named_rows(): void
    {
        Http::fake([self::QUERY => Http::response([
            'rows' => [
                ['keys' => ['imagina wp', '/home', 'col', 'DESKTOP'], 'clicks' => 80, 'impressions' => 1000],
                ['keys' => ['reportes', '/blog', 'mex', 'MOBILE'], 'clicks' => 20, 'impressions' => 600],
            ],
        ])]);

        $set = $this->connector()->fetch($this->source(), $this->period(), ['gsc.search']);

        $this->assertTrue($set->isOk());
        $this->assertSame([
            ['query' => 'imagina wp', 'page' => '/home', 'country' => 'col', 'device' => 'DESKTOP', 'clicks' => 80, 'impressions' => 1000],
            ['query' => 'reportes', 'page' => '/blog', 'country' => 'mex', 'device' => 'MOBILE', 'clicks' => 20, 'impressions' => 600],
        ], $set->get('gsc.search'));
    }

    public function test_partial_when_a_table_query_fails(): void
    {
        Http::fake([self::QUERY => Http::sequence()
            ->push(['rows' => [['clicks' => 5, 'impressions' => 9, 'ctr' => 0.5, 'position' => 1.0]]], 200)
            ->push('error', 500),
        ]);

        $set = $this->connector()->fetch($this->source(), $this->period(), ['gsc.clicks', 'gsc.top_queries']);

        $this->assertTrue($set->isPartial());
        $this->assertSame(5, $set->get('gsc.clicks'));
        $this->assertFalse($set->has('gsc.top_queries'));
        $this->assertNotNull($set->error);
    }

    public function test_missing_site_url_fails_without_calling_the_api(): void
    {
        Http::fake();

        $source = DataSource::factory()->make([
            'agency_id' => 1,
            'type' => DataSourceType::Gsc,
            'config' => [],
            'credentials' => [],
        ]);

        $set = $this->connector()->fetch($source, $this->period(), ['gsc.clicks']);

        $this->assertTrue($set->isFailed());
        Http::assertNothingSent();
    }

    public function test_auth_failure_yields_a_failed_set(): void
    {
        Http::fake();
        $tokens = new FakeGoogleTokenProvider(throws: new RuntimeException('bad key'));

        $set = $this->connector($tokens)->fetch($this->source(), $this->period(), ['gsc.clicks']);

        $this->assertTrue($set->isFailed());
        Http::assertNothingSent();
    }
}
