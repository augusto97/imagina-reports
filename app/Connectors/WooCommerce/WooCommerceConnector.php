<?php

declare(strict_types=1);

namespace App\Connectors\WooCommerce;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\Support\ParsesValues;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WooCommerce connector (CLAUDE.md §9). Read-only consumer key/secret; reads
 * revenue, orders and top products from the WC REST API reports endpoints
 * (aggregated server-side, §3.3). The exact `/wc/v3/reports` field names are a
 * documented assumption — see PROGRESS Open Questions.
 */
final class WooCommerceConnector implements DataSourceConnector
{
    use ParsesValues;

    private const API_PREFIX = '/wp-json/wc/v3';

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
        return new MetricCatalog(
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

        return MetricSet::ok($metrics);
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
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];

        return Http::baseUrl(rtrim($this->toStr(Arr::get($config, 'store_url')), '/').self::API_PREFIX)
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
