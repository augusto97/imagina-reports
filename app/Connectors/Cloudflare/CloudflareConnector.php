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
            new MetricDefinition('cloudflare.requests', 'Peticiones', MetricType::Scalar, 'count'),
            new MetricDefinition('cloudflare.cached_requests', 'Peticiones en caché', MetricType::Scalar, 'count'),
            new MetricDefinition('cloudflare.threats_blocked', 'Amenazas bloqueadas', MetricType::Scalar, 'count'),
            new MetricDefinition('cloudflare.page_views', 'Páginas vistas', MetricType::Scalar, 'count'),
            new MetricDefinition('cloudflare.encrypted_requests', 'Peticiones cifradas (HTTPS)', MetricType::Scalar, 'count'),
            new MetricDefinition('cloudflare.unique_visitors', 'Visitantes únicos', MetricType::Scalar, 'count'),
            new MetricDefinition('cloudflare.cache_ratio', 'Ratio de caché', MetricType::Scalar, 'percent'),
            new MetricDefinition('cloudflare.bandwidth', 'Ancho de banda', MetricType::Scalar, 'bytes'),
            new MetricDefinition('cloudflare.requests_by_date', 'Peticiones por día', MetricType::Series, 'count'),
            new MetricDefinition('cloudflare.threats_by_date', 'Amenazas por día', MetricType::Series, 'count'),
            new MetricDefinition('cloudflare.bandwidth_by_date', 'Ancho de banda por día', MetricType::Series, 'bytes'),
            new MetricDefinition('cloudflare.threats_by_country', 'Amenazas por país', MetricType::Table, dimensions: ['country']),
            new MetricDefinition('cloudflare.requests_by_country', 'Peticiones por país', MetricType::Table, dimensions: ['country']),
            new MetricDefinition('cloudflare.top_threat_sources', 'Tipos de amenaza', MetricType::Table, dimensions: ['source']),
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
                return ConnectionResult::failure('Cloudflare responded with HTTP '.$response->status());
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
            return MetricSet::failed('Cloudflare request failed: HTTP '.$response->status());
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
        /** @var array<string, int> $threatSources */
        $threatSources = [];

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

            foreach ($this->listOf(Arr::get($sum, 'threatPathingMap')) as $entry) {
                $source = $this->toStr(Arr::get($entry, 'pathingSource'));
                if ($source === '') {
                    continue;
                }
                $threatSources[$source] = ($threatSources[$source] ?? 0) + $this->toInt(Arr::get($entry, 'requests'));
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
            'cloudflare.top_threat_sources' => $this->topTable($threatSources),
        ]);
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
        // encryptedRequests, country/threat maps). GraphQL fails the whole query on any
        // unknown field, so fetch() falls back to the core set, which every plan has.
        $groupExtra = $full ? 'uniq { uniques }' : '';
        $sumExtra = $full
            ? 'pageViews encryptedRequests countryMap { clientCountryName requests threats } threatPathingMap { pathingSource requests }'
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
