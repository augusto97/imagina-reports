import type { Block, BlockBinding, BlockLayout, BlockType } from '@shared/blocks/types';

import { makeBlock } from './blockFactory';

interface BlockSpec {
    type: BlockType;
    binding?: BlockBinding;
    props?: Record<string, unknown>;
    style?: Record<string, unknown>;
    layout?: BlockLayout;
    page?: number;
}

/** Build a fresh block (new id) from a spec, merging over the factory defaults. */
function spec({ type, binding, props, style, layout, page }: BlockSpec): Block {
    const block = makeBlock(type);
    if (binding !== undefined) {
        block.binding = binding;
    }
    block.props = { ...block.props, ...props };
    block.style = { ...block.style, ...style };
    if (layout !== undefined) {
        block.layout = layout;
    }
    if (page !== undefined) {
        block.page = page;
    }

    return block;
}

/* --------------------------- block helpers (rich) -------------------------- */

/** A branded report header (eyebrow + title + client·site·period subtitle, via merge fields). */
const header = (title = 'Informe mensual', layout: BlockLayout = { x: 0, y: 0, w: 12, h: 2 }, page = 0): Block =>
    spec({ type: 'header', props: { title, eyebrow: '{{agency}}', subtitle: '{{client}} · {{site}} · {{period}}' }, layout, page });

/** Full-page branded cover (portada) — self-fills from agency logo + client/site/period. */
const cover = (title = 'Informe de soporte y rendimiento', page = 0): Block =>
    spec({ type: 'cover', props: { title, subtitle: 'Resumen del trabajo realizado y el rendimiento de tu sitio este periodo.', showScore: true }, layout: { x: 0, y: 0, w: 12, h: 16 }, page });

/** Full-page closing (contraportada) with the retention message + agency contact line. */
const backCover = (page = 0): Block =>
    spec({
        type: 'back_cover',
        props: {
            headline: 'Tu plan de soporte está activo y protegiendo tu sitio.',
            text: 'Gracias por confiar en nosotros para cuidar tu sitio cada mes. Cualquier duda, estamos a un mensaje de distancia.',
            contact: '{{agency}}',
        },
        layout: { x: 0, y: 0, w: 12, h: 16 },
        page,
    });

/** A KPI card bound to a metric, compared vs. the previous period. */
const kpi = (source: string, metric: string, label: string, layout: BlockLayout, style: Record<string, unknown> = {}, page = 0): Block =>
    spec({ type: 'kpi', binding: { source, metric, compare: 'prev_period' }, props: { label }, style, layout, page });

/** A chart (line/area/bar) bound to a time-series metric. */
const chart = (source: string, metric: string, chartType: string, title: string, layout: BlockLayout, style: Record<string, unknown> = {}, page = 0): Block =>
    spec({ type: 'chart', binding: { source, metric }, props: { chartType, title }, style, layout, page });

/** A donut chart with legend (breakdowns: sources, devices, categories…). */
const donut = (source: string, metric: string, title: string, layout: BlockLayout, page = 0): Block =>
    spec({ type: 'chart', binding: { source, metric }, props: { chartType: 'donut', title }, style: { legend: true }, layout, page });

/** A table bound to a list metric. */
const table = (source: string, metric: string, title: string, layout: BlockLayout, style: Record<string, unknown> = {}, page = 0): Block =>
    spec({ type: 'table', binding: { source, metric }, props: { title }, style, layout, page });

/** A world choropleth bound to a country metric ({label: country, value}). */
const geo = (source: string, metric: string, title: string, layout: BlockLayout, page = 0): Block =>
    spec({ type: 'geo_map', binding: { source, metric }, props: { title }, layout, page });

/** A goal/target block (current value vs. a target, e.g. hours vs. plan). */
const goal = (source: string, metric: string, label: string, layout: BlockLayout, page = 0): Block =>
    spec({ type: 'goal', binding: { source, metric }, props: { label }, layout, page });

/** The 0–100 health-score gauge (reads the computed score; no binding). */
const healthscore = (layout: BlockLayout, page = 0, title = 'Estado general'): Block =>
    spec({ type: 'healthscore', props: { title }, layout, page });

/** The security shield visual (threats/attacks/malware reassurance). */
const shield = (layout: BlockLayout, page = 0, title = 'Tu sitio, protegido'): Block =>
    spec({ type: 'security_shield', props: { title }, layout, page });

/** The "what we did this month" dated timeline. */
const worklog = (layout: BlockLayout, page = 0, title = 'Lo que hicimos este mes'): Block =>
    spec({ type: 'worklog_timeline', props: { title }, layout, page });

/** Client-visible team notes (overlaid at serve time; hidden when empty). */
const comments = (layout: BlockLayout, page = 0): Block =>
    spec({ type: 'comments', props: { title: 'Notas del equipo' }, layout, page });

/** The AI-filled executive summary narrative (the §11.5 plain-language paragraph). */
const summary = (layout: BlockLayout, page = 0): Block =>
    spec({ type: 'narrative', props: { variant: 'executive_summary', title: 'Resumen del mes' }, layout, page });

/** The retention CTA that closes single-page reports. */
const cta = (y: number, page = 0): Block => spec({ type: 'cta', layout: { x: 0, y, w: 12, h: 3 }, page });

export interface GalleryTemplate {
    key: string;
    name: string;
    description: string;
    build: () => Block[];
    /** Named pages, indexed by page — set when the template is multi-page (paginated). */
    pages?: string[];
}

/**
 * Starter templates by vertical (CLAUDE.md §10.2 / §11.5 — "ready starting points").
 * Each ships an explicit 12-column grid layout so it opens as a polished, intentional
 * dashboard (not auto-flowed). Bindings use real connector metric keys; blocks whose
 * metric has no data are gracefully hidden at generation, so a template never shows an
 * empty box. Most close with a client-visible "Notas del equipo" block (hidden when empty)
 * and the retention CTA.
 */
export const GALLERY: GalleryTemplate[] = [
    {
        key: 'unified',
        name: '★ Reporte 360 unificado (paginado)',
        description: 'El informe total: portada, resumen ejecutivo con health score, seguridad, tráfico y SEO, ventas y mantenimiento, cada uno en su propia página, y contraportada. Reúne TODAS las fuentes; las páginas o bloques sin datos se ocultan solos.',
        pages: ['Portada', 'Resumen', 'Seguridad', 'Tráfico y SEO', 'Ventas', 'Mantenimiento', 'Cierre'],
        build: () => [
            // ── Page 0 — Cover ────────────────────────────────────────────────
            cover('Informe 360 de tu sitio', 0),

            // ── Page 1 — Executive summary ───────────────────────────────────
            header('Resumen del mes', { x: 0, y: 0, w: 12, h: 2 }, 1),
            healthscore({ x: 0, y: 2, w: 4, h: 8 }, 1),
            kpi('betteruptime', 'uptime_percent', 'Disponibilidad', { x: 4, y: 2, w: 4, h: 4 }, { format: 'percent' }, 1),
            kpi('cloudflare', 'threats_blocked', 'Amenazas bloqueadas', { x: 8, y: 2, w: 4, h: 4 }, {}, 1),
            kpi('mainwp', 'updates_applied', 'Updates aplicadas', { x: 4, y: 6, w: 4, h: 4 }, {}, 1),
            kpi('ga4', 'sessions', 'Visitas', { x: 8, y: 6, w: 4, h: 4 }, {}, 1),
            kpi('woocommerce', 'revenue', 'Ingresos', { x: 0, y: 10, w: 4, h: 4 }, { format: 'currency' }, 1),
            kpi('gsc', 'clicks', 'Clics en Google', { x: 4, y: 10, w: 4, h: 4 }, {}, 1),
            kpi('worklog', 'hours', 'Horas de soporte', { x: 8, y: 10, w: 4, h: 4 }, {}, 1),
            summary({ x: 0, y: 14, w: 12, h: 5 }, 1),

            // ── Page 2 — Security ────────────────────────────────────────────
            header('Seguridad y protección', { x: 0, y: 0, w: 12, h: 2 }, 2),
            shield({ x: 0, y: 2, w: 8, h: 5 }, 2),
            kpi('cloudflare', 'threats_blocked', 'Amenazas (Cloudflare)', { x: 8, y: 2, w: 4, h: 5 }, {}, 2),
            kpi('mainwp', 'malware_found', 'Malware detectado', { x: 0, y: 7, w: 3, h: 4 }, {}, 2),
            kpi('mainwp', 'vulnerabilities_count', 'Vulnerabilidades', { x: 3, y: 7, w: 3, h: 4 }, {}, 2),
            kpi('mainwp', 'wordfence_scans_count', 'Escaneos Wordfence', { x: 6, y: 7, w: 3, h: 4 }, {}, 2),
            kpi('cloudflare', 'cache_ratio', 'Ratio de caché', { x: 9, y: 7, w: 3, h: 4 }, { format: 'percent' }, 2),
            chart('cloudflare', 'threats_by_date', 'bar', 'Amenazas bloqueadas por día', { x: 0, y: 11, w: 7, h: 7 }, {}, 2),
            geo('cloudflare', 'threats_by_country', 'Origen de las amenazas', { x: 7, y: 11, w: 5, h: 7 }, 2),
            table('mainwp', 'wordfence_scans', 'Escaneos de seguridad', { x: 0, y: 18, w: 6, h: 7 }, {}, 2),
            table('mainwp', 'security_checklist', 'Estado de seguridad', { x: 6, y: 18, w: 6, h: 7 }, {}, 2),

            // ── Page 3 — Traffic & SEO ───────────────────────────────────────
            header('Tráfico y posicionamiento', { x: 0, y: 0, w: 12, h: 2 }, 3),
            kpi('ga4', 'users', 'Usuarios', { x: 0, y: 2, w: 3, h: 4 }, {}, 3),
            kpi('ga4', 'sessions', 'Sesiones', { x: 3, y: 2, w: 3, h: 4 }, {}, 3),
            kpi('gsc', 'impressions', 'Impresiones', { x: 6, y: 2, w: 3, h: 4 }, { format: 'compact' }, 3),
            kpi('gsc', 'position', 'Posición media', { x: 9, y: 2, w: 3, h: 4 }, {}, 3),
            chart('ga4', 'sessions_by_date', 'area', 'Sesiones por día', { x: 0, y: 6, w: 8, h: 7 }, {}, 3),
            donut('ga4', 'traffic_sources', 'Fuentes de tráfico', { x: 8, y: 6, w: 4, h: 7 }, 3),
            table('gsc', 'top_queries', 'Búsquedas que te encuentran', { x: 0, y: 13, w: 6, h: 7 }, {}, 3),
            table('ga4', 'top_pages', 'Páginas más vistas', { x: 6, y: 13, w: 6, h: 7 }, { bars: true }, 3),
            geo('ga4', 'top_countries', 'De dónde te visitan', { x: 0, y: 20, w: 7, h: 7 }, 3),
            donut('ga4', 'devices', 'Dispositivos', { x: 7, y: 20, w: 5, h: 7 }, 3),

            // ── Page 4 — Sales (Woo) ─────────────────────────────────────────
            header('Ventas de tu tienda', { x: 0, y: 0, w: 12, h: 2 }, 4),
            kpi('woocommerce', 'revenue', 'Ingresos', { x: 0, y: 2, w: 3, h: 4 }, { format: 'currency' }, 4),
            kpi('woocommerce', 'orders', 'Pedidos', { x: 3, y: 2, w: 3, h: 4 }, {}, 4),
            kpi('woocommerce', 'new_customers', 'Clientes nuevos', { x: 6, y: 2, w: 3, h: 4 }, {}, 4),
            kpi('woocommerce', 'average_sales', 'Venta media diaria', { x: 9, y: 2, w: 3, h: 4 }, { format: 'currency' }, 4),
            chart('woocommerce', 'revenue_by_date', 'area', 'Ingresos por día', { x: 0, y: 6, w: 8, h: 7 }, { format: 'currency' }, 4),
            kpi('woocommerce', 'items_sold', 'Artículos vendidos', { x: 8, y: 6, w: 4, h: 3 }, {}, 4),
            kpi('woocommerce', 'refunds', 'Reembolsos', { x: 8, y: 9, w: 4, h: 4 }, {}, 4),
            table('woocommerce', 'top_products', 'Productos más vendidos', { x: 0, y: 13, w: 6, h: 7 }, {}, 4),
            chart('woocommerce', 'orders_by_date', 'bar', 'Pedidos por día', { x: 6, y: 13, w: 6, h: 7 }, {}, 4),

            // ── Page 5 — Maintenance ─────────────────────────────────────────
            header('Mantenimiento y soporte', { x: 0, y: 0, w: 12, h: 2 }, 5),
            kpi('mainwp', 'updates_applied', 'Updates aplicadas', { x: 0, y: 2, w: 3, h: 4 }, {}, 5),
            kpi('mainwp', 'updates_available', 'Pendientes', { x: 3, y: 2, w: 3, h: 4 }, {}, 5),
            goal('worklog', 'hours_vs_plan', 'Horas vs. plan', { x: 6, y: 2, w: 3, h: 4 }, 5),
            kpi('mainwp', 'ssl_days_remaining', 'Días de SSL', { x: 9, y: 2, w: 3, h: 4 }, { format: 'number' }, 5),
            table('mainwp', 'work_log', 'Cada actualización aplicada', { x: 0, y: 6, w: 7, h: 8 }, {}, 5),
            worklog({ x: 7, y: 6, w: 5, h: 8 }, 5),
            chart('betteruptime', 'uptime_by_date', 'bar', 'Disponibilidad por día (%)', { x: 0, y: 14, w: 12, h: 5 }, { threshold: 100 }, 5),
            comments({ x: 0, y: 19, w: 12, h: 5 }, 5),

            // ── Page 6 — Back cover ──────────────────────────────────────────
            backCover(6),
        ],
    },
    {
        key: 'woocommerce',
        name: 'Tienda WooCommerce',
        description: 'Reporte completo de e-commerce: ingresos, pedidos, productos, tendencia diaria y desglose fiscal.',
        build: () => [
            header('Reporte de tu tienda'),
            kpi('woocommerce', 'revenue', 'Ingresos', { x: 0, y: 2, w: 3, h: 4 }, { format: 'currency' }),
            kpi('woocommerce', 'net_revenue', 'Ingresos netos', { x: 3, y: 2, w: 3, h: 4 }, { format: 'currency' }),
            kpi('woocommerce', 'orders', 'Pedidos', { x: 6, y: 2, w: 3, h: 4 }),
            kpi('woocommerce', 'new_customers', 'Clientes nuevos', { x: 9, y: 2, w: 3, h: 4 }),
            chart('woocommerce', 'revenue_by_date', 'area', 'Ingresos por día', { x: 0, y: 6, w: 8, h: 8 }, { format: 'currency' }),
            kpi('woocommerce', 'items_sold', 'Artículos vendidos', { x: 8, y: 6, w: 4, h: 4 }),
            kpi('woocommerce', 'average_sales', 'Venta media diaria', { x: 8, y: 10, w: 4, h: 4 }, { format: 'currency' }),
            table('woocommerce', 'top_products', 'Productos más vendidos', { x: 0, y: 14, w: 6, h: 7 }),
            chart('woocommerce', 'orders_by_date', 'bar', 'Pedidos por día', { x: 6, y: 14, w: 6, h: 7 }),
            kpi('woocommerce', 'tax', 'Impuestos', { x: 0, y: 21, w: 3, h: 4 }, { format: 'currency' }),
            kpi('woocommerce', 'shipping', 'Envíos', { x: 3, y: 21, w: 3, h: 4 }, { format: 'currency' }),
            kpi('woocommerce', 'discount', 'Descuentos', { x: 6, y: 21, w: 3, h: 4 }, { format: 'currency' }),
            kpi('woocommerce', 'refunds', 'Reembolsos', { x: 9, y: 21, w: 3, h: 4 }),
            summary({ x: 0, y: 25, w: 12, h: 4 }),
            comments({ x: 0, y: 29, w: 12, h: 4 }),
            cta(33),
        ],
    },
    {
        key: 'ga4_web',
        name: 'Analítica web (GA4)',
        description: 'Sitios de contenido: usuarios, interacción, páginas, fuentes, mapa de países, ciudades y dispositivos.',
        build: () => [
            header('Analítica de tu sitio'),
            kpi('ga4', 'users', 'Usuarios', { x: 0, y: 2, w: 3, h: 4 }),
            kpi('ga4', 'new_users', 'Usuarios nuevos', { x: 3, y: 2, w: 3, h: 4 }),
            kpi('ga4', 'sessions', 'Sesiones', { x: 6, y: 2, w: 3, h: 4 }),
            kpi('ga4', 'engagement_rate', 'Interacción', { x: 9, y: 2, w: 3, h: 4 }, { format: 'percent' }),
            chart('ga4', 'sessions_by_date', 'area', 'Sesiones por día', { x: 0, y: 6, w: 8, h: 7 }),
            kpi('ga4', 'screen_page_views', 'Páginas vistas', { x: 8, y: 6, w: 4, h: 3 }, { format: 'compact' }),
            kpi('ga4', 'avg_session_duration', 'Duración media (s)', { x: 8, y: 9, w: 4, h: 4 }, { format: 'duration' }),
            table('ga4', 'top_pages', 'Páginas más vistas', { x: 0, y: 13, w: 6, h: 7 }, { bars: true }),
            donut('ga4', 'traffic_sources', 'Fuentes de tráfico', { x: 6, y: 13, w: 6, h: 7 }),
            geo('ga4', 'top_countries', 'De dónde te visitan', { x: 0, y: 20, w: 7, h: 7 }),
            donut('ga4', 'devices', 'Dispositivos', { x: 7, y: 20, w: 5, h: 7 }),
            table('ga4', 'top_cities', 'Ciudades', { x: 0, y: 27, w: 6, h: 7 }, { bars: true }),
            chart('ga4', 'sessions_by_hour', 'bar', 'Visitas por hora del día', { x: 6, y: 27, w: 6, h: 7 }),
            summary({ x: 0, y: 34, w: 12, h: 4 }),
            comments({ x: 0, y: 38, w: 12, h: 4 }),
            cta(42),
        ],
    },
    {
        key: 'ga4_ecommerce',
        name: 'E-commerce (GA4)',
        description: 'Analítica de tienda en Google Analytics: ingresos, transacciones, productos, conversión y fuentes.',
        build: () => [
            header('Rendimiento de tu tienda'),
            kpi('ga4', 'revenue', 'Ingresos', { x: 0, y: 2, w: 3, h: 4 }, { format: 'currency' }),
            kpi('ga4', 'transactions', 'Transacciones', { x: 3, y: 2, w: 3, h: 4 }),
            kpi('ga4', 'purchases', 'Compras', { x: 6, y: 2, w: 3, h: 4 }),
            kpi('ga4', 'avg_purchase_revenue', 'Ticket medio', { x: 9, y: 2, w: 3, h: 4 }, { format: 'currency' }),
            chart('ga4', 'revenue_by_date', 'area', 'Ingresos por día', { x: 0, y: 6, w: 8, h: 8 }, { format: 'currency' }),
            kpi('ga4', 'items_purchased', 'Artículos comprados', { x: 8, y: 6, w: 4, h: 4 }),
            kpi('ga4', 'purchaser_conversion_rate', 'Conversión a compra', { x: 8, y: 10, w: 4, h: 4 }, { format: 'percent' }),
            table('ga4', 'top_products', 'Productos top (ingresos)', { x: 0, y: 14, w: 6, h: 7 }, { bars: true }),
            chart('ga4', 'purchases_by_date', 'bar', 'Compras por día', { x: 6, y: 14, w: 6, h: 7 }),
            donut('ga4', 'traffic_sources', 'Fuentes de tráfico', { x: 0, y: 21, w: 6, h: 7 }),
            summary({ x: 6, y: 21, w: 6, h: 7 }),
            comments({ x: 0, y: 28, w: 12, h: 4 }),
            cta(32),
        ],
    },
    {
        key: 'seo',
        name: 'SEO y tráfico',
        description: 'Posicionamiento y audiencia: GA4 + Search Console con tendencia, top de búsquedas, mapa de países y dispositivos.',
        build: () => [
            header('Tráfico y posicionamiento'),
            kpi('ga4', 'sessions', 'Visitas', { x: 0, y: 2, w: 3, h: 4 }),
            kpi('ga4', 'users', 'Usuarios', { x: 3, y: 2, w: 3, h: 4 }),
            kpi('gsc', 'clicks', 'Clics en Google', { x: 6, y: 2, w: 3, h: 4 }),
            kpi('gsc', 'impressions', 'Impresiones', { x: 9, y: 2, w: 3, h: 4 }, { format: 'compact' }),
            chart('ga4', 'sessions_by_date', 'area', 'Tendencia de visitas', { x: 0, y: 6, w: 8, h: 7 }),
            kpi('gsc', 'position', 'Posición media', { x: 8, y: 6, w: 4, h: 3 }),
            kpi('gsc', 'ctr', 'CTR', { x: 8, y: 9, w: 4, h: 4 }, { format: 'percent' }),
            table('gsc', 'top_queries', 'Búsquedas que te encuentran', { x: 0, y: 13, w: 6, h: 7 }),
            table('ga4', 'top_pages', 'Páginas más vistas', { x: 6, y: 13, w: 6, h: 7 }, { bars: true }),
            chart('gsc', 'clicks_by_date', 'area', 'Clics en Google por día', { x: 0, y: 20, w: 7, h: 7 }),
            table('gsc', 'by_device', 'Por dispositivo', { x: 7, y: 20, w: 5, h: 7 }),
            geo('ga4', 'top_countries', 'De dónde te visitan', { x: 0, y: 27, w: 7, h: 7 }),
            donut('ga4', 'traffic_sources', 'Fuentes de tráfico', { x: 7, y: 27, w: 5, h: 7 }),
            summary({ x: 0, y: 34, w: 12, h: 4 }),
            comments({ x: 0, y: 38, w: 12, h: 4 }),
            cta(42),
        ],
    },
    {
        key: 'hourly_support',
        name: 'Soporte por horas',
        description: 'Justifica el plan: horas invertidas vs. plan, desglose por categoría y el trabajo realizado con fecha.',
        build: () => [
            header('Tu plan de soporte este mes'),
            kpi('worklog', 'hours', 'Horas invertidas', { x: 0, y: 2, w: 4, h: 4 }),
            kpi('worklog', 'tasks', 'Tareas realizadas', { x: 4, y: 2, w: 4, h: 4 }),
            goal('worklog', 'hours_vs_plan', 'Horas vs. plan', { x: 8, y: 2, w: 4, h: 4 }),
            donut('worklog', 'by_category', 'Horas por categoría', { x: 0, y: 6, w: 5, h: 8 }),
            worklog({ x: 5, y: 6, w: 7, h: 8 }),
            summary({ x: 0, y: 14, w: 12, h: 4 }),
            comments({ x: 0, y: 18, w: 12, h: 4 }),
            cta(22),
        ],
    },
    {
        key: 'security',
        name: 'Seguridad y mantenimiento',
        description: 'El trabajo invisible: salud del sitio, ataques bloqueados, uptime, updates aplicadas y mapa de amenazas.',
        build: () => [
            header('Seguridad y mantenimiento'),
            healthscore({ x: 0, y: 2, w: 4, h: 7 }),
            shield({ x: 4, y: 2, w: 8, h: 7 }),
            kpi('cloudflare', 'threats_blocked', 'Amenazas bloqueadas', { x: 0, y: 9, w: 3, h: 4 }),
            kpi('mainwp', 'malware_found', 'Malware detectado', { x: 3, y: 9, w: 3, h: 4 }),
            kpi('mainwp', 'updates_applied', 'Updates aplicadas', { x: 6, y: 9, w: 3, h: 4 }),
            kpi('betteruptime', 'uptime_percent', 'Disponibilidad', { x: 9, y: 9, w: 3, h: 4 }, { format: 'percent' }),
            chart('cloudflare', 'threats_by_date', 'bar', 'Amenazas por día', { x: 0, y: 13, w: 6, h: 7 }),
            geo('cloudflare', 'threats_by_country', 'Origen de las amenazas', { x: 6, y: 13, w: 6, h: 7 }),
            table('mainwp', 'security_checklist', 'Estado de seguridad', { x: 0, y: 20, w: 6, h: 7 }),
            worklog({ x: 6, y: 20, w: 6, h: 7 }),
            summary({ x: 0, y: 27, w: 12, h: 4 }),
            comments({ x: 0, y: 31, w: 12, h: 4 }),
            cta(35),
        ],
    },
    {
        key: 'cloudflare',
        name: 'Cloudflare (CDN y seguridad)',
        description: 'Tráfico, caché y ancho de banda + amenazas bloqueadas por día y mapa de países de origen.',
        build: () => [
            header('Rendimiento y seguridad de tu sitio'),
            kpi('cloudflare', 'requests', 'Peticiones', { x: 0, y: 2, w: 3, h: 4 }, { format: 'compact' }),
            kpi('cloudflare', 'cache_ratio', 'Ratio de caché', { x: 3, y: 2, w: 3, h: 4 }, { format: 'percent' }),
            kpi('cloudflare', 'threats_blocked', 'Amenazas bloqueadas', { x: 6, y: 2, w: 3, h: 4 }),
            kpi('cloudflare', 'unique_visitors', 'Visitantes únicos', { x: 9, y: 2, w: 3, h: 4 }, { format: 'compact' }),
            chart('cloudflare', 'requests_by_date', 'area', 'Peticiones por día', { x: 0, y: 6, w: 8, h: 7 }),
            kpi('cloudflare', 'page_views', 'Páginas vistas', { x: 8, y: 6, w: 4, h: 3 }, { format: 'compact' }),
            kpi('cloudflare', 'bandwidth', 'Ancho de banda', { x: 8, y: 9, w: 4, h: 4 }, { format: 'compact' }),
            chart('cloudflare', 'threats_by_date', 'bar', 'Amenazas bloqueadas por día', { x: 0, y: 13, w: 6, h: 7 }),
            geo('cloudflare', 'threats_by_country', 'Amenazas por país', { x: 6, y: 13, w: 6, h: 7 }),
            table('cloudflare', 'requests_by_country', 'Peticiones por país', { x: 0, y: 20, w: 12, h: 7 }),
            summary({ x: 0, y: 27, w: 12, h: 4 }),
            comments({ x: 0, y: 31, w: 12, h: 4 }),
            cta(35),
        ],
    },
    {
        key: 'uptime',
        name: 'Disponibilidad y SLA',
        description: 'Uptime, incidentes, tiempo caído y la gráfica de tiempos de respuesta (Better Stack) + el trabajo del mes.',
        build: () => [
            header('Disponibilidad de tu sitio'),
            healthscore({ x: 0, y: 2, w: 4, h: 7 }),
            kpi('betteruptime', 'uptime_percent', 'Disponibilidad', { x: 4, y: 2, w: 4, h: 4 }, { format: 'percent' }),
            kpi('betteruptime', 'incidents', 'Incidentes', { x: 8, y: 2, w: 4, h: 4 }),
            kpi('betteruptime', 'total_downtime', 'Tiempo caído', { x: 4, y: 6, w: 4, h: 3 }, { format: 'duration' }),
            kpi('betteruptime', 'longest_incident', 'Incidente más largo', { x: 8, y: 6, w: 4, h: 3 }, { format: 'duration' }),
            chart('betteruptime', 'uptime_by_date', 'bar', 'Disponibilidad por día (%)', { x: 0, y: 9, w: 12, h: 5 }, { threshold: 100 }),
            chart('betteruptime', 'response_times', 'area', 'Tiempo de respuesta (ms)', { x: 0, y: 14, w: 8, h: 7 }),
            kpi('betteruptime', 'avg_response_time', 'Resp. media (ms)', { x: 8, y: 14, w: 4, h: 4 }),
            table('betteruptime', 'incidents_list', 'Incidentes del periodo', { x: 0, y: 21, w: 12, h: 6 }),
            worklog({ x: 0, y: 27, w: 12, h: 7 }),
            summary({ x: 0, y: 34, w: 12, h: 4 }),
            comments({ x: 0, y: 38, w: 12, h: 4 }),
            cta(42),
        ],
    },
    {
        key: 'maintenance',
        name: 'Mantenimiento (MainWP)',
        description: 'El corazón de la retención: cada actualización aplicada con fecha y versión, lo pendiente, SSL y dominio.',
        build: () => [
            header('Mantenimiento de tu sitio'),
            kpi('mainwp', 'updates_applied', 'Actualizaciones aplicadas', { x: 0, y: 2, w: 3, h: 4 }),
            kpi('mainwp', 'updates_available', 'Pendientes', { x: 3, y: 2, w: 3, h: 4 }),
            kpi('mainwp', 'plugins_active', 'Plugins activos', { x: 6, y: 2, w: 3, h: 4 }),
            kpi('mainwp', 'ssl_days_remaining', 'Días de SSL', { x: 9, y: 2, w: 3, h: 4 }, { format: 'number' }),
            summary({ x: 0, y: 6, w: 12, h: 4 }),
            table('mainwp', 'work_log', 'Lo que hicimos este mes', { x: 0, y: 10, w: 12, h: 8 }),
            table('mainwp', 'pending_updates', 'Actualizaciones pendientes (detalle)', { x: 0, y: 18, w: 6, h: 6 }),
            table('mainwp', 'ssl_domain', 'Certificado SSL y dominio', { x: 6, y: 18, w: 6, h: 6 }),
            comments({ x: 0, y: 24, w: 12, h: 4 }),
            cta(28),
        ],
    },
    {
        key: 'virusdie',
        name: 'Antimalware (VirusDie)',
        description: 'Malware detectado y eliminado, escudo de protección y el trabajo de limpieza realizado.',
        build: () => [
            header('Protección antimalware'),
            kpi('mainwp', 'malware_found', 'Malware detectado', { x: 0, y: 2, w: 4, h: 4 }),
            shield({ x: 4, y: 2, w: 8, h: 4 }),
            summary({ x: 0, y: 6, w: 12, h: 4 }),
            worklog({ x: 0, y: 10, w: 12, h: 7 }),
            comments({ x: 0, y: 17, w: 12, h: 4 }),
            cta(21),
        ],
    },
    {
        key: 'ssl-domains',
        name: 'SSL y dominios (MainWP)',
        description: 'Tranquilidad anticipada: días para que caduquen el certificado SSL y el dominio, con emisor, registrador y fechas.',
        build: () => [
            header('Certificado y dominio'),
            spec({ type: 'kpi', binding: { source: 'mainwp', metric: 'ssl_days_remaining' }, props: { label: 'Días para caducar el SSL' }, style: { format: 'number' }, layout: { x: 0, y: 2, w: 6, h: 4 } }),
            spec({ type: 'kpi', binding: { source: 'mainwp', metric: 'domain_days_remaining' }, props: { label: 'Días para caducar el dominio' }, style: { format: 'number' }, layout: { x: 6, y: 2, w: 6, h: 4 } }),
            summary({ x: 0, y: 6, w: 12, h: 4 }),
            table('mainwp', 'ssl_domain', 'Certificado SSL y dominio', { x: 0, y: 10, w: 12, h: 6 }),
            cta(16),
        ],
    },
    {
        key: 'vulnerabilities',
        name: 'Vulnerabilidades (MainWP)',
        description: 'Monitoreo de vulnerabilidades conocidas (CVE) en plugins y temas: cuántas hay y cuáles, para demostrar la vigilancia.',
        build: () => [
            header('Vigilancia de vulnerabilidades'),
            spec({ type: 'kpi', binding: { source: 'mainwp', metric: 'vulnerabilities_count' }, props: { label: 'Vulnerabilidades detectadas' }, style: { format: 'number' }, layout: { x: 0, y: 2, w: 4, h: 4 } }),
            shield({ x: 4, y: 2, w: 8, h: 4 }, 0, 'Tu sitio, bajo vigilancia'),
            summary({ x: 0, y: 6, w: 12, h: 4 }),
            table('mainwp', 'vulnerabilities_list', 'Plugins y temas con vulnerabilidades conocidas', { x: 0, y: 10, w: 12, h: 8 }),
            comments({ x: 0, y: 18, w: 12, h: 4 }),
            cta(22),
        ],
    },
    {
        key: 'wordfence',
        name: 'Seguridad Wordfence (MainWP)',
        description: 'Vigilancia continua: el registro de escaneos de seguridad de Wordfence del periodo, con fecha y resultado.',
        build: () => [
            header('Análisis de seguridad'),
            spec({ type: 'kpi', binding: { source: 'mainwp', metric: 'wordfence_scans_count' }, props: { label: 'Escaneos realizados' }, style: { format: 'number' }, layout: { x: 0, y: 2, w: 4, h: 4 } }),
            shield({ x: 4, y: 2, w: 8, h: 4 }, 0, 'Tu sitio, analizado'),
            summary({ x: 0, y: 6, w: 12, h: 4 }),
            table('mainwp', 'wordfence_scans', 'Escaneos de Wordfence', { x: 0, y: 10, w: 12, h: 8 }),
            comments({ x: 0, y: 18, w: 12, h: 4 }),
            cta(22),
        ],
    },
    {
        key: 'security-checklist',
        name: 'Estado de seguridad (MainWP)',
        description: 'Una foto clara del endurecimiento del sitio: WordPress al día, SSL, depuración y plugins/temas obsoletos, con un semáforo por punto.',
        build: () => [
            header('Estado de seguridad'),
            spec({ type: 'kpi', binding: { source: 'mainwp', metric: 'security_issues_count' }, props: { label: 'Puntos por revisar' }, style: { format: 'number' }, layout: { x: 0, y: 2, w: 4, h: 4 } }),
            shield({ x: 4, y: 2, w: 8, h: 4 }, 0, 'Tu sitio, endurecido'),
            summary({ x: 0, y: 6, w: 12, h: 4 }),
            table('mainwp', 'security_checklist', 'Comprobaciones de seguridad', { x: 0, y: 10, w: 12, h: 8 }),
            comments({ x: 0, y: 18, w: 12, h: 4 }),
            cta(22),
        ],
    },
    {
        key: 'site-agent',
        name: 'Sitio y respaldos (Agente Imagina)',
        description: 'Estado real de los respaldos (último respaldo, antigüedad, tamaño) más la salud del WordPress: versiones, plugins, actualizaciones y almacenamiento.',
        build: () => [
            header('Estado de tu sitio'),
            kpi('site_agent', 'last_backup_days', 'Días desde el último respaldo', { x: 0, y: 2, w: 3, h: 4 }, { format: 'number' }),
            kpi('site_agent', 'backups_count', 'Respaldos del periodo', { x: 3, y: 2, w: 3, h: 4 }, { format: 'number' }),
            kpi('site_agent', 'last_backup_size_mb', 'Tamaño del último (MB)', { x: 6, y: 2, w: 3, h: 4 }, { format: 'number' }),
            kpi('site_agent', 'updates_pending', 'Actualizaciones pendientes', { x: 9, y: 2, w: 3, h: 4 }, { format: 'number' }),
            summary({ x: 0, y: 6, w: 12, h: 4 }),
            table('site_agent', 'backup_status', 'Estado de respaldos', { x: 0, y: 10, w: 6, h: 8 }),
            table('site_agent', 'recent_backups', 'Respaldos recientes', { x: 6, y: 10, w: 6, h: 8 }),
            table('site_agent', 'site_health', 'Salud del sitio', { x: 0, y: 18, w: 6, h: 8 }),
            kpi('site_agent', 'plugins_active', 'Plugins activos', { x: 6, y: 18, w: 3, h: 4 }, { format: 'number' }),
            kpi('site_agent', 'plugins_total', 'Plugins instalados', { x: 9, y: 18, w: 3, h: 4 }, { format: 'number' }),
            kpi('site_agent', 'db_size_mb', 'Base de datos (MB)', { x: 6, y: 22, w: 3, h: 4 }, { format: 'number' }),
            kpi('site_agent', 'uploads_size_mb', 'Archivos subidos (MB)', { x: 9, y: 22, w: 3, h: 4 }, { format: 'number' }),
            comments({ x: 0, y: 26, w: 12, h: 4 }),
            cta(30),
        ],
    },
    {
        key: 'site-agent-360',
        name: 'Seguridad, rendimiento y captación (Agente)',
        description: 'Panel 360 del sitio vía el Agente Imagina: spam bloqueado, solicitudes, auditoría de seguridad, rendimiento, limpieza de base de datos y tienda. Los bloques sin datos se ocultan solos.',
        build: () => [
            header('Tu sitio este mes'),
            kpi('site_agent', 'spam_blocked', 'Spam bloqueado', { x: 0, y: 2, w: 3, h: 4 }, { format: 'number' }),
            kpi('site_agent', 'leads', 'Solicitudes recibidas', { x: 3, y: 2, w: 3, h: 4 }, { format: 'number' }),
            kpi('site_agent', 'posts_published', 'Publicaciones', { x: 6, y: 2, w: 3, h: 4 }, { format: 'number' }),
            kpi('site_agent', 'users_new', 'Usuarios nuevos', { x: 9, y: 2, w: 3, h: 4 }, { format: 'number' }),
            summary({ x: 0, y: 6, w: 12, h: 4 }),
            table('site_agent', 'security_audit', 'Auditoría de seguridad', { x: 0, y: 10, w: 6, h: 8 }),
            table('site_agent', 'performance_status', 'Estado de rendimiento', { x: 6, y: 10, w: 6, h: 8 }),
            table('site_agent', 'db_cleanup', 'Limpieza de base de datos', { x: 0, y: 18, w: 6, h: 8 }),
            kpi('site_agent', 'out_of_stock', 'Productos agotados', { x: 6, y: 18, w: 3, h: 4 }, { format: 'number' }),
            kpi('site_agent', 'low_stock', 'Stock bajo', { x: 9, y: 18, w: 3, h: 4 }, { format: 'number' }),
            kpi('site_agent', 'pending_orders', 'Pedidos por atender', { x: 6, y: 22, w: 3, h: 4 }, { format: 'number' }),
            kpi('site_agent', 'cron_overdue', 'Cron atrasado', { x: 9, y: 22, w: 3, h: 4 }, { format: 'number' }),
            comments({ x: 0, y: 26, w: 12, h: 4 }),
            cta(30),
        ],
    },
];
