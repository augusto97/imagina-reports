import type { Block, BlockBinding, BlockLayout, BlockType } from '@shared/blocks/types';

import { makeBlock } from './blockFactory';

interface BlockSpec {
    type: BlockType;
    binding?: BlockBinding;
    props?: Record<string, unknown>;
    style?: Record<string, unknown>;
    layout?: BlockLayout;
}

/** Build a fresh block (new id) from a spec, merging over the factory defaults. */
function spec({ type, binding, props, style, layout }: BlockSpec): Block {
    const block = makeBlock(type);
    if (binding !== undefined) {
        block.binding = binding;
    }
    block.props = { ...block.props, ...props };
    block.style = { ...block.style, ...style };
    if (layout !== undefined) {
        block.layout = layout;
    }

    return block;
}

/** A branded report header (eyebrow + title + client·site·period subtitle, via merge fields). */
const header = (title = 'Informe mensual'): Block =>
    spec({
        type: 'header',
        props: { title, eyebrow: '{{agency}}', subtitle: '{{client}} · {{site}} · {{period}}' },
        layout: { x: 0, y: 0, w: 12, h: 2 },
    });

/** A KPI card bound to a metric, compared vs. the previous period. */
const kpi = (source: string, metric: string, label: string, layout: BlockLayout, style: Record<string, unknown> = {}): Block =>
    spec({
        type: 'kpi',
        binding: { source, metric, compare: 'prev_period' },
        props: { label },
        style,
        layout,
    });

/** The AI-filled executive summary narrative (the §11.5 plain-language paragraph). */
const summary = (layout: BlockLayout): Block =>
    spec({ type: 'narrative', props: { variant: 'executive_summary', title: 'Resumen del mes' }, layout });

/** The retention CTA that closes every report. */
const cta = (y: number): Block => spec({ type: 'cta', layout: { x: 0, y, w: 12, h: 3 } });

export interface GalleryTemplate {
    key: string;
    name: string;
    description: string;
    build: () => Block[];
}

/**
 * Starter templates by vertical (CLAUDE.md §10.2 / §11.5 — "ready starting points").
 * Each ships an explicit 12-column grid layout so it opens as a polished, intentional
 * dashboard (not auto-flowed). Bindings use real connector metric keys; blocks whose
 * metric has no data are gracefully hidden at generation, so a template never shows an
 * empty box.
 */
export const GALLERY: GalleryTemplate[] = [
    {
        key: 'woocommerce',
        name: 'Tienda WooCommerce',
        description: 'Reporte completo de e-commerce: ingresos, pedidos, productos y tendencia diaria.',
        build: () => [
            header('Reporte de tu tienda'),
            kpi('woocommerce', 'revenue', 'Ingresos', { x: 0, y: 2, w: 3, h: 4 }, { format: 'currency' }),
            kpi('woocommerce', 'net_revenue', 'Ingresos netos', { x: 3, y: 2, w: 3, h: 4 }, { format: 'currency' }),
            kpi('woocommerce', 'orders', 'Pedidos', { x: 6, y: 2, w: 3, h: 4 }),
            kpi('woocommerce', 'new_customers', 'Clientes nuevos', { x: 9, y: 2, w: 3, h: 4 }),
            spec({
                type: 'chart',
                binding: { source: 'woocommerce', metric: 'revenue_by_date' },
                props: { chartType: 'area', title: 'Ingresos por día' },
                style: { format: 'currency' },
                layout: { x: 0, y: 6, w: 8, h: 8 },
            }),
            kpi('woocommerce', 'items_sold', 'Artículos vendidos', { x: 8, y: 6, w: 4, h: 4 }),
            kpi('woocommerce', 'average_sales', 'Venta media diaria', { x: 8, y: 10, w: 4, h: 4 }, { format: 'currency' }),
            spec({
                type: 'table',
                binding: { source: 'woocommerce', metric: 'top_products' },
                props: { title: 'Productos más vendidos' },
                layout: { x: 0, y: 14, w: 6, h: 7 },
            }),
            spec({
                type: 'chart',
                binding: { source: 'woocommerce', metric: 'orders_by_date' },
                props: { chartType: 'bar', title: 'Pedidos por día' },
                layout: { x: 6, y: 14, w: 6, h: 7 },
            }),
            kpi('woocommerce', 'tax', 'Impuestos', { x: 0, y: 21, w: 3, h: 4 }, { format: 'currency' }),
            kpi('woocommerce', 'shipping', 'Envíos', { x: 3, y: 21, w: 3, h: 4 }, { format: 'currency' }),
            kpi('woocommerce', 'discount', 'Descuentos', { x: 6, y: 21, w: 3, h: 4 }, { format: 'currency' }),
            kpi('woocommerce', 'refunds', 'Reembolsos', { x: 9, y: 21, w: 3, h: 4 }),
            summary({ x: 0, y: 25, w: 12, h: 4 }),
            cta(29),
        ],
    },
    {
        key: 'ga4_web',
        name: 'Analítica web (GA4)',
        description: 'Sitios de contenido: usuarios, interacción, páginas, fuentes, países y dispositivos.',
        build: () => [
            header('Analítica de tu sitio'),
            kpi('ga4', 'users', 'Usuarios', { x: 0, y: 2, w: 3, h: 4 }),
            kpi('ga4', 'new_users', 'Usuarios nuevos', { x: 3, y: 2, w: 3, h: 4 }),
            kpi('ga4', 'sessions', 'Sesiones', { x: 6, y: 2, w: 3, h: 4 }),
            kpi('ga4', 'engagement_rate', 'Interacción', { x: 9, y: 2, w: 3, h: 4 }, { format: 'percent' }),
            spec({
                type: 'chart',
                binding: { source: 'ga4', metric: 'sessions_by_date' },
                props: { chartType: 'area', title: 'Sesiones por día' },
                layout: { x: 0, y: 6, w: 8, h: 7 },
            }),
            kpi('ga4', 'screen_page_views', 'Páginas vistas', { x: 8, y: 6, w: 4, h: 3 }, { format: 'compact' }),
            kpi('ga4', 'avg_session_duration', 'Duración media (s)', { x: 8, y: 9, w: 4, h: 4 }),
            spec({
                type: 'table',
                binding: { source: 'ga4', metric: 'top_pages' },
                props: { title: 'Páginas más vistas' },
                style: { bars: true },
                layout: { x: 0, y: 13, w: 6, h: 7 },
            }),
            spec({
                type: 'chart',
                binding: { source: 'ga4', metric: 'traffic_sources' },
                props: { chartType: 'donut', title: 'Fuentes de tráfico' },
                style: { legend: true },
                layout: { x: 6, y: 13, w: 6, h: 7 },
            }),
            spec({
                type: 'table',
                binding: { source: 'ga4', metric: 'top_countries' },
                props: { title: 'Países' },
                layout: { x: 0, y: 20, w: 6, h: 7 },
            }),
            spec({
                type: 'chart',
                binding: { source: 'ga4', metric: 'devices' },
                props: { chartType: 'donut', title: 'Dispositivos' },
                style: { legend: true },
                layout: { x: 6, y: 20, w: 6, h: 7 },
            }),
            summary({ x: 0, y: 27, w: 12, h: 4 }),
            cta(31),
        ],
    },
    {
        key: 'ga4_ecommerce',
        name: 'E-commerce (GA4)',
        description: 'Analítica de tienda en Google Analytics: ingresos, transacciones, productos y conversión.',
        build: () => [
            header('Rendimiento de tu tienda'),
            kpi('ga4', 'revenue', 'Ingresos', { x: 0, y: 2, w: 3, h: 4 }, { format: 'currency' }),
            kpi('ga4', 'transactions', 'Transacciones', { x: 3, y: 2, w: 3, h: 4 }),
            kpi('ga4', 'purchases', 'Compras', { x: 6, y: 2, w: 3, h: 4 }),
            kpi('ga4', 'avg_purchase_revenue', 'Ticket medio', { x: 9, y: 2, w: 3, h: 4 }, { format: 'currency' }),
            spec({
                type: 'chart',
                binding: { source: 'ga4', metric: 'revenue_by_date' },
                props: { chartType: 'area', title: 'Ingresos por día' },
                style: { format: 'currency' },
                layout: { x: 0, y: 6, w: 8, h: 8 },
            }),
            kpi('ga4', 'items_purchased', 'Artículos comprados', { x: 8, y: 6, w: 4, h: 4 }),
            kpi('ga4', 'purchaser_conversion_rate', 'Conversión a compra', { x: 8, y: 10, w: 4, h: 4 }, { format: 'percent' }),
            spec({
                type: 'table',
                binding: { source: 'ga4', metric: 'top_products' },
                props: { title: 'Productos top (ingresos)' },
                style: { bars: true },
                layout: { x: 0, y: 14, w: 6, h: 7 },
            }),
            spec({
                type: 'chart',
                binding: { source: 'ga4', metric: 'purchases_by_date' },
                props: { chartType: 'bar', title: 'Compras por día' },
                layout: { x: 6, y: 14, w: 6, h: 7 },
            }),
            spec({
                type: 'chart',
                binding: { source: 'ga4', metric: 'traffic_sources' },
                props: { chartType: 'donut', title: 'Fuentes de tráfico' },
                style: { legend: true },
                layout: { x: 0, y: 21, w: 6, h: 7 },
            }),
            summary({ x: 6, y: 21, w: 6, h: 7 }),
            cta(28),
        ],
    },
    {
        key: 'seo',
        name: 'SEO y tráfico',
        description: 'Posicionamiento y audiencia: GA4 + Search Console con tendencia y top de búsquedas.',
        build: () => [
            header('Tráfico y posicionamiento'),
            kpi('ga4', 'sessions', 'Visitas', { x: 0, y: 2, w: 3, h: 4 }),
            kpi('ga4', 'users', 'Usuarios', { x: 3, y: 2, w: 3, h: 4 }),
            kpi('gsc', 'clicks', 'Clics en Google', { x: 6, y: 2, w: 3, h: 4 }),
            kpi('gsc', 'impressions', 'Impresiones', { x: 9, y: 2, w: 3, h: 4 }, { format: 'compact' }),
            spec({
                type: 'chart',
                binding: { source: 'ga4', metric: 'sessions_by_date' },
                props: { chartType: 'area', title: 'Tendencia de visitas' },
                layout: { x: 0, y: 6, w: 8, h: 7 },
            }),
            kpi('gsc', 'position', 'Posición media', { x: 8, y: 6, w: 4, h: 3 }),
            kpi('gsc', 'ctr', 'CTR', { x: 8, y: 9, w: 4, h: 4 }, { format: 'percent' }),
            spec({
                type: 'table',
                binding: { source: 'gsc', metric: 'top_queries' },
                props: { title: 'Búsquedas que te encuentran' },
                layout: { x: 0, y: 13, w: 6, h: 7 },
            }),
            spec({
                type: 'table',
                binding: { source: 'ga4', metric: 'top_pages' },
                props: { title: 'Páginas más vistas' },
                style: { bars: true },
                layout: { x: 6, y: 13, w: 6, h: 7 },
            }),
            spec({
                type: 'chart',
                binding: { source: 'gsc', metric: 'clicks_by_date' },
                props: { chartType: 'area', title: 'Clics en Google por día' },
                layout: { x: 0, y: 20, w: 7, h: 7 },
            }),
            spec({
                type: 'table',
                binding: { source: 'gsc', metric: 'by_device' },
                props: { title: 'Por dispositivo' },
                layout: { x: 7, y: 20, w: 5, h: 7 },
            }),
            spec({
                type: 'chart',
                binding: { source: 'ga4', metric: 'traffic_sources' },
                props: { chartType: 'donut', title: 'Fuentes de tráfico' },
                style: { legend: true },
                layout: { x: 0, y: 27, w: 5, h: 7 },
            }),
            summary({ x: 5, y: 27, w: 7, h: 7 }),
            cta(34),
        ],
    },
    {
        key: 'hourly_support',
        name: 'Soporte por horas',
        description: 'Justifica el plan: horas invertidas vs. plan, desglose por categoría y trabajo realizado.',
        build: () => [
            header('Tu plan de soporte este mes'),
            kpi('worklog', 'hours', 'Horas invertidas', { x: 0, y: 2, w: 4, h: 4 }),
            kpi('worklog', 'tasks', 'Tareas realizadas', { x: 4, y: 2, w: 4, h: 4 }),
            spec({
                type: 'goal',
                binding: { source: 'worklog', metric: 'hours_vs_plan' },
                props: { label: 'Horas vs. plan' },
                layout: { x: 8, y: 2, w: 4, h: 4 },
            }),
            spec({
                type: 'chart',
                binding: { source: 'worklog', metric: 'by_category' },
                props: { chartType: 'donut', title: 'Horas por categoría' },
                style: { legend: true },
                layout: { x: 0, y: 6, w: 5, h: 8 },
            }),
            spec({
                type: 'worklog_timeline',
                props: { title: 'Lo que hicimos este mes' },
                layout: { x: 5, y: 6, w: 7, h: 8 },
            }),
            summary({ x: 0, y: 14, w: 12, h: 4 }),
            cta(18),
        ],
    },
    {
        key: 'security',
        name: 'Seguridad y mantenimiento',
        description: 'El trabajo invisible: salud del sitio, ataques bloqueados, uptime y updates aplicadas.',
        build: () => [
            header('Seguridad y mantenimiento'),
            spec({ type: 'healthscore', props: { title: 'Estado general' }, layout: { x: 0, y: 2, w: 4, h: 7 } }),
            spec({ type: 'security_shield', props: { title: 'Tu sitio, protegido' }, layout: { x: 4, y: 2, w: 8, h: 7 } }),
            kpi('cloudflare', 'threats_blocked', 'Amenazas bloqueadas', { x: 0, y: 9, w: 3, h: 4 }),
            kpi('crowdsec', 'attacks_blocked', 'Ataques bloqueados', { x: 3, y: 9, w: 3, h: 4 }),
            kpi('mainwp', 'updates_applied', 'Updates aplicadas', { x: 6, y: 9, w: 3, h: 4 }),
            kpi('betteruptime', 'uptime_percent', 'Disponibilidad', { x: 9, y: 9, w: 3, h: 4 }, { format: 'percent' }),
            spec({
                type: 'table',
                binding: { source: 'crowdsec', metric: 'attack_types' },
                props: { title: 'Tipos de ataque bloqueados' },
                layout: { x: 0, y: 13, w: 6, h: 7 },
            }),
            spec({
                type: 'worklog_timeline',
                props: { title: 'Lo que hicimos este mes' },
                layout: { x: 6, y: 13, w: 6, h: 7 },
            }),
            summary({ x: 0, y: 20, w: 12, h: 4 }),
            cta(24),
        ],
    },
    {
        key: 'cloudflare',
        name: 'Cloudflare (CDN y seguridad)',
        description: 'Tráfico, caché y ancho de banda + amenazas bloqueadas por día y por país.',
        build: () => [
            header('Rendimiento y seguridad de tu sitio'),
            kpi('cloudflare', 'requests', 'Peticiones', { x: 0, y: 2, w: 3, h: 4 }, { format: 'compact' }),
            kpi('cloudflare', 'cache_ratio', 'Ratio de caché', { x: 3, y: 2, w: 3, h: 4 }, { format: 'percent' }),
            kpi('cloudflare', 'threats_blocked', 'Amenazas bloqueadas', { x: 6, y: 2, w: 3, h: 4 }),
            kpi('cloudflare', 'unique_visitors', 'Visitantes únicos', { x: 9, y: 2, w: 3, h: 4 }, { format: 'compact' }),
            spec({
                type: 'chart',
                binding: { source: 'cloudflare', metric: 'requests_by_date' },
                props: { chartType: 'area', title: 'Peticiones por día' },
                layout: { x: 0, y: 6, w: 8, h: 7 },
            }),
            kpi('cloudflare', 'page_views', 'Páginas vistas', { x: 8, y: 6, w: 4, h: 3 }, { format: 'compact' }),
            kpi('cloudflare', 'bandwidth', 'Ancho de banda', { x: 8, y: 9, w: 4, h: 4 }, { format: 'compact' }),
            spec({
                type: 'chart',
                binding: { source: 'cloudflare', metric: 'threats_by_date' },
                props: { chartType: 'bar', title: 'Amenazas bloqueadas por día' },
                layout: { x: 0, y: 13, w: 6, h: 7 },
            }),
            spec({
                type: 'table',
                binding: { source: 'cloudflare', metric: 'threats_by_country' },
                props: { title: 'Amenazas por país' },
                style: { bars: true },
                layout: { x: 6, y: 13, w: 6, h: 7 },
            }),
            spec({
                type: 'table',
                binding: { source: 'cloudflare', metric: 'top_threat_sources' },
                props: { title: 'Tipos de amenaza' },
                layout: { x: 0, y: 20, w: 6, h: 7 },
            }),
            spec({
                type: 'table',
                binding: { source: 'cloudflare', metric: 'requests_by_country' },
                props: { title: 'Peticiones por país' },
                layout: { x: 6, y: 20, w: 6, h: 7 },
            }),
            summary({ x: 0, y: 27, w: 12, h: 4 }),
            cta(31),
        ],
    },
    {
        key: 'uptime',
        name: 'Disponibilidad y SLA',
        description: 'Uptime, incidentes y tiempo caído (Better Stack) + el trabajo del mes.',
        build: () => [
            header('Disponibilidad de tu sitio'),
            spec({ type: 'healthscore', props: { title: 'Estado general' }, layout: { x: 0, y: 2, w: 4, h: 7 } }),
            kpi('betteruptime', 'uptime_percent', 'Disponibilidad', { x: 4, y: 2, w: 4, h: 4 }, { format: 'percent' }),
            kpi('betteruptime', 'incidents', 'Incidentes', { x: 8, y: 2, w: 4, h: 4 }),
            kpi('betteruptime', 'total_downtime', 'Tiempo caído (s)', { x: 4, y: 6, w: 4, h: 3 }),
            kpi('betteruptime', 'longest_incident', 'Incidente más largo (s)', { x: 8, y: 6, w: 4, h: 3 }),
            spec({
                type: 'worklog_timeline',
                props: { title: 'Lo que hicimos este mes' },
                layout: { x: 0, y: 9, w: 12, h: 7 },
            }),
            summary({ x: 0, y: 16, w: 12, h: 4 }),
            cta(20),
        ],
    },
    {
        key: 'crowdsec',
        name: 'Seguridad de red (CrowdSec)',
        description: 'Ataques bloqueados, IPs atacantes, tipos de ataque y procedencia por país.',
        build: () => [
            header('Seguridad de red'),
            kpi('crowdsec', 'attacks_blocked', 'Ataques bloqueados', { x: 0, y: 2, w: 3, h: 4 }),
            kpi('crowdsec', 'alerts', 'Alertas', { x: 3, y: 2, w: 3, h: 4 }),
            kpi('crowdsec', 'unique_ips', 'IPs atacantes', { x: 6, y: 2, w: 3, h: 4 }),
            kpi('crowdsec', 'events', 'Eventos maliciosos', { x: 9, y: 2, w: 3, h: 4 }, { format: 'compact' }),
            spec({
                type: 'chart',
                binding: { source: 'crowdsec', metric: 'attack_types' },
                props: { chartType: 'donut', title: 'Tipos de ataque' },
                style: { legend: true },
                layout: { x: 0, y: 6, w: 5, h: 8 },
            }),
            spec({
                type: 'table',
                binding: { source: 'crowdsec', metric: 'top_attacker_ips' },
                props: { title: 'IPs más activas' },
                style: { bars: true },
                layout: { x: 5, y: 6, w: 7, h: 8 },
            }),
            spec({
                type: 'table',
                binding: { source: 'crowdsec', metric: 'attacks_by_country' },
                props: { title: 'Ataques por país' },
                style: { bars: true },
                layout: { x: 0, y: 14, w: 6, h: 7 },
            }),
            spec({
                type: 'worklog_timeline',
                props: { title: 'Lo que hicimos este mes' },
                layout: { x: 6, y: 14, w: 6, h: 7 },
            }),
            summary({ x: 0, y: 21, w: 12, h: 4 }),
            cta(25),
        ],
    },
];
