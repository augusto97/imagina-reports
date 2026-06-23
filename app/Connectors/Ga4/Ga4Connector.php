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
            $token = $this->tokenProvider->accessToken($source->credentials ?? [], self::SCOPE);
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

        return new MetricCatalog(...$definitions);
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        $propertyId = $this->propertyId($source);

        if ($propertyId === '') {
            return MetricSet::failed('GA4 property_id is not configured.');
        }

        try {
            $token = $this->tokenProvider->accessToken($source->credentials ?? [], self::SCOPE);
        } catch (Throwable $e) {
            return MetricSet::failed('GA4 authentication failed: '.$e->getMessage());
        }

        if ($token === '') {
            return MetricSet::failed('GA4 authentication returned no access token.');
        }

        $specs = $this->specs();
        $keys = $requestedMetrics === [] ? array_keys($specs) : array_values(array_intersect($requestedMetrics, array_keys($specs)));

        $metrics = [];
        $errors = [];

        foreach ($keys as $key) {
            try {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->post(self::API_BASE."/properties/{$propertyId}:runReport", $this->body($specs[$key], $period));

                if ($response->failed()) {
                    $errors[] = "{$key}: HTTP ".$response->status();

                    continue;
                }

                $metrics[$key] = $this->parse($specs[$key], $response->json());
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
            'ga4.devices' => ['label' => 'Dispositivos', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['sessions'], 'dimensions' => ['deviceCategory'], 'limit' => 10, 'cast' => 'int', 'scale' => 1],
            'ga4.top_products' => ['label' => 'Productos top (ingresos)', 'type' => MetricType::Table, 'unit' => null, 'metrics' => ['itemRevenue'], 'dimensions' => ['itemName'], 'limit' => 10, 'cast' => 'float', 'scale' => 1],
        ];
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
