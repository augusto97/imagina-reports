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
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\SetupGuide;
use App\Connectors\Support\ParsesValues;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use App\Services\Platform\OAuthCredentials;
use Illuminate\Http\Client\PendingRequest;
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
    use ParsesValues;

    /** Bump when moving to a newer Google Ads API version (endpoint path segment). */
    // Bump when Google sunsets a version (~14-month support window). An outdated version
    // makes EVERY endpoint return HTTP 404, including customers:listAccessibleCustomers.
    private const API_VERSION = 'v21';

    private const OAUTH_URL = 'https://oauth2.googleapis.com/token';

    private const API_BASE = 'https://googleads.googleapis.com';

    /** Google Ads reports money in micros (millionths of the account currency). */
    private const MICROS = 1_000_000;

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

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('google_ads.impressions', 'Impresiones', MetricType::Scalar, 'count'),
            new MetricDefinition('google_ads.clicks', 'Clics', MetricType::Scalar, 'count'),
            new MetricDefinition('google_ads.cost', 'Coste', MetricType::Scalar, 'currency'),
            new MetricDefinition('google_ads.conversions', 'Conversiones', MetricType::Scalar, 'count'),
            new MetricDefinition('google_ads.conversions_value', 'Valor de conversiones', MetricType::Scalar, 'currency'),
            new MetricDefinition('google_ads.ctr', 'CTR', MetricType::Scalar, 'percent'),
            new MetricDefinition('google_ads.avg_cpc', 'CPC medio', MetricType::Scalar, 'currency'),
            new MetricDefinition('google_ads.clicks_by_date', 'Clics por día', MetricType::Series, 'count'),
            new MetricDefinition('google_ads.impressions_by_date', 'Impresiones por día', MetricType::Series, 'count'),
            new MetricDefinition('google_ads.top_campaigns', 'Campañas principales', MetricType::Table),
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
                : ConnectionResult::failure('Google Ads respondió HTTP '.$response->status().' '.$this->apiError($response->json()));
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
                return MetricSet::failed('Google Ads request failed: HTTP '.$totals->status().' '.$this->apiError($totals->json()));
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
            return null;
        }

        $response = $this->apiClient($source, $token)
            ->get(self::API_BASE.'/'.self::API_VERSION.'/customers:listAccessibleCustomers');

        if ($response->failed()) {
            return null;
        }

        $names = $response->json('resourceNames');
        $options = [];
        foreach (is_array($names) ? $names : [] as $name) {
            if (! is_string($name)) {
                continue;
            }
            $id = str_starts_with($name, 'customers/') ? substr($name, 10) : $name;
            if ($id === '') {
                continue;
            }
            // Look up the account's descriptive name so the picker reads "Mi Cuenta
            // (123-456-7890)" instead of a bare id (best-effort; falls back to the id).
            $accountName = $this->descriptiveName($source, $token, $id);
            $formatted = $this->formatCustomerId($id);
            $options[] = ['value' => $id, 'label' => $accountName !== '' ? "{$accountName} ({$formatted})" : $formatted];
        }

        return new ConnectableResources('customer_id', 'Cuenta de Google Ads', $options);
    }

    /** The account's descriptive name via a tiny GAQL query; '' on any failure. */
    private function descriptiveName(DataSource $source, string $token, string $customerId): string
    {
        try {
            $response = $this->apiClient($source, $token)->post(
                self::API_BASE.'/'.self::API_VERSION.'/customers/'.$customerId.'/googleAds:search',
                ['query' => 'SELECT customer.descriptive_name FROM customer LIMIT 1'],
            );

            if ($response->failed()) {
                return '';
            }

            $row = $this->listOf($response->json('results'))[0] ?? [];

            return $this->toStr(Arr::get($row, 'customer.descriptiveName'));
        } catch (Throwable) {
            return '';
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

        return self::API_BASE.'/'.self::API_VERSION.'/customers/'.$customer.'/googleAds:search';
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
