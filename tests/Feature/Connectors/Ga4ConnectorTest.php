<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Ga4\Ga4Connector;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\Connectors\FakeGa4TokenProvider;
use Tests\TestCase;

class Ga4ConnectorTest extends TestCase
{
    private const RUN_REPORT = 'analyticsdata.googleapis.com/*';

    private function connector(?FakeGa4TokenProvider $tokens = null): Ga4Connector
    {
        return new Ga4Connector($tokens ?? new FakeGa4TokenProvider);
    }

    private function source(): DataSource
    {
        return DataSource::factory()->make([
            'agency_id' => 1,
            'type' => DataSourceType::Ga4,
            'config' => ['property_id' => '123456789'],
            'credentials' => ['type' => 'service_account', 'client_email' => 'sa@example.iam'],
        ]);
    }

    private function period(): Period
    {
        return Period::make('2026-06-01', '2026-06-30');
    }

    public function test_catalog_lists_ga4_metrics(): void
    {
        $catalog = $this->connector()->metricCatalog($this->source());

        $this->assertTrue($catalog->has('ga4.sessions'));
        $this->assertTrue($catalog->has('ga4.sessions_by_date'));
        $this->assertTrue($catalog->has('ga4.top_pages'));
    }

    public function test_fetch_parses_a_scalar_metric(): void
    {
        Http::fake([self::RUN_REPORT => Http::response([
            'rows' => [['metricValues' => [['value' => '1234']]]],
        ])]);

        $set = $this->connector()->fetch($this->source(), $this->period(), ['ga4.sessions']);

        $this->assertTrue($set->isOk());
        $this->assertSame(1234, $set->get('ga4.sessions'));
    }

    public function test_fetch_parses_a_series_metric(): void
    {
        Http::fake([self::RUN_REPORT => Http::response([
            'rows' => [
                ['dimensionValues' => [['value' => '20260601']], 'metricValues' => [['value' => '40']]],
                ['dimensionValues' => [['value' => '20260602']], 'metricValues' => [['value' => '55']]],
            ],
        ])]);

        $set = $this->connector()->fetch($this->source(), $this->period(), ['ga4.sessions_by_date']);

        $this->assertSame([
            ['date' => '20260601', 'value' => 40],
            ['date' => '20260602', 'value' => 55],
        ], $set->get('ga4.sessions_by_date'));
    }

    public function test_fetch_parses_a_table_metric(): void
    {
        Http::fake([self::RUN_REPORT => Http::response([
            'rows' => [
                ['dimensionValues' => [['value' => '/home']], 'metricValues' => [['value' => '900']]],
                ['dimensionValues' => [['value' => '/pricing']], 'metricValues' => [['value' => '120']]],
            ],
        ])]);

        $set = $this->connector()->fetch($this->source(), $this->period(), ['ga4.top_pages']);

        $this->assertSame([
            ['label' => '/home', 'value' => 900],
            ['label' => '/pricing', 'value' => 120],
        ], $set->get('ga4.top_pages'));
    }

    public function test_a_partial_failure_keeps_the_succeeding_metric(): void
    {
        Http::fake([self::RUN_REPORT => Http::sequence()
            ->push(['rows' => [['metricValues' => [['value' => '10']]]]], 200)
            ->push('error', 500),
        ]);

        $set = $this->connector()->fetch($this->source(), $this->period(), ['ga4.sessions', 'ga4.users']);

        $this->assertTrue($set->isPartial());
        $this->assertSame(10, $set->get('ga4.sessions'));
        $this->assertFalse($set->has('ga4.users'));
        $this->assertNotNull($set->error);
    }

    public function test_missing_property_id_fails_without_calling_the_api(): void
    {
        Http::fake();

        $source = DataSource::factory()->make([
            'agency_id' => 1,
            'type' => DataSourceType::Ga4,
            'config' => [],
            'credentials' => [],
        ]);

        $set = $this->connector()->fetch($source, $this->period(), ['ga4.sessions']);

        $this->assertTrue($set->isFailed());
        Http::assertNothingSent();
    }

    public function test_auth_failure_yields_a_failed_set(): void
    {
        Http::fake();
        $tokens = new FakeGa4TokenProvider(throws: new RuntimeException('bad key'));

        $set = $this->connector($tokens)->fetch($this->source(), $this->period(), ['ga4.sessions']);

        $this->assertTrue($set->isFailed());
        $this->assertNotNull($set->error);
        Http::assertNothingSent();
    }
}
