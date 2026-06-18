<?php

declare(strict_types=1);

namespace App\Reports\Templates;

use App\Reports\Blocks\BlockType;

/**
 * The default narrative template (CLAUDE.md §11.5) — the retention-focused layout
 * the AI builder and new definitions start from. Fully editable later. Blocks bound
 * to a source with no data are gracefully hidden by the ReportGenerator (§10.4), so
 * including Phase-2 sources here is safe.
 */
final class DefaultTemplate
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function blocks(): array
    {
        return [
            self::block('header', BlockType::Header, props: ['showLogo' => true, 'showPeriod' => true]),
            self::block('health', BlockType::HealthScore, props: ['title' => 'Estado general']),
            self::block('summary', BlockType::Narrative, props: ['variant' => 'executive_summary', 'title' => 'Resumen del mes']),

            self::kpi('kpi_uptime', 'betteruptime', 'uptime_percent', 'Disponibilidad', 'percent'),
            self::kpi('kpi_attacks', 'crowdsec', 'attacks_blocked', 'Ataques bloqueados', 'count'),
            self::kpi('kpi_updates', 'mainwp', 'updates_available', 'Actualizaciones', 'count'),
            self::kpi('kpi_visits', 'ga4', 'sessions', 'Visitas', 'count'),
            self::kpi('kpi_sales', 'woocommerce', 'revenue', 'Ventas', 'currency'),

            self::block('security', BlockType::SecurityShield, props: ['title' => 'Seguridad']),
            self::chart('uptime_chart', 'betteruptime', 'uptime_by_date', 'area', 'Disponibilidad'),
            self::chart('traffic_chart', 'ga4', 'sessions_by_date', 'line', 'Visitas por día'),
            self::table('top_pages', 'ga4', 'top_pages', 'Páginas más vistas'),
            self::table('top_queries', 'gsc', 'top_queries', 'Búsquedas principales'),

            self::block('worklog', BlockType::WorklogTimeline, props: ['title' => 'Lo que hicimos este mes']),
            self::block('sales', BlockType::SalesSummary, binding: ['source' => 'woocommerce', 'metric' => 'revenue'], props: ['title' => 'Ventas']),

            self::block('divider', BlockType::Divider),
            self::block('footer', BlockType::Narrative, props: ['variant' => 'footer_cta']),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $binding
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function block(string $id, BlockType $type, ?array $binding = null, array $props = []): array
    {
        return [
            'id' => $id,
            'type' => $type->value,
            'binding' => $binding,
            'props' => $props,
            'style' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function kpi(string $id, string $source, string $metric, string $label, string $unit): array
    {
        return self::block($id, BlockType::Kpi, [
            'source' => $source,
            'metric' => $metric,
            'compare' => 'prev_period',
        ], ['label' => $label, 'unit' => $unit]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function chart(string $id, string $source, string $metric, string $chartType, string $title): array
    {
        return self::block($id, BlockType::Chart, [
            'source' => $source,
            'metric' => $metric,
        ], ['chartType' => $chartType, 'title' => $title]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function table(string $id, string $source, string $metric, string $title): array
    {
        return self::block($id, BlockType::Table, [
            'source' => $source,
            'metric' => $metric,
        ], ['title' => $title]);
    }
}
