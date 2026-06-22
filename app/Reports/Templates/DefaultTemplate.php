<?php

declare(strict_types=1);

namespace App\Reports\Templates;

use App\Reports\Blocks\BlockType;

/**
 * The default narrative template (CLAUDE.md §11.5) — the retention-focused layout
 * the AI builder and new definitions start from. Fully editable later. Ships an explicit
 * 12-column grid layout so it generates as a polished dashboard (in the portal/PDF too,
 * not only after opening the editor). Blocks bound to a source with no data are gracefully
 * hidden by the ReportGenerator (§10.4), so including Phase-2 sources here is safe.
 */
final class DefaultTemplate
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function blocks(): array
    {
        return [
            self::block('header', BlockType::Header, props: [
                'showLogo' => true,
                'showPeriod' => true,
                'eyebrow' => '{{agency}}',
                'subtitle' => '{{client}} · {{site}} · {{period}}',
            ], layout: ['x' => 0, 'y' => 0, 'w' => 12, 'h' => 2]),

            self::block('health', BlockType::HealthScore, props: ['title' => 'Estado general'], layout: ['x' => 0, 'y' => 2, 'w' => 4, 'h' => 7]),
            self::block('summary', BlockType::Narrative, props: ['variant' => 'executive_summary', 'title' => 'Resumen del mes'], layout: ['x' => 4, 'y' => 2, 'w' => 8, 'h' => 7]),

            // Headline KPIs — the numbers that matter, three-plus per row (§11.5).
            self::kpi('kpi_uptime', 'betteruptime', 'uptime_percent', 'Disponibilidad', 'percent', ['x' => 0, 'y' => 9, 'w' => 3, 'h' => 4]),
            self::kpi('kpi_attacks', 'crowdsec', 'attacks_blocked', 'Ataques bloqueados', 'number', ['x' => 3, 'y' => 9, 'w' => 3, 'h' => 4]),
            self::kpi('kpi_updates', 'mainwp', 'updates_applied', 'Actualizaciones aplicadas', 'number', ['x' => 6, 'y' => 9, 'w' => 3, 'h' => 4]),
            self::kpi('kpi_visits', 'ga4', 'sessions', 'Visitas', 'number', ['x' => 9, 'y' => 9, 'w' => 3, 'h' => 4]),

            self::kpi('kpi_sales', 'woocommerce', 'revenue', 'Ventas', 'currency', ['x' => 0, 'y' => 13, 'w' => 3, 'h' => 4]),
            self::kpi('kpi_orders', 'woocommerce', 'orders', 'Pedidos', 'number', ['x' => 3, 'y' => 13, 'w' => 3, 'h' => 4]),
            self::kpi('kpi_clicks', 'gsc', 'clicks', 'Clics en Google', 'number', ['x' => 6, 'y' => 13, 'w' => 3, 'h' => 4]),
            self::kpi('kpi_position', 'gsc', 'position', 'Posición media', 'number', ['x' => 9, 'y' => 13, 'w' => 3, 'h' => 4]),

            self::block('security', BlockType::SecurityShield, props: ['title' => 'Seguridad'], layout: ['x' => 0, 'y' => 17, 'w' => 6, 'h' => 5]),
            self::chart('traffic_chart', 'ga4', 'sessions_by_date', 'area', 'Visitas por día', ['x' => 6, 'y' => 17, 'w' => 6, 'h' => 5]),

            self::table('top_pages', 'ga4', 'top_pages', 'Páginas más vistas', ['x' => 0, 'y' => 22, 'w' => 6, 'h' => 7], ['bars' => true]),
            self::table('top_queries', 'gsc', 'top_queries', 'Búsquedas principales', ['x' => 6, 'y' => 22, 'w' => 6, 'h' => 7]),

            // "What we did this month" — the block that justifies the support plan (§11.5).
            self::block('worklog', BlockType::WorklogTimeline, props: ['title' => 'Lo que hicimos este mes'], layout: ['x' => 0, 'y' => 29, 'w' => 12, 'h' => 7]),

            // Sales detail (hidden automatically when there is no WooCommerce source).
            self::chart('sales_chart', 'woocommerce', 'revenue_by_date', 'area', 'Ingresos por día', ['x' => 0, 'y' => 36, 'w' => 6, 'h' => 7], ['format' => 'currency']),
            self::table('top_products', 'woocommerce', 'top_products', 'Productos más vendidos', ['x' => 6, 'y' => 36, 'w' => 6, 'h' => 7]),

            self::block('footer', BlockType::Narrative, props: ['variant' => 'footer_cta'], layout: ['x' => 0, 'y' => 43, 'w' => 12, 'h' => 3]),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $binding
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $style
     * @param  array{x: int, y: int, w: int, h: int}|null  $layout
     * @return array<string, mixed>
     */
    private static function block(string $id, BlockType $type, ?array $binding = null, array $props = [], array $style = [], ?array $layout = null): array
    {
        $block = [
            'id' => $id,
            'type' => $type->value,
            'binding' => $binding,
            'props' => $props,
            'style' => $style,
        ];

        if ($layout !== null) {
            $block['layout'] = $layout;
        }

        return $block;
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int}  $layout
     * @return array<string, mixed>
     */
    private static function kpi(string $id, string $source, string $metric, string $label, string $format, array $layout): array
    {
        return self::block($id, BlockType::Kpi, [
            'source' => $source,
            'metric' => $metric,
            'compare' => 'prev_period',
        ], ['label' => $label], ['format' => $format], $layout);
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int}  $layout
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    private static function chart(string $id, string $source, string $metric, string $chartType, string $title, array $layout, array $style = []): array
    {
        return self::block($id, BlockType::Chart, [
            'source' => $source,
            'metric' => $metric,
        ], ['chartType' => $chartType, 'title' => $title], $style, $layout);
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int}  $layout
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    private static function table(string $id, string $source, string $metric, string $title, array $layout, array $style = []): array
    {
        return self::block($id, BlockType::Table, [
            'source' => $source,
            'metric' => $metric,
        ], ['title' => $title], $style, $layout);
    }
}
