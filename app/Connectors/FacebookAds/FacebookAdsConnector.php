<?php

declare(strict_types=1);

namespace App\Connectors\FacebookAds;

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
 * Facebook / Meta Ads connector (CLAUDE.md §9). Reads ad performance (impressions, reach,
 * clicks, spend, CTR, CPC, conversions) from the Meta Marketing API's Insights endpoint,
 * which aggregates server-side over the period (§3.3) — the response is tiny regardless of
 * how much the account spends.
 *
 * Auth is a long-lived access token (a system-user token) + the ad account id. Spend/CTR/CPC
 * come back as strings and are coerced; conversions are summed from the `actions` breakdown
 * over a set of conversion action types (a documented assumption — accounts track different
 * events, so bind spend/clicks/impressions for exact figures).
 */
final class FacebookAdsConnector implements DataSourceConnector, ProvidesSetupGuide
{
    use ParsesValues;

    /** Bump when moving to a newer Graph API version. */
    private const API_VERSION = 'v21.0';

    private const API_BASE = 'https://graph.facebook.com';

    /** Action types counted as "conversions" (documented assumption; overlaps avoided). */
    private const CONVERSION_TYPES = ['purchase', 'lead', 'complete_registration'];

    public function key(): string
    {
        return DataSourceType::FacebookAds->value;
    }

    public function label(): string
    {
        return DataSourceType::FacebookAds->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('ad_account_id', 'Ad account ID', ConfigFieldType::Text, help: 'ID de la cuenta publicitaria, solo dígitos y sin el prefijo «act_» (p. ej. 1234567890).'),
            new ConfigField('access_token', 'Access token', ConfigFieldType::Password, secret: true, help: 'Token de acceso de la API de Marketing (preferiblemente de un usuario del sistema, de larga duración).'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('facebook_ads.impressions', 'Impresiones', MetricType::Scalar, 'count'),
            new MetricDefinition('facebook_ads.reach', 'Alcance', MetricType::Scalar, 'count'),
            new MetricDefinition('facebook_ads.clicks', 'Clics', MetricType::Scalar, 'count'),
            new MetricDefinition('facebook_ads.spend', 'Inversión', MetricType::Scalar, 'currency'),
            new MetricDefinition('facebook_ads.ctr', 'CTR', MetricType::Scalar, 'percent'),
            new MetricDefinition('facebook_ads.cpc', 'CPC', MetricType::Scalar, 'currency'),
            new MetricDefinition('facebook_ads.cpm', 'CPM', MetricType::Scalar, 'currency'),
            new MetricDefinition('facebook_ads.conversions', 'Conversiones', MetricType::Scalar, 'count'),
            new MetricDefinition('facebook_ads.conversions_value', 'Valor de conversiones', MetricType::Scalar, 'currency'),
            new MetricDefinition('facebook_ads.clicks_by_date', 'Clics por día', MetricType::Series, 'count'),
            new MetricDefinition('facebook_ads.impressions_by_date', 'Impresiones por día', MetricType::Series, 'count'),
            new MetricDefinition('facebook_ads.spend_by_date', 'Inversión por día', MetricType::Series, 'currency'),
            new MetricDefinition('facebook_ads.top_campaigns', 'Campañas principales', MetricType::Table),
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            $response = $this->client($source)->get($this->accountUrl($source), ['fields' => 'account_id']);

            return $response->successful()
                ? ConnectionResult::success('Meta Ads reachable.')
                : ConnectionResult::failure('Meta respondió HTTP '.$response->status().' '.$this->apiError($response->json()));
        } catch (Throwable $e) {
            return ConnectionResult::failure('No se pudo conectar con Meta Ads: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        try {
            $client = $this->client($source);
            $insightsUrl = $this->accountUrl($source).'/insights';

            $totals = $client->get($insightsUrl, [
                'fields' => 'impressions,reach,clicks,spend,ctr,cpc,cpm,actions,action_values',
                'time_range' => $this->timeRange($period),
            ]);

            if ($totals->failed()) {
                return MetricSet::failed('Meta Ads request failed: HTTP '.$totals->status().' '.$this->apiError($totals->json()));
            }

            $row = $this->listOf($totals->json('data'))[0] ?? [];

            $metrics = [
                'facebook_ads.impressions' => $this->toInt(Arr::get($row, 'impressions')),
                'facebook_ads.reach' => $this->toInt(Arr::get($row, 'reach')),
                'facebook_ads.clicks' => $this->toInt(Arr::get($row, 'clicks')),
                'facebook_ads.spend' => $this->toFloat(Arr::get($row, 'spend')),
                'facebook_ads.ctr' => $this->toFloat(Arr::get($row, 'ctr')),
                'facebook_ads.cpc' => $this->toFloat(Arr::get($row, 'cpc')),
                'facebook_ads.cpm' => $this->toFloat(Arr::get($row, 'cpm')),
                'facebook_ads.conversions' => $this->sumActions(Arr::get($row, 'actions')),
                'facebook_ads.conversions_value' => $this->sumActions(Arr::get($row, 'action_values')),
            ];

            $errors = [];
            $this->collectSeries($client, $insightsUrl, $period, $metrics, $errors);
            $this->collectTopCampaigns($client, $insightsUrl, $period, $metrics, $errors);

            return $errors === [] ? MetricSet::ok($metrics) : MetricSet::partial($metrics, implode('; ', $errors));
        } catch (Throwable $e) {
            return MetricSet::failed('Meta Ads request error: '.$e->getMessage());
        }
    }

    /**
     * Per-day clicks / impressions / spend series (Insights with time_increment=1).
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectSeries(PendingRequest $client, string $url, Period $period, array &$metrics, array &$errors): void
    {
        try {
            $response = $client->get($url, [
                'fields' => 'clicks,impressions,spend',
                'time_range' => $this->timeRange($period),
                'time_increment' => 1,
            ]);

            if ($response->failed()) {
                $errors[] = 'series: HTTP '.$response->status();

                return;
            }

            $rows = $this->listOf($response->json('data'));
            $metrics['facebook_ads.clicks_by_date'] = $this->series($rows, 'clicks', 'int');
            $metrics['facebook_ads.impressions_by_date'] = $this->series($rows, 'impressions', 'int');
            $metrics['facebook_ads.spend_by_date'] = $this->series($rows, 'spend', 'float');
        } catch (Throwable $e) {
            $errors[] = 'series: '.$e->getMessage();
        }
    }

    /**
     * Top campaigns by spend. Insights at campaign level returns one already-aggregated row
     * per campaign (a bounded set), so sorting/top-N in-app still respects §3.3.
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectTopCampaigns(PendingRequest $client, string $url, Period $period, array &$metrics, array &$errors): void
    {
        try {
            $response = $client->get($url, [
                'level' => 'campaign',
                'fields' => 'campaign_name,spend,clicks,impressions',
                'time_range' => $this->timeRange($period),
                'limit' => 200,
            ]);

            if ($response->failed()) {
                $errors[] = 'campaigns: HTTP '.$response->status();

                return;
            }

            $rows = array_map(fn (array $row): array => [
                'campaign' => $this->toStr(Arr::get($row, 'campaign_name')),
                'spend' => $this->toFloat(Arr::get($row, 'spend')),
                'clicks' => $this->toInt(Arr::get($row, 'clicks')),
                'impressions' => $this->toInt(Arr::get($row, 'impressions')),
            ], $this->listOf($response->json('data')));

            usort($rows, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

            $metrics['facebook_ads.top_campaigns'] = array_slice($rows, 0, 10);
        } catch (Throwable $e) {
            $errors[] = 'campaigns: '.$e->getMessage();
        }
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     * @return list<array{date: string, value: int|float}>
     */
    private function series(array $rows, string $field, string $cast): array
    {
        return array_map(fn (array $row): array => [
            'date' => $this->toStr(Arr::get($row, 'date_start')),
            'value' => $cast === 'int' ? $this->toInt(Arr::get($row, $field)) : $this->toFloat(Arr::get($row, $field)),
        ], $rows);
    }

    /** Sum the numeric `value` of the account's conversion actions (documented assumption). */
    private function sumActions(mixed $actions): float
    {
        $total = 0.0;

        foreach ($this->listOf($actions) as $action) {
            if (in_array($this->toStr(Arr::get($action, 'action_type')), self::CONVERSION_TYPES, true)) {
                $total += $this->toFloat(Arr::get($action, 'value'));
            }
        }

        return $total;
    }

    private function client(DataSource $source): PendingRequest
    {
        $token = $this->toStr(Arr::get($source->credentials ?? [], 'access_token'));

        return Http::baseUrl(self::API_BASE.'/'.self::API_VERSION)
            ->withToken($token)
            ->acceptJson()
            ->timeout(30);
    }

    private function accountUrl(DataSource $source): string
    {
        return '/act_'.$this->toStr(Arr::get($source->config ?? [], 'ad_account_id'));
    }

    /** Meta expects the date window as a JSON-encoded `{since, until}` query value. */
    private function timeRange(Period $period): string
    {
        return (string) json_encode([
            'since' => $period->start->toDateString(),
            'until' => $period->end->toDateString(),
        ]);
    }

    private function apiError(mixed $json): string
    {
        $message = is_array($json) ? Arr::get($json, 'error.message') : null;

        return is_string($message) ? $message : '';
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'Conecta Meta (Facebook) Ads con un token de la API de Marketing.',
            [
                'En Meta Business (business.facebook.com) crea o usa una app con acceso a la API de Marketing.',
                'Crea un «usuario del sistema» y asígnale la cuenta publicitaria con permiso de lectura.',
                'Genera un token de acceso de larga duración para ese usuario (scope ads_read).',
                'Copia el ID de la cuenta publicitaria (solo dígitos, sin «act_») en «Ad account ID».',
                'Pega el token en «Access token», guarda y pulsa «Probar conexión».',
            ],
            'https://developers.facebook.com/docs/marketing-api/insights',
        );
    }
}
