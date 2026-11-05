<?php

declare(strict_types=1);

namespace App\Connectors\GoogleAds;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\Connect\ConnectableResources;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\Contracts\ListsConnectableResources;
use App\Connectors\Contracts\ProvidesSetupGuide;
use App\Connectors\Exceptions\DiscoveryFailed;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\SetupGuide;
use App\Connectors\Support\DescribesApiErrors;
use App\Connectors\Support\ParsesValues;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use App\Services\Platform\OAuthCredentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Ads connector (CLAUDE.md §9). Reads campaign performance (impressions, clicks,
 * cost, conversions, CTR, CPC) via the Google Ads API using GAQL, which aggregates
 * server-side over the period (§3.3) — the response is tiny regardless of account size.
 *
 * Auth is OAuth 2.0: a long-lived refresh token is exchanged for a short-lived access
 * token per sync, plus the account's developer token. Cost is returned in micros
 * (1e-6 of the account currency) and normalized to the currency here.
 *
 * The `metrics.*` GAQL field names and camelCase response keys are the documented API
 * shape; conversions map to the account's configured conversion actions.
 */
final class GoogleAdsConnector implements DataSourceConnector, ListsConnectableResources, ProvidesSetupGuide
{
    use DescribesApiErrors;
    use ParsesValues;

    /**
     * Google Ads API version (endpoint path segment). Google sunsets each version about a
     * year after release, and a retired version makes EVERY endpoint answer HTTP 404 — v18
     * went in 2026-07, v21 by 2026-11. GOOGLE_ADS_API_VERSION overrides it without a release.
     */
    private const DEFAULT_API_VERSION = 'v26';

    private const OAUTH_URL = 'https://oauth2.googleapis.com/token';

    private const API_BASE = 'https://googleads.googleapis.com';

    /** Google Ads reports money in micros (millionths of the account currency). */
    private const MICROS = 1_000_000;

    /** Campaign rows kept per period. The editor can only filter over these. */
    private const DATASET_ROW_LIMIT = 500;

    public function key(): string
    {
        return DataSourceType::GoogleAds->value;
    }

    public function label(): string
    {
        return DataSourceType::GoogleAds->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('customer_id', 'Customer ID', ConfigFieldType::Text, help: 'ID de la cuenta de Google Ads, solo dígitos y sin guiones (p. ej. 1234567890).'),
            new ConfigField('login_customer_id', 'Login Customer ID (MCC)', ConfigFieldType::Text, required: false, help: 'Solo si accedes a través de una cuenta administrador (MCC): su ID sin guiones. Déjalo vacío si no aplica.'),
            new ConfigField('developer_token', 'Developer token', ConfigFieldType::Password, secret: true, help: 'Token de desarrollador de tu cuenta de API de Google Ads (API Center).'),
            new ConfigField('client_id', 'OAuth client ID', ConfigFieldType::Text, help: 'ID de cliente OAuth 2.0 (Google Cloud → APIs y servicios → Credenciales).'),
            new ConfigField('client_secret', 'OAuth client secret', ConfigFieldType::Password, secret: true, help: 'Secreto del cliente OAuth 2.0.'),
            new ConfigField('refresh_token', 'OAuth refresh token', ConfigFieldType::Password, secret: true, help: 'Refresh token generado para el scope https://www.googleapis.com/auth/adwords.'),
        ];
    }

    /**
     * The campaign dataset's shape. Measures are **additive only** — the DatasetEngine sums
     * them across the rows a block keeps, and summing CTR or average CPC yields a number
     * that looks plausible and is wrong. Those stay account-level scalars.
     *
     * @return array{dimensions: array<string, string>, measures: array<string, array{label: string, unit: string}>}
     */
    private function campaignDataset(): array
    {
        return [
            'dimensions' => [
                'campaign' => 'Campaña',
                'channel' => 'Canal (Búsqueda / Display / YouTube…)',
            ],
            'measures' => [
                'cost' => ['label' => 'Inversión', 'unit' => 'currency'],
                'impressions' => ['label' => 'Impresiones', 'unit' => 'count'],
                'clicks' => ['label' => 'Clics', 'unit' => 'count'],
                'conversions' => ['label' => 'Conversiones', 'unit' => 'count'],
            ],
        ];
    }

    /**
     * Campaign × device. A separate dataset rather than another segment on the campaign one:
     * every extra segment multiplies the rows Google returns, and most blocks only care
     * about one axis.
     *
     * @return array{dimensions: array<string, string>, measures: array<string, array{label: string, unit: string}>}
     */
    private function deviceDataset(): array
    {
        return [
            'dimensions' => [
                'campaign' => 'Campaña',
                'device' => 'Dispositivo',
            ],
            'measures' => $this->campaignDataset()['measures'],
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        $dataset = $this->campaignDataset();
        $measures = [];
        foreach ($dataset['measures'] as $key => $measure) {
            $measures[] = ['key' => $key, 'label' => $measure['label'], 'unit' => $measure['unit']];
        }

        return new MetricCatalog(
            new MetricDefinition(
                'google_ads.campaigns',
                'Campañas (por campaña y canal)',
                MetricType::Dataset,
                null,
                array_keys($dataset['dimensions']),
                'Inversión, impresiones, clics y conversiones de cada campaña; puedes agruparlas por campaña o por canal.',
                $measures,
                $dataset['dimensions'],
            ),
            new MetricDefinition(
                'google_ads.by_device',
                'Anuncios por dispositivo',
                MetricType::Dataset,
                null,
                array_keys($this->deviceDataset()['dimensions']),
                'Inversión, impresiones, clics y conversiones según el dispositivo (celular, computador, tableta).',
                $measures,
                $this->deviceDataset()['dimensions'],
            ),
            new MetricDefinition('google_ads.impressions', 'Impresiones', MetricType::Scalar, 'count', description: 'Veces que se mostraron tus anuncios de Google. Más impresiones, más personas los vieron.'),
            new MetricDefinition('google_ads.clicks', 'Clics', MetricType::Scalar, 'count', description: 'Veces que alguien hizo clic en tus anuncios de Google para ir a tu sitio.'),
            new MetricDefinition('google_ads.cost', 'Inversión', MetricType::Scalar, 'currency', description: 'Lo que se invirtió en Google Ads durante el periodo.'),
            new MetricDefinition('google_ads.conversions', 'Conversiones', MetricType::Scalar, 'count', description: 'Acciones valiosas logradas con los anuncios (compras, formularios, llamadas…), según las conversiones configuradas en la cuenta.'),
            new MetricDefinition('google_ads.conversions_value', 'Valor de las conversiones', MetricType::Scalar, 'currency', description: 'Valor económico de esas conversiones (por ejemplo, el importe de las ventas), según Google Ads.'),
            new MetricDefinition('google_ads.ctr', 'Porcentaje de clics (CTR)', MetricType::Scalar, 'percent', description: 'De cada 100 veces que se mostró un anuncio, cuántas recibieron un clic. Más alto es mejor.'),
            new MetricDefinition('google_ads.avg_cpc', 'Costo medio por clic (CPC)', MetricType::Scalar, 'currency', description: 'Lo que costó en promedio cada clic en los anuncios. Más bajo es mejor.'),
            new MetricDefinition('google_ads.clicks_by_date', 'Clics por día', MetricType::Series, 'count', description: 'Clics en tus anuncios de Google, día a día.'),
            new MetricDefinition('google_ads.impressions_by_date', 'Impresiones por día', MetricType::Series, 'count', description: 'Veces que se mostraron tus anuncios de Google, día a día.'),
            new MetricDefinition('google_ads.top_campaigns', 'Campañas con más inversión', MetricType::Table, description: 'Las 10 campañas con más inversión del periodo, con sus clics e impresiones.'),
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            if ($this->toStr(Arr::get($source->config ?? [], 'customer_id')) === '') {
                return ConnectionResult::failure('Falta la cuenta de Google Ads (Customer ID). Elígela en el desplegable tras conectar, o escríbela (solo dígitos, sin guiones).');
            }

            $token = $this->accessToken($source);
            if ($token === null) {
                return ConnectionResult::failure('No se pudo obtener el token de acceso de Google (revisa client_id, client_secret y refresh_token).');
            }

            $response = $this->apiClient($source, $token)->post($this->searchUrl($source), [
                'query' => 'SELECT customer.id FROM customer LIMIT 1',
            ]);

            return $response->successful()
                ? ConnectionResult::success('Google Ads reachable.')
                : ConnectionResult::failure($this->retiredVersion($response) ?? 'Google Ads respondió HTTP '.$response->status().' '.$this->apiError($response->json()));
        } catch (Throwable $e) {
            return ConnectionResult::failure('No se pudo conectar con Google Ads: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        try {
            $token = $this->accessToken($source);
            if ($token === null) {
                return MetricSet::failed('Google Ads: no se pudo obtener el token de acceso (OAuth).');
            }

            $client = $this->apiClient($source, $token);
            $url = $this->searchUrl($source);
            $range = "segments.date BETWEEN '".$period->start->toDateString()."' AND '".$period->end->toDateString()."'";

            $totals = $client->post($url, [
                'query' => "SELECT metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value, metrics.ctr, metrics.average_cpc FROM customer WHERE {$range}",
            ]);

            if ($totals->failed()) {
                return MetricSet::failed($this->retiredVersion($totals) ?? 'Google Ads request failed: HTTP '.$totals->status().' '.$this->apiError($totals->json()));
            }

            $m = $this->arrayOf(Arr::get($this->listOf($totals->json('results'))[0] ?? [], 'metrics'));

            $metrics = [
                'google_ads.impressions' => $this->toInt(Arr::get($m, 'impressions')),
                'google_ads.clicks' => $this->toInt(Arr::get($m, 'clicks')),
                'google_ads.cost' => $this->toFloat(Arr::get($m, 'costMicros')) / self::MICROS,
                'google_ads.conversions' => $this->toFloat(Arr::get($m, 'conversions')),
                'google_ads.conversions_value' => $this->toFloat(Arr::get($m, 'conversionsValue')),
                'google_ads.ctr' => $this->toFloat(Arr::get($m, 'ctr')) * 100,
                'google_ads.avg_cpc' => $this->toFloat(Arr::get($m, 'averageCpc')) / self::MICROS,
            ];

            $errors = [];
            $this->collectSeries($client, $url, $range, $metrics, $errors);
            $this->collectTopCampaigns($client, $url, $range, $metrics, $errors);
            $this->collectCampaignDataset($client, $url, $range, $metrics, $errors);
            $this->collectDeviceDataset($client, $url, $range, $metrics, $errors);

            return $errors === [] ? MetricSet::ok($metrics) : MetricSet::partial($metrics, implode('; ', $errors));
        } catch (Throwable $e) {
            return MetricSet::failed('Google Ads request error: '.$e->getMessage());
        }
    }

    /**
     * Per-day clicks + impressions series (one GAQL row per date).
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectSeries(PendingRequest $client, string $url, string $range, array &$metrics, array &$errors): void
    {
        try {
            $response = $client->post($url, [
                'query' => "SELECT segments.date, metrics.clicks, metrics.impressions FROM customer WHERE {$range} ORDER BY segments.date",
            ]);

            if ($response->failed()) {
                $errors[] = 'series: HTTP '.$response->status();

                return;
            }

            $rows = $this->listOf($response->json('results'));
            $metrics['google_ads.clicks_by_date'] = array_map(fn (array $row): array => [
                'date' => $this->toStr(Arr::get($row, 'segments.date')),
                'value' => $this->toFloat(Arr::get($row, 'metrics.clicks')),
            ], $rows);
            $metrics['google_ads.impressions_by_date'] = array_map(fn (array $row): array => [
                'date' => $this->toStr(Arr::get($row, 'segments.date')),
                'value' => $this->toFloat(Arr::get($row, 'metrics.impressions')),
            ], $rows);
        } catch (Throwable $e) {
            $errors[] = 'series: '.$e->getMessage();
        }
    }

    /**
     * Top campaigns by cost (bounded top-N, aggregated at source).
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectTopCampaigns(PendingRequest $client, string $url, string $range, array &$metrics, array &$errors): void
    {
        try {
            $response = $client->post($url, [
                'query' => "SELECT campaign.name, metrics.cost_micros, metrics.clicks, metrics.impressions FROM campaign WHERE {$range} ORDER BY metrics.cost_micros DESC LIMIT 10",
            ]);

            if ($response->failed()) {
                $errors[] = 'campaigns: HTTP '.$response->status();

                return;
            }

            $metrics['google_ads.top_campaigns'] = array_map(fn (array $row): array => [
                'campaign' => $this->toStr(Arr::get($row, 'campaign.name')),
                'cost' => $this->toFloat(Arr::get($row, 'metrics.costMicros')) / self::MICROS,
                'clicks' => $this->toInt(Arr::get($row, 'metrics.clicks')),
                'impressions' => $this->toInt(Arr::get($row, 'metrics.impressions')),
            ], $this->listOf($response->json('results')));
        } catch (Throwable $e) {
            $errors[] = 'campaigns: '.$e->getMessage();
        }
    }

    /**
     * Campaign × channel rows for the modelable dataset, so a block can be bound to a
     * specific set of campaigns from the editor — no per-client metric, no re-sync when the
     * selection changes. Aggregated at the source and bounded (§3.3); the cap is generous
     * because the editor can only filter over what the snapshot holds.
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectCampaignDataset(PendingRequest $client, string $url, string $range, array &$metrics, array &$errors): void
    {
        try {
            $response = $client->post($url, [
                'query' => 'SELECT campaign.name, campaign.advertising_channel_type, metrics.cost_micros, metrics.clicks, '
                    ."metrics.impressions, metrics.conversions FROM campaign WHERE {$range} "
                    .'ORDER BY metrics.cost_micros DESC LIMIT '.self::DATASET_ROW_LIMIT,
            ]);

            if ($response->failed()) {
                $errors[] = 'campaign dataset: HTTP '.$response->status();

                return;
            }

            $metrics['google_ads.campaigns'] = array_map(fn (array $row): array => [
                'campaign' => $this->toStr(Arr::get($row, 'campaign.name')),
                'channel' => $this->toStr(Arr::get($row, 'campaign.advertisingChannelType')),
                'cost' => $this->toFloat(Arr::get($row, 'metrics.costMicros')) / self::MICROS,
                'impressions' => $this->toInt(Arr::get($row, 'metrics.impressions')),
                'clicks' => $this->toInt(Arr::get($row, 'metrics.clicks')),
                'conversions' => $this->toFloat(Arr::get($row, 'metrics.conversions')),
            ], $this->listOf($response->json('results')));
        } catch (Throwable $e) {
            $errors[] = 'campaign dataset: '.$e->getMessage();
        }
    }

    /**
     * Campaign × device rows, so a block can be scoped to mobile, desktop or tablet.
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectDeviceDataset(PendingRequest $client, string $url, string $range, array &$metrics, array &$errors): void
    {
        try {
            $response = $client->post($url, [
                'query' => 'SELECT campaign.name, segments.device, metrics.cost_micros, metrics.clicks, '
                    ."metrics.impressions, metrics.conversions FROM campaign WHERE {$range} "
                    .'ORDER BY metrics.cost_micros DESC LIMIT '.self::DATASET_ROW_LIMIT,
            ]);

            if ($response->failed()) {
                $errors[] = 'device dataset: HTTP '.$response->status();

                return;
            }

            $metrics['google_ads.by_device'] = array_map(fn (array $row): array => [
                'campaign' => $this->toStr(Arr::get($row, 'campaign.name')),
                'device' => $this->toStr(Arr::get($row, 'segments.device')),
                'cost' => $this->toFloat(Arr::get($row, 'metrics.costMicros')) / self::MICROS,
                'impressions' => $this->toInt(Arr::get($row, 'metrics.impressions')),
                'clicks' => $this->toInt(Arr::get($row, 'metrics.clicks')),
                'conversions' => $this->toFloat(Arr::get($row, 'metrics.conversions')),
            ], $this->listOf($response->json('results')));
        } catch (Throwable $e) {
            $errors[] = 'device dataset: '.$e->getMessage();
        }
    }

    /** Exchange the OAuth refresh token for a short-lived access token; null on failure. */
    private function accessToken(DataSource $source): ?string
    {
        $response = Http::asForm()->timeout(20)->post(self::OAUTH_URL, [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId($source),
            'client_secret' => $this->clientSecret($source),
            'refresh_token' => $this->refreshToken($source),
        ]);

        if ($response->failed()) {
            return null;
        }

        $token = $response->json('access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function apiClient(DataSource $source, string $token): PendingRequest
    {
        $headers = ['developer-token' => $this->developerToken($source)];

        $login = $this->loginCustomerId($source);
        if ($login !== '') {
            $headers['login-customer-id'] = $login;
        }

        return Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(30);
    }

    /**
     * OAuth/account settings resolve from the source (the manual form) first, falling back to
     * the platform Google OAuth app (services.google_oauth) — so a one-click "Connect with
     * Google" source, which stores only a refresh token, still authenticates.
     */
    private function refreshToken(DataSource $source): string
    {
        $credentials = $source->credentials ?? [];

        return $this->toStr(Arr::get($credentials, 'refresh_token'))
            ?: $this->toStr(Arr::get($credentials, 'oauth_refresh_token'));
    }

    private function clientId(DataSource $source): string
    {
        return $this->toStr(Arr::get($source->config ?? [], 'client_id')) ?: $this->platform('client_id');
    }

    private function clientSecret(DataSource $source): string
    {
        return $this->toStr(Arr::get($source->credentials ?? [], 'client_secret')) ?: $this->platform('client_secret');
    }

    private function developerToken(DataSource $source): string
    {
        return $this->toStr(Arr::get($source->credentials ?? [], 'developer_token')) ?: $this->platform('ads_developer_token');
    }

    private function loginCustomerId(DataSource $source): string
    {
        return $this->toStr(Arr::get($source->config ?? [], 'login_customer_id')) ?: $this->platform('ads_login_customer_id');
    }

    private function platform(string $key): string
    {
        $credentials = new OAuthCredentials;

        return match ($key) {
            'client_id' => $credentials->googleClientId(),
            'client_secret' => $credentials->googleClientSecret(),
            'ads_developer_token' => $credentials->googleAdsDeveloperToken(),
            'ads_login_customer_id' => $credentials->googleAdsLoginCustomerId(),
            default => '',
        };
    }

    /**
     * The Google Ads accounts this connected login can reach (`listAccessibleCustomers`),
     * so the client picks the account after the one-click connect. Best-effort — null on error.
     */
    public function connectableResources(DataSource $source): ?ConnectableResources
    {
        if ($this->refreshToken($source) === '') {
            return null;
        }

        $token = $this->accessToken($source);
        if ($token === null) {
            throw DiscoveryFailed::because('Google no aceptó el permiso guardado (token caducado o revocado). Reconecta la fuente.');
        }

        $response = $this->apiClient($source, $token)
            ->get(self::API_BASE.'/'.$this->apiVersion().'/customers:listAccessibleCustomers');

        if ($response->failed()) {
            $retired = $this->retiredVersion($response);
            throw $retired !== null ? DiscoveryFailed::because($retired) : $this->discoveryFailed('Google Ads', $response);
        }

        $names = $response->json('resourceNames');
        $ids = [];
        foreach (is_array($names) ? $names : [] as $name) {
            if (! is_string($name)) {
                continue;
            }
            $id = str_starts_with($name, 'customers/') ? substr($name, 10) : $name;
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        $labels = $this->accountNames($source, $token, $ids);

        $options = [];
        foreach ($ids as $id) {
            $formatted = $this->formatCustomerId($id);
            $accountName = $labels[$id] ?? '';
            $options[] = ['value' => $id, 'label' => $accountName !== '' ? "{$accountName} ({$formatted})" : $formatted];
        }

        return new ConnectableResources(
            'customer_id',
            'Cuenta de Google Ads',
            $options,
            'La conexión funcionó, pero esta cuenta de Google no administra ninguna cuenta de Google Ads. '
            .'Conéctate con el usuario de Google que tiene acceso a la cuenta publicitaria y pulsa «Detectar cuentas».',
        );
    }

    /** Accounts whose name is looked up one by one (in parallel) after the MCC query. */
    private const NAME_LOOKUP_LIMIT = 40;

    /**
     * Descriptive names for the accessible accounts. Best-effort: an account whose name
     * can't be read just shows its id.
     *
     * It used to query only the hierarchy under the FIRST accessible account, so separate
     * accounts (not under one MCC) — or a first account that refused the query — left every
     * option as a bare id. Now: the configured MCC's whole hierarchy in one query, then each
     * remaining account asks for its own name, as itself.
     *
     * @param  list<string>  $ids
     * @return array<string, string> customer id => descriptive name
     */
    private function accountNames(DataSource $source, string $token, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $labels = [];
        $manager = $this->loginCustomerId($source);
        if ($manager !== '') {
            $labels = $this->namesFrom(
                $this->apiClient($source, $token),
                $manager,
                'SELECT customer_client.id, customer_client.descriptive_name FROM customer_client',
                'customerClient',
            );
        }

        $missing = array_slice(array_values(array_filter($ids, static fn (string $id): bool => ! isset($labels[$id]))), 0, self::NAME_LOOKUP_LIMIT);
        if ($missing === []) {
            return $labels;
        }

        try {
            $developerToken = $this->developerToken($source);
            $responses = Http::pool(function (Pool $pool) use ($missing, $token, $developerToken): array {
                $requests = [];
                foreach ($missing as $id) {
                    // Each account asks about itself, so no manager header is needed (or wrong).
                    $requests[] = $pool->as($id)
                        ->withToken($token)
                        ->withHeaders(['developer-token' => $developerToken, 'login-customer-id' => $id])
                        ->acceptJson()
                        ->timeout(20)
                        ->post(self::API_BASE.'/'.$this->apiVersion().'/customers/'.$id.'/googleAds:search', [
                            'query' => 'SELECT customer.id, customer.descriptive_name FROM customer LIMIT 1',
                        ]);
                }

                return $requests;
            });
        } catch (Throwable) {
            return $labels;
        }

        foreach ($missing as $id) {
            $response = $responses[$id] ?? null;
            if (! $response instanceof Response || $response->failed()) {
                continue;
            }
            $name = $this->toStr(Arr::get($this->listOf($response->json('results'))[0] ?? [], 'customer.descriptiveName'));
            if ($name !== '') {
                $labels[$id] = $name;
            }
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    private function namesFrom(PendingRequest $client, string $customerId, string $query, string $resource): array
    {
        try {
            $response = $client->post(self::API_BASE.'/'.$this->apiVersion().'/customers/'.$customerId.'/googleAds:search', ['query' => $query]);
            if ($response->failed()) {
                return [];
            }

            $labels = [];
            foreach ($this->listOf($response->json('results')) as $row) {
                $id = $this->toStr(Arr::get($row, "{$resource}.id"));
                $name = $this->toStr(Arr::get($row, "{$resource}.descriptiveName"));
                if ($id !== '' && $name !== '') {
                    $labels[$id] = $name;
                }
            }

            return $labels;
        } catch (Throwable) {
            return [];
        }
    }

    /** 1234567890 → 123-456-7890 for display (Google's canonical format). */
    private function formatCustomerId(string $id): string
    {
        return strlen($id) === 10 ? substr($id, 0, 3).'-'.substr($id, 3, 3).'-'.substr($id, 6) : $id;
    }

    private function searchUrl(DataSource $source): string
    {
        // The REST search method is GoogleAdsService.Search → customers/{id}/googleAds:search
        // (the '/googleAds' segment is required; without it the API returns HTTP 404).
        $customer = $this->toStr(Arr::get($source->config ?? [], 'customer_id'));

        return self::API_BASE.'/'.$this->apiVersion().'/customers/'.$customer.'/googleAds:search';
    }

    private function apiVersion(): string
    {
        $configured = config('services.google_oauth.ads_api_version');

        return is_string($configured) && preg_match('/^v\d+$/', $configured) === 1 ? $configured : self::DEFAULT_API_VERSION;
    }

    /**
     * A 404 whose body isn't a Google Ads JSON error means the API version itself is gone —
     * say that, instead of a bare "HTTP 404" that reads like a wrong account or a broken app.
     */
    private function retiredVersion(Response $response): ?string
    {
        if ($response->status() !== 404 || is_array($response->json('error'))) {
            return null;
        }

        return 'Google Ads ya no reconoce la versión '.$this->apiVersion().' de su API (Google la retiró). '
            .'Actualiza Imagina Reports a la última versión, o define GOOGLE_ADS_API_VERSION con una versión vigente.';
    }

    private function apiError(mixed $json): string
    {
        $message = is_array($json) ? Arr::get($json, 'error.message') : null;

        return is_string($message) ? $message : '';
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'Conecta Google Ads con OAuth 2.0 + un developer token.',
            [
                'Solicita un developer token en tu cuenta de API de Google Ads (Herramientas → Configuración → API Center).',
                'En Google Cloud crea credenciales OAuth 2.0 (tipo «Aplicación de escritorio») → copia client_id y client_secret.',
                'Genera un refresh_token autorizando el scope https://www.googleapis.com/auth/adwords con esas credenciales.',
                'Pon el Customer ID de la cuenta (solo dígitos). Si accedes vía un MCC, añade su ID en «Login Customer ID».',
                'Guarda y pulsa «Probar conexión».',
            ],
            'https://developers.google.com/google-ads/api/docs/get-started/introduction',
        );
    }
}
