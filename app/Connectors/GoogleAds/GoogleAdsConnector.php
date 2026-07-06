<?php

declare(strict_types=1);

namespace App\Connectors\GoogleAds;

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
final class GoogleAdsConnector implements DataSourceConnector, ProvidesSetupGuide
{
    use ParsesValues;

    /** Bump when moving to a newer Google Ads API version (endpoint path segment). */
    private const API_VERSION = 'v18';

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
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];

        $response = Http::asForm()->timeout(20)->post(self::OAUTH_URL, [
            'grant_type' => 'refresh_token',
            'client_id' => $this->toStr(Arr::get($config, 'client_id')),
            'client_secret' => $this->toStr(Arr::get($credentials, 'client_secret')),
            'refresh_token' => $this->toStr(Arr::get($credentials, 'refresh_token')),
        ]);

        if ($response->failed()) {
            return null;
        }

        $token = $response->json('access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function apiClient(DataSource $source, string $token): PendingRequest
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];

        $headers = ['developer-token' => $this->toStr(Arr::get($credentials, 'developer_token'))];

        $login = $this->toStr(Arr::get($config, 'login_customer_id'));
        if ($login !== '') {
            $headers['login-customer-id'] = $login;
        }

        return Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(30);
    }

    private function searchUrl(DataSource $source): string
    {
        $customer = $this->toStr(Arr::get($source->config ?? [], 'customer_id'));

        return self::API_BASE.'/'.self::API_VERSION.'/customers/'.$customer.':search';
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
