<?php

declare(strict_types=1);

namespace App\Connectors\Cloudflare;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\Contracts\ProvidesSetupGuide;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\SetupGuide;
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
final class CloudflareConnector implements DataSourceConnector, ProvidesSetupGuide
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
            new ConfigField('zone_id', 'Zone ID', ConfigFieldType::Text, help: 'ID de la zona: Cloudflare → tu dominio → Overview (abajo a la derecha).'),
            new ConfigField('api_token', 'API token (Analytics:Read)', ConfigFieldType::Password, secret: true, help: 'My Profile → API Tokens → Create Token, con permiso Zone Analytics: Read.'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('cloudflare.requests', 'Solicitudes al sitio', MetricType::Scalar, 'count', description: 'Todas las veces que navegadores y bots pidieron algo al sitio (páginas, imágenes, archivos) a través de Cloudflare.'),
            new MetricDefinition('cloudflare.cached_requests', 'Solicitudes servidas desde caché', MetricType::Scalar, 'count', description: 'Solicitudes que Cloudflare respondió con su copia guardada, sin cargar tu servidor: cuantas más, más rápido.'),
            new MetricDefinition('cloudflare.threats_blocked', 'Amenazas bloqueadas', MetricType::Scalar, 'count', description: 'Solicitudes peligrosas (ataques, bots maliciosos) que el firewall de Cloudflare frenó antes de llegar al sitio.'),
            new MetricDefinition('cloudflare.page_views', 'Páginas vistas', MetricType::Scalar, 'count', description: 'Páginas completas que se abrieron, según Cloudflare (no cuenta imágenes ni archivos sueltos).'),
            new MetricDefinition('cloudflare.encrypted_requests', 'Solicitudes seguras (HTTPS)', MetricType::Scalar, 'count', description: 'Solicitudes que viajaron cifradas con HTTPS (el candado del navegador).'),
            new MetricDefinition('cloudflare.unique_visitors', 'Visitantes únicos', MetricType::Scalar, 'count', description: 'Direcciones IP distintas que visitaron el sitio en el periodo, según Cloudflare.'),
            new MetricDefinition('cloudflare.cache_ratio', 'Contenido servido desde caché', MetricType::Scalar, 'percent', description: 'Porcentaje de solicitudes que Cloudflare respondió desde su caché: cuanto más alto, más rápido carga y menos trabaja el servidor.'),
            new MetricDefinition('cloudflare.bandwidth', 'Datos transferidos', MetricType::Scalar, 'bytes', description: 'Cantidad de datos que el sitio envió a los visitantes en el periodo (ancho de banda).'),
            new MetricDefinition('cloudflare.requests_by_date', 'Solicitudes por día', MetricType::Series, 'count', description: 'Cuántas solicitudes recibió el sitio cada día del periodo.'),
            new MetricDefinition('cloudflare.threats_by_date', 'Amenazas bloqueadas por día', MetricType::Series, 'count', description: 'Cuántas amenazas frenó Cloudflare cada día: un pico suele indicar un ataque.'),
            new MetricDefinition('cloudflare.bandwidth_by_date', 'Datos transferidos por día', MetricType::Series, 'bytes', description: 'Cantidad de datos que el sitio envió cada día del periodo.'),
            new MetricDefinition('cloudflare.threats_by_country', 'Amenazas por país', MetricType::Table, dimensions: ['country'], description: 'Desde qué países llegaron las amenazas bloqueadas, de más a menos.'),
            new MetricDefinition('cloudflare.requests_by_country', 'Solicitudes por país', MetricType::Table, dimensions: ['country'], description: 'Desde qué países se visita el sitio, según las solicitudes recibidas.'),
        );
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'Crea un API token de Cloudflare con permiso de analítica de solo lectura, acotado a la zona del sitio.',
            [
                'Cloudflare → icono de perfil → My Profile → API Tokens → Create Token → «Create Custom Token».',
                'Permisos: Zone → Analytics → Read. En «Zone Resources» limítalo a la zona (dominio) del sitio.',
                'Crea el token y cópialo (solo se muestra una vez) → pégalo en «API token».',
                'El Zone ID está en el panel de la zona: pestaña Overview → columna derecha «Zone ID». Pégalo en «zone_id».',
                'Guarda y pulsa «Probar conexión».',
            ],
            'https://developers.cloudflare.com/fundamentals/api/get-started/create-token/',
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            $response = $this->query($source, Period::make('7 days ago', 'today'));

            if ($response->failed()) {
                return ConnectionResult::failure($this->httpFailureMessage($response));
            }

            // A 200 with GraphQL errors (bad field/permission) or no visible zone is the
            // common silent-failure — surface it instead of a false "reachable".
            $error = $this->graphqlError($response->json());
            if ($error !== null) {
                return ConnectionResult::failure('Cloudflare: '.$error);
            }

            if ($this->zones($response->json()) === []) {
                return ConnectionResult::failure('Cloudflare: el token no ve la zona o el Zone ID es incorrecto. Revisa el permiso «Zone Analytics: Read» y que el token incluya esa zona.');
            }

            return ConnectionResult::success('Cloudflare API reachable.');
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
            return MetricSet::failed($this->httpFailureMessage($response));
        }

        // GraphQL returns HTTP 200 even on errors; without this the report would just
        // show zeros. If the full query hit an unknown/forbidden field, retry with the
        // core set so the main metrics still come through (the extras hide gracefully).
        $error = $this->graphqlError($response->json());
        $groups = $this->groups($response->json());

        if ($groups === [] && $error !== null) {
            try {
                $response = $this->query($source, $period, full: false);
            } catch (Throwable $e) {
                return MetricSet::failed('Cloudflare request error: '.$e->getMessage());
            }

            $coreError = $this->graphqlError($response->json());
            $groups = $this->groups($response->json());

            if ($groups === [] && $coreError !== null) {
                return MetricSet::failed('Cloudflare: '.$coreError);
            }
        }

        $requests = $cached = $threats = $bytes = $pageViews = $encrypted = $uniques = 0;
        $requestsByDate = $threatsByDate = $bandwidthByDate = [];
        /** @var array<string, int> $threatsByCountry */
        $threatsByCountry = [];
        /** @var array<string, int> $requestsByCountry */
        $requestsByCountry = [];

        foreach ($groups as $group) {
            $sum = $this->arrayOf(Arr::get($group, 'sum'));
            $date = $this->toStr(Arr::get($group, 'dimensions.date'));
            $r = $this->toInt(Arr::get($sum, 'requests'));
            $t = $this->toInt(Arr::get($sum, 'threats'));
            $b = $this->toInt(Arr::get($sum, 'bytes'));

            $requests += $r;
            $cached += $this->toInt(Arr::get($sum, 'cachedRequests'));
            $threats += $t;
            $bytes += $b;
            $pageViews += $this->toInt(Arr::get($sum, 'pageViews'));
            $encrypted += $this->toInt(Arr::get($sum, 'encryptedRequests'));
            $uniques += $this->toInt(Arr::get($group, 'uniq.uniques'));

            if ($date !== '') {
                $requestsByDate[] = ['date' => $date, 'value' => $r];
                $threatsByDate[] = ['date' => $date, 'value' => $t];
                $bandwidthByDate[] = ['date' => $date, 'value' => $b];
            }

            foreach ($this->listOf(Arr::get($sum, 'countryMap')) as $entry) {
                $country = $this->toStr(Arr::get($entry, 'clientCountryName'));
                if ($country === '') {
                    continue;
                }
                $threatsByCountry[$country] = ($threatsByCountry[$country] ?? 0) + $this->toInt(Arr::get($entry, 'threats'));
                $requestsByCountry[$country] = ($requestsByCountry[$country] ?? 0) + $this->toInt(Arr::get($entry, 'requests'));
            }
        }

        return MetricSet::ok([
            'cloudflare.requests' => $requests,
            'cloudflare.cached_requests' => $cached,
            'cloudflare.threats_blocked' => $threats,
            'cloudflare.page_views' => $pageViews,
            'cloudflare.encrypted_requests' => $encrypted,
            'cloudflare.unique_visitors' => $uniques,
            'cloudflare.cache_ratio' => $requests > 0 ? round($cached / $requests * 100, 2) : 0.0,
            'cloudflare.bandwidth' => $bytes,
            'cloudflare.requests_by_date' => $requestsByDate,
            'cloudflare.threats_by_date' => $threatsByDate,
            'cloudflare.bandwidth_by_date' => $bandwidthByDate,
            'cloudflare.threats_by_country' => $this->topTable($threatsByCountry),
            'cloudflare.requests_by_country' => $this->topTable($requestsByCountry),
        ]);
    }

    /**
     * Turn a failed HTTP response into an actionable message. 401/403 is an auth
     * problem (bad/expired token or missing scope), not a transient outage, so it gets
     * specific guidance rather than a bare status code.
     */
    private function httpFailureMessage(Response $response): string
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            return 'Cloudflare: token inválido, caducado o sin permiso (HTTP '.$status.'). Crea o renueva un API token con permiso «Zone Analytics: Read» acotado a la zona y pégalo de nuevo (sin espacios).';
        }

        return 'Cloudflare respondió con HTTP '.$status.'.';
    }

    /**
     * The GraphQL `errors[].message` joined, or null when the response has none.
     * Cloudflare returns these with HTTP 200, so they must be checked explicitly.
     */
    private function graphqlError(mixed $json): ?string
    {
        $errors = is_array($json) ? ($json['errors'] ?? null) : null;

        if (! is_array($errors) || $errors === []) {
            return null;
        }

        $messages = [];
        foreach ($errors as $error) {
            $message = is_array($error) ? Arr::get($error, 'message') : null;
            if (is_string($message) && $message !== '') {
                $messages[] = $message;
            }
        }

        return $messages === [] ? 'error de GraphQL sin detalle.' : implode('; ', $messages);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function zones(mixed $json): array
    {
        return $this->listOf(Arr::get($this->arrayOf($json), 'data.viewer.zones'));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function groups(mixed $json): array
    {
        return $this->listOf(Arr::get($this->arrayOf($json), 'data.viewer.zones.0.httpRequests1dGroups'));
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

    private function query(DataSource $source, Period $period, bool $full = true): Response
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];

        // The "full" set adds fields some plans don't expose (uniques, pageViews,
        // encryptedRequests, country map). GraphQL fails the whole query on any unknown
        // field, so fetch() falls back to the core set, which every plan has.
        // Note: `threatPathingMap { pathingSource }` is intentionally omitted — that field
        // is not part of the httpRequests1dGroups schema and rejects the whole query.
        $groupExtra = $full ? 'uniq { uniques }' : '';
        $sumExtra = $full
            ? 'pageViews encryptedRequests countryMap { clientCountryName requests threats }'
            : '';

        $graphql = <<<GQL
        query Analytics(\$zone: String!, \$since: String!, \$until: String!) {
          viewer { zones(filter: { zoneTag: \$zone }) {
            httpRequests1dGroups(limit: 1000, filter: { date_geq: \$since, date_leq: \$until }, orderBy: [date_ASC]) {
              dimensions { date }
              {$groupExtra}
              sum { requests cachedRequests threats bytes {$sumExtra} }
            }
          } }
        }
        GQL;

        // Trim both: a token or Zone ID pasted with a trailing space/newline would send a
        // malformed Bearer header and fail auth with a 401 even though the value is right.
        $token = trim($this->toStr(Arr::get($credentials, 'api_token')));
        $zone = trim($this->toStr(Arr::get($config, 'zone_id')));

        return Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->post(self::ENDPOINT, [
                'query' => $graphql,
                'variables' => [
                    'zone' => $zone,
                    'since' => $period->start->toDateString(),
                    'until' => $period->end->toDateString(),
                ],
            ]);
    }
}
