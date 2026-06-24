<?php

declare(strict_types=1);

namespace App\Connectors\CrowdSec;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\Contracts\ProvidesSetupGuide;
use App\Connectors\Contracts\ReceivesPushedData;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\SetupGuide;
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
final class CrowdSecConnector implements DataSourceConnector, ProvidesSetupGuide, ReceivesPushedData
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
            new ConfigField('api_url', 'API base URL (opcional)', ConfigFieldType::Url, help: 'Solo si consultas una LAPI directa (p. ej. por red privada). En modo push (recomendado) déjalo vacío.'),
            new ConfigField('token', 'API token (opcional)', ConfigFieldType::Password, secret: true, help: 'Solo para LAPI/Console directa. En modo push no hace falta: el VPS envía los datos con su token de envío.'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('crowdsec.alerts', 'Alertas', MetricType::Scalar, 'count'),
            new MetricDefinition('crowdsec.attacks_blocked', 'Ataques bloqueados', MetricType::Scalar, 'count'),
            new MetricDefinition('crowdsec.events', 'Eventos maliciosos', MetricType::Scalar, 'count'),
            new MetricDefinition('crowdsec.unique_ips', 'IPs atacantes', MetricType::Scalar, 'count'),
            new MetricDefinition('crowdsec.attack_types', 'Tipos de ataque', MetricType::Table, dimensions: ['scenario']),
            new MetricDefinition('crowdsec.attacks_by_country', 'Ataques por país', MetricType::Table, dimensions: ['country']),
            new MetricDefinition('crowdsec.top_attacker_ips', 'IPs más activas', MetricType::Table, dimensions: ['ip']),
        );
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'CrowdSec corre en el VPS de cada cliente, así que en vez de abrir un puerto entrante (riesgo), '
            .'cada VPS ENVÍA sus datos de forma saliente a Imagina Reports. La API de la Console (nube) es de pago; '
            .'esta vía usa la LAPI local gratis y no expone nada. Guarda primero la fuente para obtener su token de envío.',
            [
                'Guarda esta fuente (sin tocar api_url/token, son opcionales en modo push) — al guardarla aparece abajo un comando de instalación con tu token y URL ya rellenados.',
                'Entra por SSH al VPS del cliente donde corre CrowdSec.',
                'Pega el comando de instalación que te mostramos: crea un cron que ejecuta «cscli alerts list -o json» y lo envía por HTTPS saliente a Imagina Reports.',
                'No se abre ningún puerto en el VPS del cliente: solo hace una llamada saliente, como cualquier web.',
                'Vuelve aquí: cuando llegue el primer envío, el estado pasará a «ok» y verás las métricas en el reporte.',
            ],
            'https://docs.crowdsec.net/docs/local_api/intro',
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

        return $this->normalizeAlerts($this->listOf($response->json()));
    }

    /**
     * Push model (CLAUDE.md §9): the client VPS runs `cscli alerts list -o json` locally
     * and POSTs the result here, so no inbound port is opened. The payload is either the
     * raw alerts array or `{ "alerts": [...] }`; both normalize to the same metric bag as
     * the polled path, keeping the connector the single source of truth.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function fromPushedPayload(array $payload): MetricSet
    {
        $alerts = array_key_exists('alerts', $payload)
            ? $this->listOf($payload['alerts'])
            : $this->listOf($payload);

        return $this->normalizeAlerts($alerts);
    }

    /**
     * Map a list of CrowdSec alerts (same shape from the LAPI or `cscli -o json`) into
     * the normalized metric bag.
     *
     * @param  list<array<array-key, mixed>>  $alerts
     */
    private function normalizeAlerts(array $alerts): MetricSet
    {
        $blocked = 0;
        $events = 0;
        /** @var array<string, int> $byScenario */
        $byScenario = [];
        /** @var array<string, int> $byCountry */
        $byCountry = [];
        /** @var array<string, int> $byIp */
        $byIp = [];

        foreach ($alerts as $alert) {
            $blocked += count($this->listOf(Arr::get($alert, 'decisions')));
            $events += $this->toInt(Arr::get($alert, 'events_count'));

            $scenario = $this->toStr(Arr::get($alert, 'scenario'));
            if ($scenario !== '') {
                $byScenario[$scenario] = ($byScenario[$scenario] ?? 0) + 1;
            }

            $country = $this->toStr(Arr::get($alert, 'source.cn'));
            if ($country !== '') {
                $byCountry[$country] = ($byCountry[$country] ?? 0) + 1;
            }

            $ip = $this->toStr(Arr::get($alert, 'source.value'));
            if ($ip !== '') {
                $byIp[$ip] = ($byIp[$ip] ?? 0) + 1;
            }
        }

        return MetricSet::ok([
            'crowdsec.alerts' => count($alerts),
            'crowdsec.attacks_blocked' => $blocked,
            'crowdsec.events' => $events,
            'crowdsec.unique_ips' => count($byIp),
            'crowdsec.attack_types' => $this->topTable($byScenario),
            'crowdsec.attacks_by_country' => $this->topTable($byCountry),
            'crowdsec.top_attacker_ips' => $this->topTable($byIp),
        ]);
    }

    /**
     * Sort a label→total map descending and keep the top 10 as normalized table rows.
     *
     * @param  array<string, int>  $map
     * @return list<array{label: string, value: int}>
     */
    private function topTable(array $map): array
    {
        arsort($map);

        $rows = [];
        foreach (array_slice($map, 0, 10, true) as $label => $value) {
            $rows[] = ['label' => $label, 'value' => $value];
        }

        return $rows;
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
