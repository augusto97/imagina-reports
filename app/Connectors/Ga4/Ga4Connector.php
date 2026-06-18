<?php

declare(strict_types=1);

namespace App\Connectors\Ga4;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Analytics 4 connector (CLAUDE.md §9). Authenticates with a service-account
 * JSON and reads sessions, users, conversions, traffic sources and top pages from
 * the Analytics Data API (`runReport`), which aggregates server-side by design
 * (§3.3). Returns a normalized `ga4.*` metric bag; catches its own errors (§7).
 */
final class Ga4Connector implements DataSourceConnector
{
    private const API_BASE = 'https://analyticsdata.googleapis.com/v1beta';

    public function __construct(private readonly Ga4TokenProvider $tokenProvider) {}

    public function key(): string
    {
        return DataSourceType::Ga4->value;
    }

    public function label(): string
    {
        return DataSourceType::Ga4->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('property_id', 'GA4 property ID', ConfigFieldType::Text, help: 'Numeric property id, e.g. 123456789'),
            new ConfigField('service_account', 'Service account JSON', ConfigFieldType::Json, secret: true),
        ];
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        $propertyId = $this->propertyId($source);

        if ($propertyId === '') {
            return ConnectionResult::failure('GA4 property_id is not configured.');
        }

        try {
            $token = $this->tokenProvider->accessToken($source->credentials ?? []);
        } catch (Throwable $e) {
            return ConnectionResult::failure('GA4 authentication failed: '.$e->getMessage());
        }

        if ($token === '') {
            return ConnectionResult::failure('GA4 authentication returned no access token.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->post(self::API_BASE."/properties/{$propertyId}:runReport", [
                'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
                'metrics' => [['name' => 'sessions']],
                'limit' => 1,
            ]);

        return $response->successful()
            ? ConnectionResult::success('GA4 property reachable.')
            : ConnectionResult::failure('GA4 responded with HTTP '.$response->status());
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        $definitions = [];

        foreach ($this->specs() as $key => $spec) {
            $definitions[] = new MetricDefinition($key, $spec['label'], $spec['type'], $spec['unit'], $spec['dimensions']);
        }

        return new MetricCatalog(...$definitions);
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        $propertyId = $this->propertyId($source);

        if ($propertyId === '') {
            return MetricSet::failed('GA4 property_id is not configured.');
        }

        try {
            $token = $this->tokenProvider->accessToken($source->credentials ?? []);
        } catch (Throwable $e) {
            return MetricSet::failed('GA4 authentication failed: '.$e->getMessage());
        }

        if ($token === '') {
            return MetricSet::failed('GA4 authentication returned no access token.');
        }

        $specs = $this->specs();
        $keys = $requestedMetrics === [] ? array_keys($specs) : array_values(array_intersect($requestedMetrics, array_keys($specs)));

        $metrics = [];
        $errors = [];

        foreach ($keys as $key) {
            try {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->post(self::API_BASE."/properties/{$propertyId}:runReport", $this->body($specs[$key], $period));

                if ($response->failed()) {
                    $errors[] = "{$key}: HTTP ".$response->status();

                    continue;
                }

                $metrics[$key] = $this->parse($specs[$key], $response->json());
            } catch (Throwable $e) {
                $errors[] = "{$key}: ".$e->getMessage();
            }
        }

        if ($metrics === [] && $errors !== []) {
            return MetricSet::failed(implode('; ', $errors));
        }

        return $errors === []
            ? MetricSet::ok($metrics)
            : MetricSet::partial($metrics, implode('; ', $errors));
    }

    private function propertyId(DataSource $source): string
    {
        $value = Arr::get($source->config ?? [], 'property_id', '');

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The GA4 metrics this connector exposes, mapped to Analytics Data API names.
     *
     * @return array<string, array{label: string, type: MetricType, unit: string|null, metrics: list<string>, dimensions: list<string>, limit: int}>
     */
    private function specs(): array
    {
        return [
            'ga4.sessions' => ['label' => 'Sessions', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['sessions'], 'dimensions' => [], 'limit' => 1],
            'ga4.users' => ['label' => 'Users', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['totalUsers'], 'dimensions' => [], 'limit' => 1],
            'ga4.conversions' => ['label' => 'Conversions', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['conversions'], 'dimensions' => [], 'limit' => 1],
            'ga4.screen_page_views' => ['label' => 'Page views', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['screenPageViews'], 'dimensions' => [], 'limit' => 1],
            'ga4.sessions_by_date' => ['label' => 'Sessions by date', 'type' => MetricType::Series, 'unit' => 'count', 'metrics' => ['sessions'], 'dimensions' => ['date'], 'limit' => 400],
            'ga4.top_pages' => ['label' => 'Top pages', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['screenPageViews'], 'dimensions' => ['pagePath'], 'limit' => 10],
            'ga4.traffic_sources' => ['label' => 'Traffic sources', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['sessions'], 'dimensions' => ['sessionDefaultChannelGroup'], 'limit' => 10],
        ];
    }

    /**
     * @param  array{label: string, type: MetricType, unit: string|null, metrics: list<string>, dimensions: list<string>, limit: int}  $spec
     * @return array<string, mixed>
     */
    private function body(array $spec, Period $period): array
    {
        $body = [
            'dateRanges' => [[
                'startDate' => $period->start->toDateString(),
                'endDate' => $period->end->toDateString(),
            ]],
            'metrics' => array_map(static fn (string $m): array => ['name' => $m], $spec['metrics']),
            'limit' => $spec['limit'],
        ];

        if ($spec['dimensions'] !== []) {
            $body['dimensions'] = array_map(static fn (string $d): array => ['name' => $d], $spec['dimensions']);
        }

        return $body;
    }

    /**
     * @param  array{label: string, type: MetricType, unit: string|null, metrics: list<string>, dimensions: list<string>, limit: int}  $spec
     * @return int|list<array{label: string, value: int}>|list<array{date: string, value: int}>
     */
    private function parse(array $spec, mixed $json): int|array
    {
        $rows = $this->rows($json);

        return match ($spec['type']) {
            MetricType::Scalar => array_sum(array_map($this->metricValue(...), $rows)),
            MetricType::Series => array_map(
                fn (array $row): array => ['date' => $this->dimensionValue($row), 'value' => $this->metricValue($row)],
                $rows,
            ),
            MetricType::Table => array_map(
                fn (array $row): array => ['label' => $this->dimensionValue($row), 'value' => $this->metricValue($row)],
                $rows,
            ),
        };
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rows(mixed $json): array
    {
        $rows = is_array($json) ? ($json['rows'] ?? []) : [];

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, is_array(...)));
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function metricValue(array $row): int
    {
        $value = Arr::get($row, 'metricValues.0.value');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function dimensionValue(array $row): string
    {
        $value = Arr::get($row, 'dimensionValues.0.value');

        return is_string($value) ? $value : '';
    }
}
