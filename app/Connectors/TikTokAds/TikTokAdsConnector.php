<?php

declare(strict_types=1);

namespace App\Connectors\TikTokAds;

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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * TikTok Ads connector (CLAUDE.md §9). Reads ad performance (spend, impressions, clicks,
 * conversions, CTR, CPC) from the TikTok Business API's integrated report endpoint, which
 * aggregates server-side over the period (§3.3). Auth is a long-lived access token + the
 * advertiser id. Its `spend/conversions/clicks/impressions` feed the blended cross-source
 * marketing metrics (ROAS, total ad spend…) automatically.
 *
 * The endpoint returns HTTP 200 even on an application error, signalled by a non-zero `code`
 * — handled here. Metric/dimension names are the documented API shape.
 */
final class TikTokAdsConnector implements DataSourceConnector, ProvidesSetupGuide
{
    use ParsesValues;

    private const API_BASE = 'https://business-api.tiktok.com/open_api/v1.3';

    /** @var list<string> */
    private const METRICS = ['spend', 'impressions', 'clicks', 'conversion', 'ctr', 'cpc'];

    public function key(): string
    {
        return DataSourceType::TikTokAds->value;
    }

    public function label(): string
    {
        return DataSourceType::TikTokAds->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('advertiser_id', 'Advertiser ID', ConfigFieldType::Text, help: 'ID del anunciante en TikTok Ads Manager (solo dígitos).'),
            new ConfigField('access_token', 'Access token', ConfigFieldType::Password, secret: true, help: 'Token de acceso de larga duración de tu app de TikTok for Business (Marketing API).'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('tiktok_ads.spend', 'Inversión', MetricType::Scalar, 'currency'),
            new MetricDefinition('tiktok_ads.impressions', 'Impresiones', MetricType::Scalar, 'count'),
            new MetricDefinition('tiktok_ads.clicks', 'Clics', MetricType::Scalar, 'count'),
            new MetricDefinition('tiktok_ads.conversions', 'Conversiones', MetricType::Scalar, 'count'),
            new MetricDefinition('tiktok_ads.ctr', 'CTR', MetricType::Scalar, 'percent'),
            new MetricDefinition('tiktok_ads.cpc', 'CPC', MetricType::Scalar, 'currency'),
            new MetricDefinition('tiktok_ads.spend_by_date', 'Inversión por día', MetricType::Series, 'currency'),
            new MetricDefinition('tiktok_ads.clicks_by_date', 'Clics por día', MetricType::Series, 'count'),
            new MetricDefinition('tiktok_ads.top_campaigns', 'Campañas principales', MetricType::Table),
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            $response = $this->report($source, 'AUCTION_ADVERTISER', ['advertiser_id'], Period::make('7 days ago', 'today'));

            return $this->ok($response)
                ? ConnectionResult::success('TikTok Ads reachable.')
                : ConnectionResult::failure('TikTok respondió: '.$this->apiError($response->json()));
        } catch (Throwable $e) {
            return ConnectionResult::failure('No se pudo conectar con TikTok Ads: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        try {
            $totals = $this->report($source, 'AUCTION_ADVERTISER', ['advertiser_id'], $period);

            if (! $this->ok($totals)) {
                return MetricSet::failed('TikTok Ads request failed: '.$this->apiError($totals->json()));
            }

            $m = $this->arrayOf(Arr::get($this->listOf(Arr::get($this->arrayOf($totals->json()), 'data.list'))[0] ?? [], 'metrics'));

            $metrics = [
                'tiktok_ads.spend' => $this->toFloat(Arr::get($m, 'spend')),
                'tiktok_ads.impressions' => $this->toInt(Arr::get($m, 'impressions')),
                'tiktok_ads.clicks' => $this->toInt(Arr::get($m, 'clicks')),
                'tiktok_ads.conversions' => $this->toFloat(Arr::get($m, 'conversion')),
                'tiktok_ads.ctr' => $this->toFloat(Arr::get($m, 'ctr')),
                'tiktok_ads.cpc' => $this->toFloat(Arr::get($m, 'cpc')),
            ];

            $errors = [];
            $this->collectSeries($source, $period, $metrics, $errors);
            $this->collectTopCampaigns($source, $period, $metrics, $errors);

            return $errors === [] ? MetricSet::ok($metrics) : MetricSet::partial($metrics, implode('; ', $errors));
        } catch (Throwable $e) {
            return MetricSet::failed('TikTok Ads request error: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectSeries(DataSource $source, Period $period, array &$metrics, array &$errors): void
    {
        try {
            $response = $this->report($source, 'AUCTION_ADVERTISER', ['stat_time_day'], $period);

            if (! $this->ok($response)) {
                $errors[] = 'series: '.$this->apiError($response->json());

                return;
            }

            $rows = $this->listOf(Arr::get($this->arrayOf($response->json()), 'data.list'));
            $metrics['tiktok_ads.spend_by_date'] = $this->series($rows, 'spend', 'float');
            $metrics['tiktok_ads.clicks_by_date'] = $this->series($rows, 'clicks', 'int');
        } catch (Throwable $e) {
            $errors[] = 'series: '.$e->getMessage();
        }
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectTopCampaigns(DataSource $source, Period $period, array &$metrics, array &$errors): void
    {
        try {
            $response = $this->report($source, 'AUCTION_CAMPAIGN', ['campaign_id'], $period, ['campaign_name']);

            if (! $this->ok($response)) {
                $errors[] = 'campaigns: '.$this->apiError($response->json());

                return;
            }

            $rows = array_map(fn (array $row): array => [
                'campaign' => $this->toStr(Arr::get($row, 'metrics.campaign_name')),
                'spend' => $this->toFloat(Arr::get($row, 'metrics.spend')),
                'clicks' => $this->toInt(Arr::get($row, 'metrics.clicks')),
                'impressions' => $this->toInt(Arr::get($row, 'metrics.impressions')),
            ], $this->listOf(Arr::get($this->arrayOf($response->json()), 'data.list')));

            usort($rows, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

            $metrics['tiktok_ads.top_campaigns'] = array_slice($rows, 0, 10);
        } catch (Throwable $e) {
            $errors[] = 'campaigns: '.$e->getMessage();
        }
    }

    /**
     * Run the integrated report at a data level with the given dimensions.
     *
     * @param  list<string>  $dimensions
     * @param  list<string>  $extraMetrics
     */
    private function report(DataSource $source, string $dataLevel, array $dimensions, Period $period, array $extraMetrics = []): Response
    {
        return $this->client($source)->get('/report/integrated/get/', [
            'advertiser_id' => $this->toStr(Arr::get($source->config ?? [], 'advertiser_id')),
            'report_type' => 'BASIC',
            'data_level' => $dataLevel,
            'dimensions' => (string) json_encode($dimensions),
            'metrics' => (string) json_encode([...self::METRICS, ...$extraMetrics]),
            'start_date' => $period->start->toDateString(),
            'end_date' => $period->end->toDateString(),
            'page_size' => 100,
        ]);
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     * @return list<array{date: string, value: int|float}>
     */
    private function series(array $rows, string $field, string $cast): array
    {
        return array_map(fn (array $row): array => [
            'date' => $this->toStr(Arr::get($row, 'dimensions.stat_time_day')),
            'value' => $cast === 'int' ? $this->toInt(Arr::get($row, "metrics.{$field}")) : $this->toFloat(Arr::get($row, "metrics.{$field}")),
        ], $rows);
    }

    private function client(DataSource $source): PendingRequest
    {
        return Http::baseUrl(self::API_BASE)
            ->withHeaders(['Access-Token' => $this->toStr(Arr::get($source->credentials ?? [], 'access_token'))])
            ->acceptJson()
            ->timeout(30);
    }

    /** TikTok signals success with HTTP 200 + `code == 0`; anything else is an error. */
    private function ok(Response $response): bool
    {
        return $response->successful() && $this->toInt(Arr::get($this->arrayOf($response->json()), 'code')) === 0;
    }

    private function apiError(mixed $json): string
    {
        $message = is_array($json) ? Arr::get($json, 'message') : null;

        return is_string($message) && $message !== '' ? $message : 'error desconocido';
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'Conecta TikTok Ads con un token de la Marketing API.',
            [
                'En TikTok for Business → TikTok Developers, crea una app con acceso a la Marketing API.',
                'Autoriza tu cuenta de anunciante y genera un access token de larga duración.',
                'Copia el Advertiser ID (solo dígitos) desde TikTok Ads Manager.',
                'Pega el token en «Access token», guarda y pulsa «Probar conexión».',
            ],
            'https://business-api.tiktok.com/portal/docs',
        );
    }
}
