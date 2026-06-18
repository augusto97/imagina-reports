<?php

declare(strict_types=1);

namespace App\Connectors\Cloudflare;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\Support\ParsesValues;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare connector (CLAUDE.md §9). API token (Analytics:Read). Reads requests,
 * WAF threats blocked, cache ratio and bandwidth from the GraphQL Analytics API,
 * aggregated server-side (§3.3). The exact GraphQL shape is a documented assumption
 * — see PROGRESS Open Questions.
 */
final class CloudflareConnector implements DataSourceConnector
{
    use ParsesValues;

    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/graphql';

    public function key(): string
    {
        return DataSourceType::Cloudflare->value;
    }

    public function label(): string
    {
        return DataSourceType::Cloudflare->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('zone_id', 'Zone ID', ConfigFieldType::Text),
            new ConfigField('api_token', 'API token (Analytics:Read)', ConfigFieldType::Password, secret: true),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('cloudflare.requests', 'Requests', MetricType::Scalar, 'count'),
            new MetricDefinition('cloudflare.threats_blocked', 'Threats blocked', MetricType::Scalar, 'count'),
            new MetricDefinition('cloudflare.cache_ratio', 'Cache ratio', MetricType::Scalar, 'ratio'),
            new MetricDefinition('cloudflare.bandwidth', 'Bandwidth', MetricType::Scalar, 'bytes'),
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            $response = $this->query($source, Period::make('7 days ago', 'today'));

            return $response->successful()
                ? ConnectionResult::success('Cloudflare API reachable.')
                : ConnectionResult::failure('Cloudflare responded with HTTP '.$response->status());
        } catch (Throwable $e) {
            return ConnectionResult::failure('Could not reach Cloudflare: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        try {
            $response = $this->query($source, $period);
        } catch (Throwable $e) {
            return MetricSet::failed('Cloudflare request error: '.$e->getMessage());
        }

        if ($response->failed()) {
            return MetricSet::failed('Cloudflare request failed: HTTP '.$response->status());
        }

        $groups = $this->listOf(Arr::get($this->arrayOf($response->json()), 'data.viewer.zones.0.httpRequests1dGroups'));

        $requests = 0;
        $cached = 0;
        $threats = 0;
        $bytes = 0;

        foreach ($groups as $group) {
            $requests += $this->toInt(Arr::get($group, 'sum.requests'));
            $cached += $this->toInt(Arr::get($group, 'sum.cachedRequests'));
            $threats += $this->toInt(Arr::get($group, 'sum.threats'));
            $bytes += $this->toInt(Arr::get($group, 'sum.bytes'));
        }

        return MetricSet::ok([
            'cloudflare.requests' => $requests,
            'cloudflare.threats_blocked' => $threats,
            'cloudflare.cache_ratio' => $requests > 0 ? round($cached / $requests, 4) : 0.0,
            'cloudflare.bandwidth' => $bytes,
        ]);
    }

    private function query(DataSource $source, Period $period): Response
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];

        $graphql = <<<'GQL'
        query Analytics($zone: String!, $since: String!, $until: String!) {
          viewer { zones(filter: { zoneTag: $zone }) {
            httpRequests1dGroups(limit: 100, filter: { date_geq: $since, date_leq: $until }) {
              sum { requests cachedRequests threats bytes }
            }
          } }
        }
        GQL;

        return Http::withToken($this->toStr(Arr::get($credentials, 'api_token')))
            ->acceptJson()
            ->timeout(20)
            ->post(self::ENDPOINT, [
                'query' => $graphql,
                'variables' => [
                    'zone' => $this->toStr(Arr::get($config, 'zone_id')),
                    'since' => $period->start->toDateString(),
                    'until' => $period->end->toDateString(),
                ],
            ]);
    }
}
