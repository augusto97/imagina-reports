<?php

declare(strict_types=1);

namespace App\Connectors\Ga4;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\Contracts\ProvidesSetupGuide;
use App\Connectors\Google\GoogleTokenProvider;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\SetupGuide;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Google Analytics 4 connector (CLAUDE.md §9). Authenticates with a service-account
 * JSON and reads sessions, users, conversions, traffic sources and top pages from
 * the Analytics Data API (`runReport`), which aggregates server-side by design
 * (§3.3). Returns a normalized `ga4.*` metric bag; catches its own errors (§7).
 */
final class Ga4Connector implements DataSourceConnector, ProvidesSetupGuide
{
    private const API_BASE = 'https://analyticsdata.googleapis.com/v1beta';

    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    public function __construct(private readonly GoogleTokenProvider $tokenProvider) {}

    public function key(): string
    {
        return DataSourceType::Ga4->value;
    }

    public function label(): string
    {
        return DataSourceType::Ga4->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('property_id', 'GA4 property ID', ConfigFieldType::Text, help: 'ID numérico de la propiedad GA4, p. ej. 123456789 (Administrar → Configuración de la propiedad).'),
            new ConfigField('service_account', 'Service account JSON', ConfigFieldType::Json, secret: true, help: 'Pega el JSON completo de una cuenta de servicio de Google Cloud. Añade su email como Lector en la propiedad GA4.'),
        ];
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'Conecta GA4 con una cuenta de servicio de Google Cloud (solo lectura). Tarda unos 5 minutos y sirve para todas las propiedades que compartas con esa cuenta.',
            [
                'En Google Cloud Console (console.cloud.google.com) crea o elige un proyecto.',
                'Habilita la «Google Analytics Data API»: APIs y servicios → Biblioteca → busca «Analytics Data API» → Habilitar.',
                'Crea una cuenta de servicio: IAM y administración → Cuentas de servicio → Crear cuenta de servicio. No necesita roles del proyecto.',
                'En esa cuenta, pestaña Claves → Agregar clave → Crear clave nueva → JSON. Se descarga un archivo .json: ese es el contenido que pegarás en «Service account JSON».',
                'Copia el email de la cuenta de servicio (termina en @…​.iam.gserviceaccount.com).',
                'En Google Analytics (GA4) → Administrar → Acceso a la propiedad → «+» → añade ese email con rol «Lector» (Viewer).',
                'Copia el ID de propiedad: Administrar → Configuración de la propiedad → «ID de la propiedad» (número de ~9 dígitos) y pégalo en «GA4 property ID».',
                'Pega el JSON de la cuenta de servicio, guarda y pulsa «Probar conexión».',
            ],
            'https://developers.google.com/analytics/devguides/reporting/data/v1/quickstart-client-libraries',
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        $propertyId = $this->propertyId($source);

        if ($propertyId === '') {
            return ConnectionResult::failure('GA4 property_id is not configured.');
        }

        try {
            $token = $this->tokenProvider->accessToken($this->serviceAccount($source), self::SCOPE);
        } catch (Throwable $e) {
            return ConnectionResult::failure('GA4 authentication failed: '.$e->getMessage());
        }

        if ($token === '') {
            return ConnectionResult::failure('GA4 authentication returned no access token.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->post(self::API_BASE."/properties/{$propertyId}:runReport", [
                'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
                'metrics' => [['name' => 'sessions']],
                'limit' => 1,
            ]);

        return $response->successful()
            ? ConnectionResult::success('GA4 property reachable.')
            : ConnectionResult::failure('GA4 responded with HTTP '.$response->status());
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        $definitions = [];

        foreach ($this->specs() as $key => $spec) {
            $definitions[] = new MetricDefinition($key, $spec['label'], $spec['type'], $spec['unit'], $spec['dimensions']);
        }

        // Datasets (multi-dimension, multi-measure) expose their filterable dimensions and
        // pickable measures so the editor can model blocks like Looker (§10 dashboards).
        // Includes user-built datasets from config (the self-serve builder, §10.6/A.3).
        foreach ($this->allDatasetSpecs($source) as $key => $spec) {
            $measures = [];
            foreach ($spec['measures'] as $measureKey => $measure) {
                $measures[] = ['key' => $measureKey, 'label' => $measure['label'], 'unit' => $measure['unit']];
            }

            $dimensionLabels = [];
            foreach ($spec['dimensions'] as $dimensionKey => $dimension) {
                $dimensionLabels[$dimensionKey] = $dimension['label'];
            }

            $definitions[] = new MetricDefinition(
                $key,
                $spec['label'],
                MetricType::Dataset,
                null,
                array_keys($spec['dimensions']),
                null,
                $measures,
                $dimensionLabels,
            );
        }

        return new MetricCatalog(...$definitions);
    }

    /**
     * GA4 property metadata (the self-serve builder's dictionary, §10.6/A.3): the
     * dimensions and metrics THIS property supports — including its own custom
     * definitions — so the builder offers exactly what's valid, no per-metric code.
     *
     * @return array{dimensions: list<array{api: string, label: string, category: string, custom: bool}>, metrics: list<array{api: string, label: string, category: string, type: string, custom: bool}>}
     */
    public function metadata(DataSource $source): array
    {
        $propertyId = $this->propertyId($source);
        if ($propertyId === '') {
            throw new RuntimeException('GA4 property_id is not configured.');
        }

        $token = $this->tokenProvider->accessToken($this->serviceAccount($source), self::SCOPE);
        if ($token === '') {
            throw new RuntimeException('GA4 authentication returned no access token.');
        }

        $response = Http::withToken($token)->acceptJson()->get(self::API_BASE."/properties/{$propertyId}/metadata");
        if ($response->failed()) {
            throw new RuntimeException('GA4 metadata: HTTP '.$response->status());
        }

        $json = $response->json();

        $dimensions = [];
        foreach (is_array($json) && is_array($json['dimensions'] ?? null) ? $json['dimensions'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $api = is_string($entry['apiName'] ?? null) ? $entry['apiName'] : '';
            if ($api === '') {
                continue;
            }
            $dimensions[] = [
                'api' => $api,
                'label' => is_string($entry['uiName'] ?? null) && $entry['uiName'] !== '' ? $entry['uiName'] : $api,
                'category' => is_string($entry['category'] ?? null) ? $entry['category'] : '',
                'custom' => ($entry['customDefinition'] ?? false) === true,
            ];
        }

        $metrics = [];
        foreach (is_array($json) && is_array($json['metrics'] ?? null) ? $json['metrics'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $api = is_string($entry['apiName'] ?? null) ? $entry['apiName'] : '';
            if ($api === '') {
                continue;
            }
            $metrics[] = [
                'api' => $api,
                'label' => is_string($entry['uiName'] ?? null) && $entry['uiName'] !== '' ? $entry['uiName'] : $api,
                'category' => is_string($entry['category'] ?? null) ? $entry['category'] : '',
                'type' => is_string($entry['type'] ?? null) ? $entry['type'] : '',
                'custom' => ($entry['customDefinition'] ?? false) === true,
            ];
        }

        return ['dimensions' => $dimensions, 'metrics' => $metrics];
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        $propertyId = $this->propertyId($source);

        if ($propertyId === '') {
            return MetricSet::failed('GA4 property_id is not configured.');
        }

        try {
            $token = $this->tokenProvider->accessToken($this->serviceAccount($source), self::SCOPE);
        } catch (Throwable $e) {
            return MetricSet::failed('GA4 authentication failed: '.$e->getMessage());
        }

        if ($token === '') {
            return MetricSet::failed('GA4 authentication returned no access token.');
        }

        $specs = $this->specs();
        $datasets = $this->allDatasetSpecs($source);
        $allKeys = array_merge(array_keys($specs), array_keys($datasets));
        $keys = $requestedMetrics === [] ? $allKeys : array_values(array_intersect($requestedMetrics, $allKeys));

        $metrics = [];
        $errors = [];

        foreach ($keys as $key) {
            try {
                $isDataset = isset($datasets[$key]);
                $body = $isDataset ? $this->datasetBody($datasets[$key], $period) : $this->body($specs[$key], $period);

                $response = Http::withToken($token)
                    ->acceptJson()
                    ->post(self::API_BASE."/properties/{$propertyId}:runReport", $body);

                if ($response->failed()) {
                    $errors[] = "{$key}: HTTP ".$response->status();

                    continue;
                }

                $metrics[$key] = $isDataset
                    ? $this->parseDataset($datasets[$key], $response->json())
                    : $this->parse($specs[$key], $response->json());
            } catch (Throwable $e) {
                $errors[] = "{$key}: ".$e->getMessage();
            }
        }

        if ($metrics === [] && $errors !== []) {
            return MetricSet::failed(implode('; ', $errors));
        }

        return $errors === []
            ? MetricSet::ok($metrics)
            : MetricSet::partial($metrics, implode('; ', $errors));
    }

    private function propertyId(DataSource $source): string
    {
        $value = Arr::get($source->config ?? [], 'property_id', '');

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The decoded service-account JSON. The admin form stores the pasted JSON as a
     * string under the `service_account` credential, so decode it; we also accept the
     * JSON fields stored directly on the credentials bag (already-decoded form).
     *
     * @return array<array-key, mixed>
     */
    private function serviceAccount(DataSource $source): array
    {
        $credentials = $source->credentials ?? [];
        $raw = $credentials['service_account'] ?? $credentials;

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * The GA4 metrics this connector exposes, mapped to Analytics Data API names. Covers
     * both general/content analytics and the GA4 ecommerce set (revenue, transactions,
     * purchases…). Ecommerce metrics simply return 0/empty on non-store properties, so the
     * bound blocks hide gracefully (§10.4). `cast` controls int vs. 2-decimal output and
     * `scale` turns GA4's 0–1 ratios into 0–100 percentages.
     *
     * @return array<string, array{label: string, type: MetricType, unit: string|null, metrics: list<string>, dimensions: list<string>, limit: int, cast: 'int'|'float', scale: int}>
     */
    private function specs(): array
    {
        return [
            // ---- Audience & engagement (content sites) ----
            'ga4.sessions' => ['label' => 'Sesiones', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['sessions'], 'dimensions' => [], 'limit' => 1, 'cast' => 'int', 'scale' => 1],
            'ga4.users' => ['label' => 'Usuarios', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['totalUsers'], 'dimensions' => [], 'limit' => 1, 'cast' => 'int', 'scale' => 1],
            'ga4.new_users' => ['label' => 'Usuarios nuevos', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['newUsers'], 'dimensions' => [], 'limit' => 1, 'cast' => 'int', 'scale' => 1],
            'ga4.active_users' => ['label' => 'Usuarios activos', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['activeUsers'], 'dimensions' => [], 'limit' => 1, 'cast' => 'int', 'scale' => 1],
            'ga4.screen_page_views' => ['label' => 'Páginas vistas', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['screenPageViews'], 'dimensions' => [], 'limit' => 1, 'cast' => 'int', 'scale' => 1],
            'ga4.engaged_sessions' => ['label' => 'Sesiones con interacción', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['engagedSessions'], 'dimensions' => [], 'limit' => 1, 'cast' => 'int', 'scale' => 1],
            'ga4.engagement_rate' => ['label' => 'Tasa de interacción', 'type' => MetricType::Scalar, 'unit' => 'percent', 'metrics' => ['engagementRate'], 'dimensions' => [], 'limit' => 1, 'cast' => 'float', 'scale' => 100],
            'ga4.bounce_rate' => ['label' => 'Tasa de rebote', 'type' => MetricType::Scalar, 'unit' => 'percent', 'metrics' => ['bounceRate'], 'dimensions' => [], 'limit' => 1, 'cast' => 'float', 'scale' => 100],
            'ga4.avg_session_duration' => ['label' => 'Duración media de sesión (s)', 'type' => MetricType::Scalar, 'unit' => 'seconds', 'metrics' => ['averageSessionDuration'], 'dimensions' => [], 'limit' => 1, 'cast' => 'int', 'scale' => 1],
            'ga4.views_per_session' => ['label' => 'Páginas por sesión', 'type' => MetricType::Scalar, 'unit' => 'ratio', 'metrics' => ['screenPageViewsPerSession'], 'dimensions' => [], 'limit' => 1, 'cast' => 'float', 'scale' => 1],
            'ga4.conversions' => ['label' => 'Conversiones', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['conversions'], 'dimensions' => [], 'limit' => 1, 'cast' => 'int', 'scale' => 1],
            'ga4.event_count' => ['label' => 'Eventos', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['eventCount'], 'dimensions' => [], 'limit' => 1, 'cast' => 'int', 'scale' => 1],

            // ---- Ecommerce ----
            'ga4.revenue' => ['label' => 'Ingresos totales', 'type' => MetricType::Scalar, 'unit' => 'currency', 'metrics' => ['totalRevenue'], 'dimensions' => [], 'limit' => 1, 'cast' => 'float', 'scale' => 1],
            'ga4.purchase_revenue' => ['label' => 'Ingresos por compras', 'type' => MetricType::Scalar, 'unit' => 'currency', 'metrics' => ['purchaseRevenue'], 'dimensions' => [], 'limit' => 1, 'cast' => 'float', 'scale' => 1],
            'ga4.transactions' => ['label' => 'Transacciones', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['transactions'], 'dimensions' => [], 'limit' => 1, 'cast' => 'int', 'scale' => 1],
            'ga4.purchases' => ['label' => 'Compras', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['ecommercePurchases'], 'dimensions' => [], 'limit' => 1, 'cast' => 'int', 'scale' => 1],
            'ga4.avg_purchase_revenue' => ['label' => 'Ticket medio', 'type' => MetricType::Scalar, 'unit' => 'currency', 'metrics' => ['averagePurchaseRevenue'], 'dimensions' => [], 'limit' => 1, 'cast' => 'float', 'scale' => 1],
            'ga4.items_purchased' => ['label' => 'Artículos comprados', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['itemsPurchased'], 'dimensions' => [], 'limit' => 1, 'cast' => 'int', 'scale' => 1],
            'ga4.items_viewed' => ['label' => 'Artículos vistos', 'type' => MetricType::Scalar, 'unit' => 'count', 'metrics' => ['itemsViewed'], 'dimensions' => [], 'limit' => 1, 'cast' => 'int', 'scale' => 1],
            'ga4.purchaser_conversion_rate' => ['label' => 'Conversión a compra', 'type' => MetricType::Scalar, 'unit' => 'percent', 'metrics' => ['purchaserConversionRate'], 'dimensions' => [], 'limit' => 1, 'cast' => 'float', 'scale' => 100],

            // ---- Time series ----
            'ga4.sessions_by_date' => ['label' => 'Sesiones por día', 'type' => MetricType::Series, 'unit' => 'count', 'metrics' => ['sessions'], 'dimensions' => ['date'], 'limit' => 400, 'cast' => 'int', 'scale' => 1],
            'ga4.users_by_date' => ['label' => 'Usuarios por día', 'type' => MetricType::Series, 'unit' => 'count', 'metrics' => ['activeUsers'], 'dimensions' => ['date'], 'limit' => 400, 'cast' => 'int', 'scale' => 1],
            'ga4.revenue_by_date' => ['label' => 'Ingresos por día', 'type' => MetricType::Series, 'unit' => 'currency', 'metrics' => ['totalRevenue'], 'dimensions' => ['date'], 'limit' => 400, 'cast' => 'float', 'scale' => 1],
            'ga4.purchases_by_date' => ['label' => 'Compras por día', 'type' => MetricType::Series, 'unit' => 'count', 'metrics' => ['ecommercePurchases'], 'dimensions' => ['date'], 'limit' => 400, 'cast' => 'int', 'scale' => 1],

            // ---- Top-N tables ----
            'ga4.top_pages' => ['label' => 'Páginas más vistas', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['screenPageViews'], 'dimensions' => ['pagePath'], 'limit' => 10, 'cast' => 'int', 'scale' => 1],
            'ga4.top_landing_pages' => ['label' => 'Páginas de entrada', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['sessions'], 'dimensions' => ['landingPage'], 'limit' => 10, 'cast' => 'int', 'scale' => 1],
            'ga4.traffic_sources' => ['label' => 'Fuentes de tráfico', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['sessions'], 'dimensions' => ['sessionDefaultChannelGroup'], 'limit' => 10, 'cast' => 'int', 'scale' => 1],
            'ga4.top_countries' => ['label' => 'Países', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['sessions'], 'dimensions' => ['country'], 'limit' => 10, 'cast' => 'int', 'scale' => 1],
            'ga4.top_cities' => ['label' => 'Ciudades', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['sessions'], 'dimensions' => ['city'], 'limit' => 10, 'cast' => 'int', 'scale' => 1],
            'ga4.by_region' => ['label' => 'Regiones', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['sessions'], 'dimensions' => ['region'], 'limit' => 10, 'cast' => 'int', 'scale' => 1],
            'ga4.by_language' => ['label' => 'Idiomas', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['sessions'], 'dimensions' => ['language'], 'limit' => 10, 'cast' => 'int', 'scale' => 1],
            'ga4.devices' => ['label' => 'Dispositivos', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['sessions'], 'dimensions' => ['deviceCategory'], 'limit' => 10, 'cast' => 'int', 'scale' => 1],
            'ga4.top_products' => ['label' => 'Productos top (ingresos)', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['itemRevenue'], 'dimensions' => ['itemName'], 'limit' => 10, 'cast' => 'float', 'scale' => 1],

            // ---- Demographics (require Google Signals; empty otherwise → block hides) ----
            'ga4.by_gender' => ['label' => 'Género', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['activeUsers'], 'dimensions' => ['userGender'], 'limit' => 10, 'cast' => 'int', 'scale' => 1],
            'ga4.by_age' => ['label' => 'Edad', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['activeUsers'], 'dimensions' => ['userAgeBracket'], 'limit' => 10, 'cast' => 'int', 'scale' => 1],

            // ---- By time-of-day / weekday ----
            'ga4.sessions_by_hour' => ['label' => 'Visitas por hora', 'type' => MetricType::Series, 'unit' => 'count', 'metrics' => ['sessions'], 'dimensions' => ['hour'], 'limit' => 24, 'cast' => 'int', 'scale' => 1],
            'ga4.sessions_by_weekday' => ['label' => 'Visitas por día de semana', 'type' => MetricType::Series, 'unit' => 'count', 'metrics' => ['sessions'], 'dimensions' => ['dayOfWeek'], 'limit' => 7, 'cast' => 'int', 'scale' => 1],
        ];
    }

    /**
     * Datasets: bounded, multi-dimension, multi-measure top-N cuts that the editor shapes
     * with filters/breakdown/measure (CLAUDE.md §10 dashboards). Each row carries its
     * dimension columns so an agency can pre-filter a block ("cities, only Colombia";
     * "sessions from Facebook"). Still one aggregated runReport per dataset, top-N — never
     * raw rows (§3.3). `dimensions`/`measures` map our field keys → GA4 API names.
     *
     * @return array<string, array{
     *     label: string,
     *     dimensions: array<string, array{label: string, api: string}>,
     *     measures: array<string, array{label: string, api: string, unit: string|null, cast: 'int'|'float', scale: int}>,
     *     limit: int,
     * }>
     */
    private function datasetSpecs(): array
    {
        return [
            'ga4.geo' => [
                'label' => 'Geografía',
                'dimensions' => [
                    'country' => ['label' => 'País', 'api' => 'country'],
                    'region' => ['label' => 'Región', 'api' => 'region'],
                    'city' => ['label' => 'Ciudad', 'api' => 'city'],
                ],
                'measures' => [
                    'sessions' => ['label' => 'Sesiones', 'api' => 'sessions', 'unit' => 'count', 'cast' => 'int', 'scale' => 1],
                    'users' => ['label' => 'Usuarios', 'api' => 'totalUsers', 'unit' => 'count', 'cast' => 'int', 'scale' => 1],
                ],
                'limit' => 250,
            ],
            'ga4.traffic' => [
                'label' => 'Tráfico (canal/fuente/medio)',
                'dimensions' => [
                    'channel' => ['label' => 'Canal', 'api' => 'sessionDefaultChannelGroup'],
                    'source' => ['label' => 'Fuente', 'api' => 'sessionSource'],
                    'medium' => ['label' => 'Medio', 'api' => 'sessionMedium'],
                ],
                'measures' => [
                    'sessions' => ['label' => 'Sesiones', 'api' => 'sessions', 'unit' => 'count', 'cast' => 'int', 'scale' => 1],
                    'users' => ['label' => 'Usuarios', 'api' => 'totalUsers', 'unit' => 'count', 'cast' => 'int', 'scale' => 1],
                    'conversions' => ['label' => 'Conversiones', 'api' => 'conversions', 'unit' => 'count', 'cast' => 'int', 'scale' => 1],
                ],
                'limit' => 250,
            ],
            'ga4.pages' => [
                'label' => 'Páginas',
                'dimensions' => [
                    'page' => ['label' => 'Página', 'api' => 'pagePath'],
                    'landing' => ['label' => 'Página de entrada', 'api' => 'landingPage'],
                ],
                'measures' => [
                    'views' => ['label' => 'Páginas vistas', 'api' => 'screenPageViews', 'unit' => 'count', 'cast' => 'int', 'scale' => 1],
                    'sessions' => ['label' => 'Sesiones', 'api' => 'sessions', 'unit' => 'count', 'cast' => 'int', 'scale' => 1],
                ],
                'limit' => 250,
            ],
        ];
    }

    /**
     * Built-in datasets plus any the agency built with the self-serve builder (stored on
     * the source config under `custom_datasets`, §10.6/A.3). User datasets are just a
     * query spec — they run through the exact same fetch/parse path as the factory ones.
     *
     * @return array<string, array{
     *     label: string,
     *     dimensions: array<string, array{label: string, api: string}>,
     *     measures: array<string, array{label: string, api: string, unit: string|null, cast: 'int'|'float', scale: int}>,
     *     limit: int,
     * }>
     */
    private function allDatasetSpecs(DataSource $source): array
    {
        return array_merge($this->datasetSpecs(), $this->customDatasetSpecs($source));
    }

    /**
     * Parse user-built datasets from the source config into the same spec shape, with
     * caps that keep them aggregate-at-source and bounded (§3.3): max 5 dimensions,
     * 10 measures, and a top-N limit of 1000. Malformed entries are skipped, never fatal.
     *
     * @return array<string, array{
     *     label: string,
     *     dimensions: array<string, array{label: string, api: string}>,
     *     measures: array<string, array{label: string, api: string, unit: string|null, cast: 'int'|'float', scale: int}>,
     *     limit: int,
     * }>
     */
    private function customDatasetSpecs(DataSource $source): array
    {
        $raw = ($source->config ?? [])['custom_datasets'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $key = is_string($entry['key'] ?? null) ? trim($entry['key']) : '';
            if ($key === '' || preg_match('/^[a-z0-9_]+$/i', $key) !== 1) {
                continue;
            }

            $dimensions = [];
            foreach (is_array($entry['dimensions'] ?? null) ? $entry['dimensions'] : [] as $dimension) {
                if (! is_array($dimension) || count($dimensions) >= 5) {
                    continue;
                }
                $dimensionKey = is_string($dimension['key'] ?? null) ? $dimension['key'] : '';
                $api = is_string($dimension['api'] ?? null) ? $dimension['api'] : '';
                if ($dimensionKey === '' || $api === '') {
                    continue;
                }
                $label = is_string($dimension['label'] ?? null) && $dimension['label'] !== '' ? $dimension['label'] : $dimensionKey;
                $dimensions[$dimensionKey] = ['label' => $label, 'api' => $api];
            }

            $measures = [];
            foreach (is_array($entry['measures'] ?? null) ? $entry['measures'] : [] as $measure) {
                if (! is_array($measure) || count($measures) >= 10) {
                    continue;
                }
                $measureKey = is_string($measure['key'] ?? null) ? $measure['key'] : '';
                $api = is_string($measure['api'] ?? null) ? $measure['api'] : '';
                if ($measureKey === '' || $api === '') {
                    continue;
                }
                $label = is_string($measure['label'] ?? null) && $measure['label'] !== '' ? $measure['label'] : $measureKey;
                $measures[$measureKey] = [
                    'label' => $label,
                    'api' => $api,
                    'unit' => is_string($measure['unit'] ?? null) ? $measure['unit'] : null,
                    'cast' => ($measure['cast'] ?? null) === 'int' ? 'int' : 'float',
                    'scale' => is_numeric($measure['scale'] ?? null) ? (int) $measure['scale'] : 1,
                ];
            }

            if ($dimensions === [] || $measures === []) {
                continue;
            }

            $limit = is_numeric($entry['limit'] ?? null) ? (int) $entry['limit'] : 250;

            $out["ga4.custom.{$key}"] = [
                'label' => is_string($entry['label'] ?? null) && $entry['label'] !== '' ? $entry['label'] : $key,
                'dimensions' => $dimensions,
                'measures' => $measures,
                'limit' => max(1, min($limit, 1000)),
            ];
        }

        return $out;
    }

    /**
     * @param  array{label: string, dimensions: array<string, array{label: string, api: string}>, measures: array<string, array{label: string, api: string, unit: string|null, cast: 'int'|'float', scale: int}>, limit: int}  $spec
     * @return array<string, mixed>
     */
    private function datasetBody(array $spec, Period $period): array
    {
        $measureApis = array_values(array_map(static fn (array $m): string => $m['api'], $spec['measures']));

        return [
            'dateRanges' => [[
                'startDate' => $period->start->toDateString(),
                'endDate' => $period->end->toDateString(),
            ]],
            'dimensions' => array_values(array_map(static fn (array $d): array => ['name' => $d['api']], $spec['dimensions'])),
            'metrics' => array_map(static fn (string $api): array => ['name' => $api], $measureApis),
            'orderBys' => [['metric' => ['metricName' => $measureApis[0]], 'desc' => true]],
            'limit' => $spec['limit'],
        ];
    }

    /**
     * Map GA4's positional dimensionValues/metricValues into named dataset rows
     * (`{country, region, city, sessions, users}`) the DatasetEngine can filter/group.
     *
     * @param  array{label: string, dimensions: array<string, array{label: string, api: string}>, measures: array<string, array{label: string, api: string, unit: string|null, cast: 'int'|'float', scale: int}>, limit: int}  $spec
     * @return list<array<string, string|int|float>>
     */
    private function parseDataset(array $spec, mixed $json): array
    {
        $dimensionKeys = array_keys($spec['dimensions']);
        $measureKeys = array_keys($spec['measures']);
        $measureMeta = array_values($spec['measures']);

        $out = [];
        foreach ($this->rows($json) as $row) {
            $entry = [];

            foreach ($dimensionKeys as $index => $dimensionKey) {
                $value = Arr::get($row, "dimensionValues.{$index}.value");
                $entry[$dimensionKey] = is_string($value) ? $value : '';
            }

            foreach ($measureKeys as $index => $measureKey) {
                $value = Arr::get($row, "metricValues.{$index}.value");
                $raw = (is_numeric($value) ? (float) $value : 0.0) * $measureMeta[$index]['scale'];
                $entry[$measureKey] = $measureMeta[$index]['cast'] === 'int' ? (int) round($raw) : round($raw, 2);
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param  array{label: string, type: MetricType, unit: string|null, metrics: list<string>, dimensions: list<string>, limit: int, cast: 'int'|'float', scale: int}  $spec
     * @return array<string, mixed>
     */
    private function body(array $spec, Period $period): array
    {
        $body = [
            'dateRanges' => [[
                'startDate' => $period->start->toDateString(),
                'endDate' => $period->end->toDateString(),
            ]],
            'metrics' => array_map(static fn (string $m): array => ['name' => $m], $spec['metrics']),
            'limit' => $spec['limit'],
        ];

        if ($spec['dimensions'] !== []) {
            $body['dimensions'] = array_map(static fn (string $d): array => ['name' => $d], $spec['dimensions']);
            // Series read chronologically by their dimension (date/hour/weekday); tables
            // are true top-N ordered by their metric descending.
            $body['orderBys'] = $spec['type'] === MetricType::Series
                ? [['dimension' => ['dimensionName' => $spec['dimensions'][0]]]]
                : [['metric' => ['metricName' => $spec['metrics'][0]], 'desc' => true]];
        }

        return $body;
    }

    /**
     * @param  array{label: string, type: MetricType, unit: string|null, metrics: list<string>, dimensions: list<string>, limit: int, cast: 'int'|'float', scale: int}  $spec
     * @return int|float|list<array{label: string, value: int|float}>|list<array{date: string, value: int|float}>
     */
    private function parse(array $spec, mixed $json): int|float|array
    {
        $rows = $this->rows($json);
        $apply = function (float $raw) use ($spec): int|float {
            $scaled = $raw * $spec['scale'];

            return $spec['cast'] === 'int' ? (int) round($scaled) : round($scaled, 2);
        };

        return match ($spec['type']) {
            MetricType::Scalar => $apply(array_sum(array_map($this->numericValue(...), $rows))),
            MetricType::Series => array_map(
                fn (array $row): array => ['date' => $this->dimensionValue($row), 'value' => $apply($this->numericValue($row))],
                $rows,
            ),
            MetricType::Table => array_map(
                fn (array $row): array => ['label' => $this->dimensionValue($row), 'value' => $apply($this->numericValue($row))],
                $rows,
            ),
            // Datasets (multi-dimension top-N) are fetched via a dedicated path, not this
            // single-dimension parser — unreachable here.
            MetricType::Dataset => [],
        };
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rows(mixed $json): array
    {
        $rows = is_array($json) ? ($json['rows'] ?? []) : [];

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, is_array(...)));
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function numericValue(array $row): float
    {
        $value = Arr::get($row, 'metricValues.0.value');

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function dimensionValue(array $row): string
    {
        $value = Arr::get($row, 'dimensionValues.0.value');

        return is_string($value) ? $value : '';
    }
}
