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
use Carbon\CarbonImmutable;
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
            new MetricDefinition('crowdsec.alerts', 'Ataques detectados', MetricType::Scalar, 'count', description: 'Veces que CrowdSec reconoció un comportamiento de ataque contra el servidor en el periodo.'),
            new MetricDefinition('crowdsec.attacks_blocked', 'Atacantes bloqueados', MetricType::Scalar, 'count', description: 'Bloqueos que CrowdSec aplicó a los atacantes (por ejemplo, vetar su IP) para cortar el ataque.'),
            new MetricDefinition('crowdsec.events', 'Intentos maliciosos', MetricType::Scalar, 'count', description: 'Intentos sueltos que formaron esos ataques (por ejemplo, cada contraseña probada en un ataque de fuerza bruta).'),
            new MetricDefinition('crowdsec.unique_ips', 'Direcciones atacantes', MetricType::Scalar, 'count', description: 'Direcciones IP distintas desde las que se atacó el servidor en el periodo.'),
            new MetricDefinition('crowdsec.attack_types', 'Tipos de ataque', MetricType::Table, dimensions: ['scenario'], description: 'Qué clase de ataques se detectaron (fuerza bruta, escaneos, bots…) y cuántas veces cada uno.'),
            new MetricDefinition('crowdsec.attacks_by_country', 'Ataques por país', MetricType::Table, dimensions: ['country'], description: 'Desde qué países llegaron los ataques detectados, de más a menos.'),
            new MetricDefinition('crowdsec.top_attacker_ips', 'Direcciones más insistentes', MetricType::Table, dimensions: ['ip'], description: 'Las direcciones IP que más veces atacaron el servidor en el periodo.'),
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
            $response = $this->client($source)->get('/alerts', $this->rangeQuery($period));
        } catch (Throwable $e) {
            return MetricSet::failed('CrowdSec request error: '.$e->getMessage());
        }

        if ($response->failed()) {
            return MetricSet::failed('CrowdSec request failed: HTTP '.$response->status());
        }

        return $this->normalizeAlerts($this->listOf($response->json()));
    }

    /**
     * The LAPI reads `since`/`until` as durations back from now («720h»), not dates, and
     * returns 100 alerts unless told otherwise (`limit=0` is all of them, as `cscli --limit 0`).
     *
     * @return array<string, string|int>
     */
    private function rangeQuery(Period $period): array
    {
        $now = CarbonImmutable::now();
        $hoursSince = (int) ceil(max(0, $now->getTimestamp() - $period->start->startOfDay()->getTimestamp()) / 3600);
        $query = ['since' => max(1, $hoursSince).'h', 'limit' => 0];

        $end = $period->end->endOfDay();
        if ($end->lessThan($now)) {
            $query['until'] = (int) floor(($now->getTimestamp() - $end->getTimestamp()) / 3600).'h';
        }

        return $query;
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
                $type = self::attackType($scenario);
                $byScenario[$type] = ($byScenario[$type] ?? 0) + 1;
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
     * A CrowdSec scenario («crowdsecurity/http-bf-wordpress_bf») in words a client reads, so
     * the attack-types table says «Fuerza bruta contra el acceso de WordPress». Scenarios of
     * the same kind share a name and add up; an unknown one is tidied up, never dropped.
     */
    public static function attackType(string $scenario): string
    {
        $name = strtolower(trim($scenario));
        $name = str_contains($name, '/') ? substr($name, (int) strrpos($name, '/') + 1) : $name;

        foreach ([
            '/wordpress.*bf|wp.*bf|bf.*wordpress|wp-login/' => 'Fuerza bruta contra el acceso de WordPress',
            '/ssh/' => 'Fuerza bruta por SSH',
            '/(mysql|mariadb|postgres|mssql|mongo).*(bf|brute)/' => 'Fuerza bruta contra la base de datos',
            '/(ftp|smtp|postfix|dovecot|imap|pop3|exim).*(bf|brute|auth)/' => 'Fuerza bruta contra el correo o el FTP',
            '/(^|[-_])bf($|[-_])|brute/' => 'Fuerza bruta (contraseñas)',
            '/user-?enum/' => 'Búsqueda de usuarios del sitio',
            '/sqli/' => 'Intento de inyección SQL',
            '/xss/' => 'Intento de inyección de código (XSS)',
            '/path-traversal|lfi|rfi/' => 'Intento de leer archivos del servidor',
            '/sensitive-files|backdoor|wpconfig|webshell/' => 'Búsqueda de archivos sensibles',
            '/cve|vpatch|appsec|exploit|log4j|spring4shell|(^|[-_])rce/' => 'Intento de explotar una vulnerabilidad conocida',
            '/admin-interface/' => 'Búsqueda de paneles de administración',
            '/wordpress|wp-/' => 'Escaneo de WordPress',
            '/req-limit|dos|flood/' => 'Exceso de peticiones',
            '/open-proxy/' => 'Intento de usar el sitio como proxy',
            '/bad-user-agent|bot|crawl/' => 'Bots maliciosos',
            '/prob|scan/' => 'Escaneo del sitio',
            '/spam/' => 'Spam',
        ] as $pattern => $label) {
            if (preg_match($pattern, $name) === 1) {
                return $label;
            }
        }

        $words = trim((string) preg_replace('/[-_]+/', ' ', $name));

        return $words === '' ? 'Otro' : ucfirst($words);
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
