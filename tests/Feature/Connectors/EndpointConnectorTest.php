<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Endpoint\EndpointConnector;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EndpointConnectorTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     */
    private function source(array $config, array $credentials = []): DataSource
    {
        return DataSource::factory()->make([
            'agency_id' => 1,
            'type' => DataSourceType::Endpoint,
            'config' => $config,
            'credentials' => $credentials,
        ]);
    }

    private function period(): Period
    {
        return Period::make('2026-06-01', '2026-06-30');
    }

    public function test_it_maps_json_via_configured_paths(): void
    {
        Http::fake(['*' => Http::response([
            'visits' => 1234,
            'trend' => [
                ['date' => '2026-06-01', 'count' => 5],
                ['date' => '2026-06-02', 'count' => 9],
            ],
            'pages' => [
                ['url' => '/a', 'views' => 100],
                ['url' => '/b', 'views' => 50],
            ],
        ])]);

        $source = $this->source([
            'url' => 'https://api.test/report',
            'format' => 'json',
            'metrics' => [
                ['key' => 'visits', 'label' => 'Visits', 'type' => 'scalar', 'path' => 'visits'],
                ['key' => 'trend', 'label' => 'Trend', 'type' => 'series', 'path' => 'trend', 'label_field' => 'date', 'value_field' => 'count'],
                ['key' => 'pages', 'label' => 'Pages', 'type' => 'table', 'path' => 'pages'],
            ],
        ], ['token' => 'secret']);

        $set = (new EndpointConnector)->fetch($source, $this->period(), []);

        $this->assertTrue($set->isOk());
        $this->assertSame(1234, $set->get('endpoint.visits'));
        $this->assertSame([
            ['label' => '2026-06-01', 'value' => 5],
            ['label' => '2026-06-02', 'value' => 9],
        ], $set->get('endpoint.trend'));
        $this->assertSame([
            ['url' => '/a', 'views' => 100],
            ['url' => '/b', 'views' => 50],
        ], $set->get('endpoint.pages'));

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret'));
    }

    public function test_it_maps_csv_rows(): void
    {
        Http::fake(['*' => Http::response("country,total\nES,15\nPT,20\n")]);

        $source = $this->source([
            'url' => 'https://api.test/export.csv',
            'format' => 'csv',
            'metrics' => [
                ['key' => 'by_country', 'label' => 'By country', 'type' => 'series', 'label_field' => 'country', 'value_field' => 'total'],
                ['key' => 'total', 'label' => 'Total', 'type' => 'scalar', 'value_field' => 'total'],
                ['key' => 'rows', 'label' => 'Rows', 'type' => 'table'],
            ],
        ]);

        $set = (new EndpointConnector)->fetch($source, $this->period(), []);

        $this->assertTrue($set->isOk());
        $this->assertSame([
            ['label' => 'ES', 'value' => 15],
            ['label' => 'PT', 'value' => 20],
        ], $set->get('endpoint.by_country'));
        $this->assertSame(15, $set->get('endpoint.total'));
        $this->assertSame([
            ['country' => 'ES', 'total' => '15'],
            ['country' => 'PT', 'total' => '20'],
        ], $set->get('endpoint.rows'));
    }

    public function test_a_failed_http_response_yields_a_failed_set(): void
    {
        Http::fake(['*' => Http::response('error', 500)]);

        $source = $this->source([
            'url' => 'https://api.test/report',
            'metrics' => [['key' => 'visits', 'label' => 'Visits', 'type' => 'scalar', 'path' => 'visits']],
        ]);

        $set = (new EndpointConnector)->fetch($source, $this->period(), []);

        $this->assertTrue($set->isFailed());
    }
}
