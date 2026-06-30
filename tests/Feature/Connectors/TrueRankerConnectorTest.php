<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Period;
use App\Connectors\TrueRanker\TrueRankerConnector;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrueRankerConnectorTest extends TestCase
{
    private function source(string $project = '12345'): DataSource
    {
        return DataSource::factory()->make([
            'agency_id' => 1,
            'type' => DataSourceType::TrueRanker,
            'config' => ['project' => $project],
            'credentials' => ['key' => 'tr-api-key'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'ok' => true,
            'data' => [
                'keywords' => [
                    [
                        'id' => 1,
                        'keyword' => 'best seo tool',
                        'tags' => ['seo'],
                        'location' => 'Spain',
                        'country' => 'ES',
                        'device' => 'Desktop',
                        'volume' => 390,
                        'best_rank_ever' => 3,
                        'cpc' => '1.2150',
                        'rank' => [
                            '2026-06-01' => ['rank' => 5, 'url' => 'https://x.test/a'],
                            '2026-06-30' => ['rank' => 3, 'url' => 'https://x.test/a'],
                        ],
                    ],
                    [
                        'id' => 2,
                        'keyword' => 'keyword tracker',
                        'tags' => [],
                        'location' => '',
                        'country' => 'US',
                        'device' => 'Mobile',
                        'volume' => 1000,
                        'best_rank_ever' => 8,
                        'cpc' => '2.0',
                        'rank' => [
                            '2026-06-01' => ['rank' => 8],
                            '2026-06-30' => ['rank' => 12],
                        ],
                    ],
                    [
                        'id' => 3,
                        'keyword' => 'rank checker',
                        'tags' => [],
                        'location' => '',
                        'country' => 'ES',
                        'device' => 'Desktop',
                        'volume' => 50,
                        'best_rank_ever' => 200,
                        'cpc' => '0',
                        'rank' => [], // never ranks in the top 100
                    ],
                ],
            ],
        ];
    }

    public function test_catalog_lists_the_keyword_metrics(): void
    {
        $catalog = (new TrueRankerConnector)->metricCatalog($this->source());

        $this->assertTrue($catalog->has('trueranker.avg_position'));
        $this->assertTrue($catalog->has('trueranker.top3'));
        $this->assertTrue($catalog->has('trueranker.improved'));
        $this->assertTrue($catalog->has('trueranker.top_keywords'));
        $this->assertFalse($catalog->has('gsc.clicks'));
    }

    public function test_fetch_aggregates_the_keyword_rankings(): void
    {
        Http::fake(['app.trueranker.com/data/project/keywords*' => Http::response($this->payload())]);

        $set = (new TrueRankerConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isOk());
        $this->assertSame(3, $set->get('trueranker.keywords_tracked'));
        // Ranked = kw1(3) + kw2(12); avg = 7.5.
        $this->assertSame(7.5, $set->get('trueranker.avg_position'));
        $this->assertSame(1, $set->get('trueranker.top3'));   // kw1
        $this->assertSame(1, $set->get('trueranker.top10'));  // kw1 (kw2=12 is not ≤10)
        $this->assertSame(2, $set->get('trueranker.top100')); // kw1 + kw2
        $this->assertSame(1, $set->get('trueranker.improved')); // kw1 5→3
        $this->assertSame(1, $set->get('trueranker.declined')); // kw2 8→12
        $this->assertSame(1440, $set->get('trueranker.total_volume'));

        // Average position per day: 01 = avg(5,8)=6.5, 30 = avg(3,12)=7.5.
        $series = $set->get('trueranker.avg_position_by_date');
        $this->assertSame(['date' => '2026-06-01', 'value' => 6.5], $series[0]);
        $this->assertSame(['date' => '2026-06-30', 'value' => 7.5], $series[1]);

        // Top keywords are sorted by volume (kw2 first).
        $top = $set->get('trueranker.top_keywords');
        $this->assertSame('keyword tracker', $top[0]['Keyword']);
        $this->assertSame('12', $top[0]['Posición']);
        $this->assertSame('US', $top[0]['País']);
        $this->assertSame('Spain', $top[1]['País']); // kw1 uses its location
        $this->assertSame('+100', $top[2]['Posición']); // kw3 never ranked
    }

    public function test_fetch_sends_the_key_project_and_period(): void
    {
        Http::fake(['*' => Http::response($this->payload())]);

        (new TrueRankerConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'key=tr-api-key')
                && str_contains($request->url(), 'project=12345')
                && str_contains($request->url(), 'start=20260601')
                && str_contains($request->url(), 'end=20260630');
        });
    }

    public function test_fetch_returns_only_requested_metrics(): void
    {
        Http::fake(['*' => Http::response($this->payload())]);

        $set = (new TrueRankerConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), ['trueranker.avg_position']);

        $this->assertSame(['trueranker.avg_position'], $set->keys());
    }

    public function test_an_api_error_yields_a_failed_set(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'Invalid API Key'])]);

        $set = (new TrueRankerConnector)->fetch($this->source(), Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isFailed());
        $this->assertStringContainsString('Invalid API Key', (string) $set->error);
    }

    public function test_test_connection_succeeds_when_the_key_is_valid(): void
    {
        Http::fake(['app.trueranker.com/data/projects/list*' => Http::response(['ok' => true, 'data' => ['projects' => []]])]);

        $this->assertTrue((new TrueRankerConnector)->testConnection($this->source())->successful);
    }

    public function test_test_connection_reports_an_invalid_key(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'Invalid API Key'])]);

        $result = (new TrueRankerConnector)->testConnection($this->source());

        $this->assertFalse($result->successful);
        $this->assertStringContainsString('Invalid API Key', $result->message);
    }
}
