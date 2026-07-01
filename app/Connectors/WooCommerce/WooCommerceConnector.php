<?php

declare(strict_types=1);

namespace App\Connectors\WooCommerce;

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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WooCommerce connector (CLAUDE.md §9). Read-only consumer key/secret; reads
 * revenue, orders and top products from the WC REST API reports endpoints
 * (aggregated server-side, §3.3). The exact `/wc/v3/reports` field names are a
 * documented assumption — see PROGRESS Open Questions.
 */
final class WooCommerceConnector implements DataSourceConnector, ProvidesSetupGuide
{
    use ParsesValues;

    private const API_PREFIX = '/wp-json/wc/v3';

    private const ANALYTICS_PREFIX = '/wp-json/wc-analytics';

    public function key(): string
    {
        return DataSourceType::WooCommerce->value;
    }

    public function label(): string
    {
        return DataSourceType::WooCommerce->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('store_url', 'Store URL', ConfigFieldType::Url, help: 'URL de la tienda con HTTPS, p. ej. https://tutienda.com'),
            new ConfigField('consumer_key', 'Consumer key', ConfigFieldType::Password, secret: true, help: 'En la tienda: WooCommerce → Ajustes → Avanzado → REST API → Añadir clave (permisos: Lectura). Empieza por «ck_».'),
            new ConfigField('consumer_secret', 'Consumer secret', ConfigFieldType::Password, secret: true, help: 'Se genera junto a la consumer key (empieza por «cs_»). Solo se muestra una vez al crearla.'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        $catalog = new MetricCatalog(
            new MetricDefinition('woocommerce.revenue', 'Ingresos (brutos)', MetricType::Scalar, 'currency'),
            new MetricDefinition('woocommerce.net_revenue', 'Ingresos netos', MetricType::Scalar, 'currency'),
            new MetricDefinition('woocommerce.orders', 'Pedidos', MetricType::Scalar, 'count'),
            new MetricDefinition('woocommerce.items_sold', 'Artículos vendidos', MetricType::Scalar, 'count'),
            new MetricDefinition('woocommerce.average_sales', 'Venta media diaria', MetricType::Scalar, 'currency'),
            new MetricDefinition('woocommerce.tax', 'Impuestos', MetricType::Scalar, 'currency'),
            new MetricDefinition('woocommerce.shipping', 'Envíos', MetricType::Scalar, 'currency'),
            new MetricDefinition('woocommerce.discount', 'Descuentos', MetricType::Scalar, 'currency'),
            new MetricDefinition('woocommerce.refunds', 'Reembolsos', MetricType::Scalar, 'count'),
            new MetricDefinition('woocommerce.new_customers', 'Clientes nuevos', MetricType::Scalar, 'count'),
            new MetricDefinition('woocommerce.revenue_by_date', 'Ingresos por día', MetricType::Series, 'currency'),
            new MetricDefinition('woocommerce.orders_by_date', 'Pedidos por día', MetricType::Series, 'count'),
            new MetricDefinition('woocommerce.top_products', 'Productos más vendidos', MetricType::Table),
        );

        // Datasets (multi-measure, bounded top-N) from the WC Analytics API, so the editor
        // can model sales blocks like Looker (filter/break/sort) — §10 dashboards.
        foreach ($this->datasetSpecs() as $key => $spec) {
            $measures = [];
            foreach ($spec['measures'] as $measureKey => $measure) {
                $measures[] = ['key' => $measureKey, 'label' => $measure['label'], 'unit' => $measure['unit']];
            }

            $dimensionLabels = [];
            foreach ($spec['dimensions'] as $dimensionKey => $dimension) {
                $dimensionLabels[$dimensionKey] = $dimension['label'];
            }

            $catalog = $catalog->with(new MetricDefinition(
                $key,
                $spec['label'],
                MetricType::Dataset,
                null,
                array_keys($spec['dimensions']),
                null,
                $measures,
                $dimensionLabels,
            ));
        }

        return $catalog;
    }

    /**
     * Bounded multi-measure datasets from the WooCommerce **Analytics** API
     * (`/wp-json/wc-analytics/reports/...`, aggregated server-side, §3.3). Each report is
     * single-dimension (product / category / coupon) with several additive measures; the
     * DatasetEngine then shapes them (filter/break/sort/limit). `dimensions` map field key →
     * the row path holding the label; `measures` map field key → the row path + cast/unit.
     * The exact field names are a documented assumption — see PROGRESS Open Questions.
     *
     * @return array<string, array{
     *     label: string,
     *     endpoint: string,
     *     order_by: string,
     *     dimensions: array<string, array{label: string, path: string}>,
     *     measures: array<string, array{label: string, path: string, unit: string|null, cast: 'int'|'float'}>,
     *     limit: int,
     * }>
     */
    private function datasetSpecs(): array
    {
        return [
            'woocommerce.products' => [
                'label' => 'Productos (ventas)',
                'endpoint' => '/reports/products',
                'order_by' => 'items_sold',
                'dimensions' => [
                    'product' => ['label' => 'Producto', 'path' => 'extended_info.name'],
                ],
                'measures' => [
                    'items_sold' => ['label' => 'Unidades vendidas', 'path' => 'items_sold', 'unit' => 'count', 'cast' => 'int'],
                    'net_revenue' => ['label' => 'Ingresos netos', 'path' => 'net_revenue', 'unit' => 'currency', 'cast' => 'float'],
                    'orders_count' => ['label' => 'Pedidos', 'path' => 'orders_count', 'unit' => 'count', 'cast' => 'int'],
                ],
                'limit' => 100,
            ],
            'woocommerce.categories' => [
                'label' => 'Categorías (ventas)',
                'endpoint' => '/reports/categories',
                'order_by' => 'items_sold',
                'dimensions' => [
                    'category' => ['label' => 'Categoría', 'path' => 'extended_info.name'],
                ],
                'measures' => [
                    'items_sold' => ['label' => 'Unidades vendidas', 'path' => 'items_sold', 'unit' => 'count', 'cast' => 'int'],
                    'net_revenue' => ['label' => 'Ingresos netos', 'path' => 'net_revenue', 'unit' => 'currency', 'cast' => 'float'],
                    'orders_count' => ['label' => 'Pedidos', 'path' => 'orders_count', 'unit' => 'count', 'cast' => 'int'],
                ],
                'limit' => 100,
            ],
            'woocommerce.coupons' => [
                'label' => 'Cupones (uso)',
                'endpoint' => '/reports/coupons',
                'order_by' => 'orders_count',
                'dimensions' => [
                    'coupon' => ['label' => 'Cupón', 'path' => 'extended_info.code'],
                ],
                'measures' => [
                    'amount' => ['label' => 'Descuento', 'path' => 'amount', 'unit' => 'currency', 'cast' => 'float'],
                    'orders_count' => ['label' => 'Pedidos', 'path' => 'orders_count', 'unit' => 'count', 'cast' => 'int'],
                ],
                'limit' => 100,
            ],
            'woocommerce.customers' => [
                'label' => 'Clientes (gasto)',
                'endpoint' => '/reports/customers',
                'order_by' => 'total_spend',
                'dimensions' => [
                    'customer' => ['label' => 'Cliente', 'path' => 'name'],
                    'country' => ['label' => 'País', 'path' => 'country'],
                ],
                'measures' => [
                    'total_spend' => ['label' => 'Gasto total', 'path' => 'total_spend', 'unit' => 'currency', 'cast' => 'float'],
                    'orders_count' => ['label' => 'Pedidos', 'path' => 'orders_count', 'unit' => 'count', 'cast' => 'int'],
                    'avg_order_value' => ['label' => 'Ticket medio', 'path' => 'avg_order_value', 'unit' => 'currency', 'cast' => 'float'],
                ],
                'limit' => 100,
            ],
        ];
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'Genera claves de API REST de solo lectura en tu tienda WooCommerce.',
            [
                'WordPress → WooCommerce → Ajustes → Avanzado → API REST → «Añadir clave».',
                'Descripción libre, Usuario un administrador, Permisos «Lectura». Pulsa «Generar clave de API».',
                'Copia «Consumer key» (ck_…) y «Consumer secret» (cs_…) — solo se muestran una vez.',
                'En «store_url» pon la URL de la tienda (https://tutienda.com) y pega ck y cs en sus campos.',
                'Guarda y pulsa «Probar conexión».',
            ],
            'https://woocommerce.github.io/woocommerce-rest-api-docs/',
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            $response = $this->client($source)->get('/reports/sales', $this->range(Period::make('7 days ago', 'today')));

            return $response->successful()
                ? ConnectionResult::success('WooCommerce store reachable.')
                : ConnectionResult::failure('WooCommerce responded with HTTP '.$response->status());
        } catch (Throwable $e) {
            return ConnectionResult::failure('Could not reach WooCommerce: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        try {
            $sales = $this->client($source)->get('/reports/sales', $this->range($period));
            $top = $this->client($source)->get('/reports/top_sellers', $this->range($period));
        } catch (Throwable $e) {
            return MetricSet::failed('WooCommerce request error: '.$e->getMessage());
        }

        if ($sales->failed()) {
            return MetricSet::failed('WooCommerce sales request failed: HTTP '.$sales->status());
        }

        $report = $this->listOf($sales->json())[0] ?? [];

        $metrics = [
            'woocommerce.revenue' => $this->toFloat(Arr::get($report, 'total_sales')),
            'woocommerce.net_revenue' => $this->toFloat(Arr::get($report, 'net_sales')),
            'woocommerce.orders' => $this->toInt(Arr::get($report, 'total_orders')),
            'woocommerce.items_sold' => $this->toInt(Arr::get($report, 'total_items')),
            'woocommerce.average_sales' => $this->toFloat(Arr::get($report, 'average_sales')),
            'woocommerce.tax' => $this->toFloat(Arr::get($report, 'total_tax')),
            'woocommerce.shipping' => $this->toFloat(Arr::get($report, 'total_shipping')),
            'woocommerce.discount' => $this->toFloat(Arr::get($report, 'total_discount')),
            'woocommerce.refunds' => $this->toInt(Arr::get($report, 'total_refunds')),
            'woocommerce.new_customers' => $this->toInt(Arr::get($report, 'total_customers')),
            'woocommerce.revenue_by_date' => $this->series(Arr::get($report, 'totals'), 'sales'),
            'woocommerce.orders_by_date' => $this->series(Arr::get($report, 'totals'), 'orders'),
            'woocommerce.top_products' => $this->topProducts($top->failed() ? null : $top->json()),
        ];

        // Datasets (WC Analytics): only the requested ones (all when sync asks for everything).
        $datasetKeys = $requestedMetrics === []
            ? array_keys($this->datasetSpecs())
            : array_values(array_intersect($requestedMetrics, array_keys($this->datasetSpecs())));

        $this->collectDatasets($source, $period, $datasetKeys, $metrics);

        return MetricSet::ok($metrics);
    }

    /**
     * Run each requested dataset's WC Analytics report → named rows
     * (`{<dimension>, <measures…>}`) the DatasetEngine shapes. Best-effort and OPTIONAL:
     * the WC Analytics API may be absent on a store, so a failure is logged and skipped —
     * it never degrades the base sales metrics to partial (§3.1: a missing optional source
     * must not break the report).
     *
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $metrics
     */
    private function collectDatasets(DataSource $source, Period $period, array $keys, array &$metrics): void
    {
        foreach ($this->datasetSpecs() as $key => $spec) {
            if (! in_array($key, $keys, true)) {
                continue;
            }

            try {
                $response = $this->analyticsClient($source)->get($spec['endpoint'], [
                    'after' => $period->start->toIso8601String(),
                    'before' => $period->end->toIso8601String(),
                    'per_page' => $spec['limit'],
                    'orderby' => $spec['order_by'],
                    'order' => 'desc',
                    'extended_info' => 'true',
                ]);
            } catch (Throwable $e) {
                Log::warning('WooCommerce dataset fetch failed.', ['dataset' => $key, 'error' => $e->getMessage()]);

                continue;
            }

            if ($response->failed()) {
                Log::warning('WooCommerce dataset request failed.', ['dataset' => $key, 'status' => $response->status()]);

                continue;
            }

            $metrics[$key] = array_map(function (array $row) use ($spec): array {
                $entry = [];
                foreach ($spec['dimensions'] as $dimensionKey => $dimension) {
                    $entry[$dimensionKey] = $this->toStr(Arr::get($row, $dimension['path']));
                }
                foreach ($spec['measures'] as $measureKey => $measure) {
                    $value = Arr::get($row, $measure['path']);
                    $entry[$measureKey] = $measure['cast'] === 'int' ? $this->toInt($value) : $this->toFloat($value);
                }

                return $entry;
            }, $this->listOf($response->json()));
        }
    }

    /**
     * Build a date-keyed series from the `/reports/sales` `totals` map, e.g.
     * `{"2026-06-01": {"sales": "10.00", "orders": "2", ...}, ...}` → a normalized
     * `[{date, value}]` series the chart blocks consume (matches the ga4.*_by_date shape).
     *
     * @return list<array{date: string, value: float}>
     */
    private function series(mixed $totals, string $field): array
    {
        if (! is_array($totals)) {
            return [];
        }

        $series = [];
        foreach ($totals as $date => $row) {
            $series[] = [
                'date' => $this->toStr($date),
                'value' => $this->toFloat(Arr::get($this->arrayOf($row), $field)),
            ];
        }

        return $series;
    }

    /**
     * @return list<array{name: string, quantity: int}>
     */
    private function topProducts(mixed $payload): array
    {
        return array_map(
            fn (array $row): array => [
                'name' => $this->toStr(Arr::get($row, 'name', Arr::get($row, 'title'))),
                'quantity' => $this->toInt(Arr::get($row, 'quantity')),
            ],
            $this->listOf($payload),
        );
    }

    private function client(DataSource $source): PendingRequest
    {
        return $this->httpFor($source, self::API_PREFIX);
    }

    /**
     * Client for the WooCommerce **Analytics** API (`/wp-json/wc-analytics`), which powers
     * the dataset reports (products/categories/coupons) with server-side aggregation.
     */
    private function analyticsClient(DataSource $source): PendingRequest
    {
        return $this->httpFor($source, self::ANALYTICS_PREFIX);
    }

    private function httpFor(DataSource $source, string $prefix): PendingRequest
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];

        return Http::baseUrl(rtrim($this->toStr(Arr::get($config, 'store_url')), '/').$prefix)
            ->withBasicAuth(
                $this->toStr(Arr::get($credentials, 'consumer_key')),
                $this->toStr(Arr::get($credentials, 'consumer_secret')),
            )
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * @return array{date_min: string, date_max: string}
     */
    private function range(Period $period): array
    {
        return [
            'date_min' => $period->start->toDateString(),
            'date_max' => $period->end->toDateString(),
        ];
    }
}
