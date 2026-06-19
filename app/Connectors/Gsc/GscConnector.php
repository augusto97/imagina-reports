<?php

declare(strict_types=1);

namespace App\Connectors\Gsc;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\Google\GoogleTokenProvider;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Search Console connector (CLAUDE.md §9). Authenticates with a service
 * account and reads clicks, impressions, CTR, average position and top queries/
 * pages via the Search Console API `searchanalytics.query`, which aggregates
 * server-side (§3.3). Returns a normalized `gsc.*` bag; catches its own errors (§7).
 */
final class GscConnector implements DataSourceConnector
{
    private const API_BASE = 'https://searchconsole.googleapis.com/webmasters/v3';

    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    /** @var array<string, string> */
    private const SCALARS = [
        'gsc.clicks' => 'clicks',
        'gsc.impressions' => 'impressions',
        'gsc.ctr' => 'ctr',
        'gsc.position' => 'position',
    ];

    /** @var array<string, string> */
    private const TABLES = [
        'gsc.top_queries' => 'query',
        'gsc.top_pages' => 'page',
    ];

    public function __construct(private readonly GoogleTokenProvider $tokenProvider) {}

    public function key(): string
    {
        return DataSourceType::Gsc->value;
    }

    public function label(): string
    {
        return DataSourceType::Gsc->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('site_url', 'Search Console property URL', ConfigFieldType::Text, help: 'e.g. https://example.com/ or sc-domain:example.com'),
            new ConfigField('service_account', 'Service account JSON', ConfigFieldType::Json, secret: true),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('gsc.clicks', 'Clicks', MetricType::Scalar, 'count'),
            new MetricDefinition('gsc.impressions', 'Impressions', MetricType::Scalar, 'count'),
            new MetricDefinition('gsc.ctr', 'CTR', MetricType::Scalar, 'ratio'),
            new MetricDefinition('gsc.position', 'Average position', MetricType::Scalar, 'position'),
            new MetricDefinition('gsc.top_queries', 'Top queries', MetricType::Table, dimensions: ['query']),
            new MetricDefinition('gsc.top_pages', 'Top pages', MetricType::Table, dimensions: ['page']),
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        $siteUrl = $this->siteUrl($source);

        if ($siteUrl === '') {
            return ConnectionResult::failure('GSC site_url is not configured.');
        }

        try {
            $token = $this->token($source);
        } catch (Throwable $e) {
            return ConnectionResult::failure('GSC authentication failed: '.$e->getMessage());
        }

        if ($token === '') {
            return ConnectionResult::failure('GSC authentication returned no access token.');
        }

        $response = $this->query($token, $siteUrl, [], 1, Period::make('7 days ago', 'today'));

        return $response->successful()
            ? ConnectionResult::success('GSC property reachable.')
            : ConnectionResult::failure('GSC responded with HTTP '.$response->status());
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        $siteUrl = $this->siteUrl($source);

        if ($siteUrl === '') {
            return MetricSet::failed('GSC site_url is not configured.');
        }

        try {
            $token = $this->token($source);
        } catch (Throwable $e) {
            return MetricSet::failed('GSC authentication failed: '.$e->getMessage());
        }

        if ($token === '') {
            return MetricSet::failed('GSC authentication returned no access token.');
        }

        $keys = $requestedMetrics === []
            ? [...array_keys(self::SCALARS), ...array_keys(self::TABLES)]
            : $requestedMetrics;

        $metrics = [];
        $errors = [];

        $this->collectScalars($token, $siteUrl, $period, $keys, $metrics, $errors);
        $this->collectTables($token, $siteUrl, $period, $keys, $metrics, $errors);

        if ($metrics === [] && $errors !== []) {
            return MetricSet::failed(implode('; ', $errors));
        }

        return $errors === []
            ? MetricSet::ok($metrics)
            : MetricSet::partial($metrics, implode('; ', $errors));
    }

    /**
     * The four totals come from a single no-dimension query.
     *
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectScalars(string $token, string $siteUrl, Period $period, array $keys, array &$metrics, array &$errors): void
    {
        $requested = array_values(array_intersect(array_keys(self::SCALARS), $keys));

        if ($requested === []) {
            return;
        }

        try {
            $response = $this->query($token, $siteUrl, [], 1, $period);
        } catch (Throwable $e) {
            $errors[] = 'gsc totals: '.$e->getMessage();

            return;
        }

        if ($response->failed()) {
            $errors[] = 'gsc totals: HTTP '.$response->status();

            return;
        }

        $row = $this->rows($response->json())[0] ?? [];

        foreach ($requested as $key) {
            $field = self::SCALARS[$key];
            $metrics[$key] = in_array($field, ['clicks', 'impressions'], true)
                ? $this->intVal(Arr::get($row, $field))
                : $this->floatVal(Arr::get($row, $field));
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectTables(string $token, string $siteUrl, Period $period, array $keys, array &$metrics, array &$errors): void
    {
        foreach (self::TABLES as $key => $dimension) {
            if (! in_array($key, $keys, true)) {
                continue;
            }

            try {
                $response = $this->query($token, $siteUrl, [$dimension], 10, $period);
            } catch (Throwable $e) {
                $errors[] = "{$key}: ".$e->getMessage();

                continue;
            }

            if ($response->failed()) {
                $errors[] = "{$key}: HTTP ".$response->status();

                continue;
            }

            $metrics[$key] = array_map(
                fn (array $row): array => [
                    'label' => $this->strVal(Arr::get($row, 'keys.0')),
                    'clicks' => $this->intVal(Arr::get($row, 'clicks')),
                    'impressions' => $this->intVal(Arr::get($row, 'impressions')),
                ],
                $this->rows($response->json()),
            );
        }
    }

    /**
     * @param  list<string>  $dimensions
     */
    private function query(string $token, string $siteUrl, array $dimensions, int $rowLimit, Period $period): Response
    {
        $body = [
            'startDate' => $period->start->toDateString(),
            'endDate' => $period->end->toDateString(),
            'rowLimit' => $rowLimit,
        ];

        if ($dimensions !== []) {
            $body['dimensions'] = $dimensions;
        }

        $endpoint = self::API_BASE.'/sites/'.rawurlencode($siteUrl).'/searchAnalytics/query';

        return Http::withToken($token)->acceptJson()->post($endpoint, $body);
    }

    private function token(DataSource $source): string
    {
        return $this->tokenProvider->accessToken($source->credentials ?? [], self::SCOPE);
    }

    private function siteUrl(DataSource $source): string
    {
        $value = Arr::get($source->config ?? [], 'site_url', '');

        return is_scalar($value) ? (string) $value : '';
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

    private function intVal(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function floatVal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function strVal(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
