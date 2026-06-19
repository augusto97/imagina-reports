<?php

declare(strict_types=1);

namespace App\Connectors\CrowdSec;

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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * CrowdSec connector (CLAUDE.md §9). Console API token (or per-VPS LAPI). Reads
 * alerts, decisions (bans) and attack types. Exact API shape is a documented
 * assumption (see PROGRESS Open Questions); parsing is defensive.
 */
final class CrowdSecConnector implements DataSourceConnector
{
    use ParsesValues;

    public function key(): string
    {
        return DataSourceType::CrowdSec->value;
    }

    public function label(): string
    {
        return DataSourceType::CrowdSec->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('api_url', 'API base URL', ConfigFieldType::Url, help: 'CrowdSec Console API or LAPI base'),
            new ConfigField('token', 'API token', ConfigFieldType::Password, secret: true),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('crowdsec.alerts', 'Alerts', MetricType::Scalar, 'count'),
            new MetricDefinition('crowdsec.attacks_blocked', 'Attacks blocked', MetricType::Scalar, 'count'),
            new MetricDefinition('crowdsec.attack_types', 'Attack types', MetricType::Table, dimensions: ['scenario']),
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            $response = $this->client($source)->get('/alerts');

            return $response->successful()
                ? ConnectionResult::success('CrowdSec API reachable.')
                : ConnectionResult::failure('CrowdSec responded with HTTP '.$response->status());
        } catch (Throwable $e) {
            return ConnectionResult::failure('Could not reach CrowdSec: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        try {
            $response = $this->client($source)->get('/alerts', [
                'since' => $period->start->toIso8601String(),
                'until' => $period->end->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            return MetricSet::failed('CrowdSec request error: '.$e->getMessage());
        }

        if ($response->failed()) {
            return MetricSet::failed('CrowdSec request failed: HTTP '.$response->status());
        }

        $alerts = $this->listOf($response->json());

        $blocked = 0;
        $byScenario = [];

        foreach ($alerts as $alert) {
            $blocked += count($this->listOf(Arr::get($alert, 'decisions')));
            $scenario = $this->toStr(Arr::get($alert, 'scenario'));
            $byScenario[$scenario] = ($byScenario[$scenario] ?? 0) + 1;
        }

        $attackTypes = [];
        foreach ($byScenario as $scenario => $count) {
            $attackTypes[] = ['scenario' => $scenario, 'count' => $count];
        }

        return MetricSet::ok([
            'crowdsec.alerts' => count($alerts),
            'crowdsec.attacks_blocked' => $blocked,
            'crowdsec.attack_types' => $attackTypes,
        ]);
    }

    private function client(DataSource $source): PendingRequest
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];

        return Http::baseUrl(rtrim($this->toStr(Arr::get($config, 'api_url')), '/'))
            ->withToken($this->toStr(Arr::get($credentials, 'token')))
            ->acceptJson()
            ->timeout(20);
    }
}
