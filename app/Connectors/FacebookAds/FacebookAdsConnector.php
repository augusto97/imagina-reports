<?php

declare(strict_types=1);

namespace App\Connectors\FacebookAds;

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
use App\Connectors\Support\DescribesApiErrors;
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
final class FacebookAdsConnector implements DataSourceConnector, ListsConnectableResources, ProvidesSetupGuide
{
    use DescribesApiErrors;
    use ParsesValues;

    /** Bump when moving to a newer Graph API version. */
    private const API_VERSION = 'v21.0';

    /** Campaign × placement rows kept per period. The editor can only filter over these. */
    private const DATASET_ROW_LIMIT = 500;

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

    /**
     * The modelable datasets, one per breakdown axis.
     *
     * Deliberately NOT one dataset with every breakdown at once: Meta multiplies rows per
     * breakdown (campaign × country × device × age would blow past any sane bound) and its
     * API rejects most combinations outright. One request per axis keeps each result small,
     * already aggregated at the source (§3.3), and lets a block filter on whichever axis it
     * cares about.
     *
     * Measures are **additive only** (spend, impressions, clicks, conversions): the
     * DatasetEngine sums a measure across the rows a block keeps, and summing a ratio like
     * CTR or CPC — or reach, which Meta de-duplicates per row — produces a number that looks
     * right and isn't. Those stay account-level scalars; derive ratios with a calculated
     * metric over these instead.
     *
     * @return array<string, array{
     *     label: string,
     *     breakdowns: string,
     *     dimensions: array<string, array{label: string, field: string}>,
     * }>
     */
    private function datasetSpecs(): array
    {
        return [
            'campaigns' => [
                'label' => 'Campañas (modelable)',
                // Instagram ads ARE Meta ads: same account, same Insights API, told apart by
                // the placement. This is what lets a block say "only Instagram".
                'breakdowns' => 'publisher_platform',
                'dimensions' => [
                    'campaign' => ['label' => 'Campaña', 'field' => 'campaign_name'],
                    'platform' => ['label' => 'Plataforma (Facebook / Instagram)', 'field' => 'publisher_platform'],
                ],
            ],
            'by_country' => [
                'label' => 'Anuncios por país (modelable)',
                'breakdowns' => 'country',
                'dimensions' => [
                    'campaign' => ['label' => 'Campaña', 'field' => 'campaign_name'],
                    'country' => ['label' => 'País', 'field' => 'country'],
                ],
            ],
            'by_device' => [
                'label' => 'Anuncios por dispositivo (modelable)',
                'breakdowns' => 'impression_device',
                'dimensions' => [
                    'campaign' => ['label' => 'Campaña', 'field' => 'campaign_name'],
                    'device' => ['label' => 'Dispositivo', 'field' => 'impression_device'],
                ],
            ],
            'by_demographics' => [
                'label' => 'Anuncios por edad y género (modelable)',
                // Meta allows age+gender together; most other pairs it refuses.
                'breakdowns' => 'age,gender',
                'dimensions' => [
                    'campaign' => ['label' => 'Campaña', 'field' => 'campaign_name'],
                    'age' => ['label' => 'Edad', 'field' => 'age'],
                    'gender' => ['label' => 'Género', 'field' => 'gender'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, unit: string}>
     */
    private function datasetMeasures(): array
    {
        return [
            'spend' => ['label' => 'Inversión', 'unit' => 'currency'],
            'impressions' => ['label' => 'Impresiones', 'unit' => 'count'],
            'clicks' => ['label' => 'Clics', 'unit' => 'count'],
            'conversions' => ['label' => 'Conversiones', 'unit' => 'count'],
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        $measures = [];
        foreach ($this->datasetMeasures() as $key => $measure) {
            $measures[] = ['key' => $key, 'label' => $measure['label'], 'unit' => $measure['unit']];
        }

        $catalog = new MetricCatalog(
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

        foreach ($this->datasetSpecs() as $key => $spec) {
            $labels = [];
            foreach ($spec['dimensions'] as $dimensionKey => $dimension) {
                $labels[$dimensionKey] = $dimension['label'];
            }

            $catalog = $catalog->with(new MetricDefinition(
                'facebook_ads.'.$key,
                $spec['label'],
                MetricType::Dataset,
                null,
                array_keys($spec['dimensions']),
                null,
                $measures,
                $labels,
            ));
        }

        return $catalog;
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
            $this->collectCampaignDataset($client, $insightsUrl, $period, $metrics, $errors);

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
     * Campaign × placement rows for the modelable dataset, so a block can be bound to a
     * specific set of campaigns, or to Instagram placements only, straight from the editor —
     * no per-client metric, no re-sync when the selection changes.
     *
     * Still aggregated at the source and bounded (§3.3): Insights returns one row per
     * campaign/platform pair, never per impression. The row cap is generous because the
     * editor can only filter over what the snapshot holds — a campaign outside it can't be
     * picked later.
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectCampaignDataset(PendingRequest $client, string $url, Period $period, array &$metrics, array &$errors): void
    {
        foreach ($this->datasetSpecs() as $key => $spec) {
            try {
                $response = $client->get($url, [
                    'level' => 'campaign',
                    'breakdowns' => $spec['breakdowns'],
                    'fields' => 'campaign_name,spend,clicks,impressions,actions',
                    'time_range' => $this->timeRange($period),
                    'limit' => self::DATASET_ROW_LIMIT,
                ]);

                if ($response->failed()) {
                    // One refused breakdown must not cost the others: skip and carry on.
                    $errors[] = $key.' dataset: HTTP '.$response->status();

                    continue;
                }

                $rows = array_map(function (array $row) use ($spec): array {
                    $entry = [];
                    foreach ($spec['dimensions'] as $dimensionKey => $dimension) {
                        $entry[$dimensionKey] = $this->toStr(Arr::get($row, $dimension['field']));
                    }
                    $entry['spend'] = $this->toFloat(Arr::get($row, 'spend'));
                    $entry['impressions'] = $this->toInt(Arr::get($row, 'impressions'));
                    $entry['clicks'] = $this->toInt(Arr::get($row, 'clicks'));
                    $entry['conversions'] = $this->sumActions(Arr::get($row, 'actions'));

                    return $entry;
                }, $this->listOf($response->json('data')));

                // Highest spend first, so a truncated snapshot keeps what matters.
                usort($rows, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

                $metrics['facebook_ads.'.$key] = $rows;
            } catch (Throwable $e) {
                $errors[] = $key.' dataset: '.$e->getMessage();
            }
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

    /**
     * The ad accounts this connected user can access (`/me/adaccounts`), so the client picks
     * the account after the one-click "Connect with Facebook". Best-effort — null on error.
     */
    public function connectableResources(DataSource $source): ?ConnectableResources
    {
        if ($this->toStr(Arr::get($source->credentials ?? [], 'access_token')) === '') {
            return null;
        }

        $client = $this->client($source);

        $response = $client->get('/me/adaccounts', ['fields' => 'name,account_id', 'limit' => 200]);
        if ($response->failed()) {
            throw $this->discoveryFailed('Meta', $response);
        }

        // Personal edge + every business portfolio's accounts (owned and shared with us):
        // agencies hold client ad accounts in a Business portfolio, and being an admin of the
        // business does not put those accounts on /me/adaccounts.
        $accounts = $this->listOf($response->json('data'));
        foreach ($this->businessIds($client) as $businessId) {
            foreach (['owned_ad_accounts', 'client_ad_accounts'] as $edge) {
                $accounts = array_merge($accounts, $this->adAccountsFrom($client, '/'.$businessId.'/'.$edge));
            }
        }

        $options = [];
        $seen = [];
        foreach ($accounts as $account) {
            $id = $this->toStr(Arr::get($account, 'account_id'));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $name = $this->toStr(Arr::get($account, 'name'));
            $options[] = ['value' => $id, 'label' => $name !== '' ? "{$name} ({$id})" : $id];
        }

        return new ConnectableResources(
            'ad_account_id',
            'Cuenta publicitaria de Meta',
            $options,
            'La conexión funcionó, pero esta cuenta de Facebook no administra ninguna cuenta publicitaria. '
            .'Revisa que tengas acceso a la cuenta en el Administrador Comercial de Meta y que la hayas marcado '
            .'en la pantalla de permisos, luego pulsa «Detectar cuentas».',
        );
    }

    /**
     * The business portfolios the token can see. A failure here (usually because
     * `business_management` wasn't granted) is skipped, not fatal — whatever the personal
     * edge found still gets offered.
     *
     * @return list<string>
     */
    private function businessIds(PendingRequest $client): array
    {
        try {
            $response = $client->get('/me/businesses', ['fields' => 'id', 'limit' => 100]);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $ids = [];
        foreach ($this->listOf($response->json('data')) as $business) {
            $id = $this->toStr(Arr::get($business, 'id'));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function adAccountsFrom(PendingRequest $client, string $path): array
    {
        try {
            $response = $client->get($path, ['fields' => 'name,account_id', 'limit' => 200]);
        } catch (Throwable) {
            return [];
        }

        return $response->failed() ? [] : $this->listOf($response->json('data'));
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
