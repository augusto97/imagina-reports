<?php

declare(strict_types=1);

namespace App\Connectors\BetterUptime;

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
 * Better Stack (Uptime) connector (CLAUDE.md §9). Bearer token; reads uptime %,
 * SLA and incidents for a monitor. Field names are a documented assumption (see
 * PROGRESS Open Questions); parsing is defensive.
 */
final class BetterUptimeConnector implements DataSourceConnector
{
    use ParsesValues;

    private const API_BASE = 'https://uptime.betterstack.com/api/v2';

    public function key(): string
    {
        return DataSourceType::BetterUptime->value;
    }

    public function label(): string
    {
        return DataSourceType::BetterUptime->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('monitor_id', 'Monitor ID', ConfigFieldType::Text, help: 'ID del monitor: Better Stack → Monitors → tu monitor (aparece en la URL).'),
            new ConfigField('api_token', 'API token (Bearer)', ConfigFieldType::Password, secret: true, help: 'Better Stack → Settings → API tokens → crea un token (Bearer).'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('betteruptime.uptime_percent', 'Uptime', MetricType::Scalar, 'percent'),
            new MetricDefinition('betteruptime.incidents', 'Incidents', MetricType::Scalar, 'count'),
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            $response = $this->sla($source, Period::make('30 days ago', 'today'));

            return $response->successful()
                ? ConnectionResult::success('Better Stack API reachable.')
                : ConnectionResult::failure('Better Stack responded with HTTP '.$response->status());
        } catch (Throwable $e) {
            return ConnectionResult::failure('Could not reach Better Stack: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        try {
            $response = $this->sla($source, $period);
        } catch (Throwable $e) {
            return MetricSet::failed('Better Stack request error: '.$e->getMessage());
        }

        if ($response->failed()) {
            return MetricSet::failed('Better Stack request failed: HTTP '.$response->status());
        }

        $attributes = $this->arrayOf(Arr::get($this->arrayOf($response->json()), 'data.attributes'));

        return MetricSet::ok([
            'betteruptime.uptime_percent' => $this->toFloat(Arr::get($attributes, 'availability')),
            'betteruptime.incidents' => $this->toInt(Arr::get($attributes, 'number_of_incidents')),
        ]);
    }

    private function sla(DataSource $source, Period $period): Response
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];
        $monitorId = $this->toStr(Arr::get($config, 'monitor_id'));

        return Http::withToken($this->toStr(Arr::get($credentials, 'api_token')))
            ->acceptJson()
            ->timeout(20)
            ->get(self::API_BASE."/monitors/{$monitorId}/sla", [
                'from' => $period->start->toDateString(),
                'to' => $period->end->toDateString(),
            ]);
    }
}
