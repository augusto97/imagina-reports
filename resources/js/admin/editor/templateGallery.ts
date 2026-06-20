import type { Block, BlockBinding, BlockType } from '@shared/blocks/types';

import { makeBlock } from './blockFactory';

interface BlockSpec {
    type: BlockType;
    binding?: BlockBinding;
    props?: Record<string, unknown>;
    style?: Record<string, unknown>;
}

/** Build a fresh block (new id) from a spec, merging over the factory defaults. */
function spec({ type, binding, props, style }: BlockSpec): Block {
    const block = makeBlock(type);
    if (binding !== undefined) {
        block.binding = binding;
    }
    block.props = { ...block.props, ...props };
    block.style = { ...block.style, ...style };

    return block;
}

const kpi = (source: string, metric: string, label: string, style: Record<string, unknown> = {}): Block =>
    spec({
        type: 'kpi',
        binding: { source, metric, compare: 'prev_period' },
        props: { label },
        style: { width: 'third', ...style },
    });

export interface GalleryTemplate {
    key: string;
    name: string;
    description: string;
    build: () => Block[];
}

/**
 * Starter templates by vertical (CLAUDE.md §10.2 / §11.5 — "ready starting points").
 * Bindings use real connector metric keys; blocks whose metric has no data are
 * gracefully hidden at generation, so a template never shows an empty box.
 */
export const GALLERY: GalleryTemplate[] = [
    {
        key: 'ecommerce',
        name: 'E-commerce',
        description: 'Ventas, pedidos y tráfico para tiendas WooCommerce.',
        build: () => [
            spec({ type: 'header' }),
            spec({ type: 'healthscore' }),
            kpi('woocommerce', 'revenue', 'Ingresos', { format: 'currency' }),
            kpi('woocommerce', 'orders', 'Pedidos'),
            kpi('ga4', 'sessions', 'Visitas'),
            spec({ type: 'chart', binding: { source: 'ga4', metric: 'sessions_by_date' }, props: { chartType: 'area', title: 'Visitas' } }),
            spec({ type: 'table', binding: { source: 'woocommerce', metric: 'top_products' }, props: { title: 'Productos top' }, style: { bars: true } }),
            spec({ type: 'cta' }),
        ],
    },
    {
        key: 'seo',
        name: 'SEO y tráfico',
        description: 'Posicionamiento y audiencia: GA4 + Search Console.',
        build: () => [
            spec({ type: 'header' }),
            kpi('ga4', 'sessions', 'Visitas'),
            kpi('gsc', 'clicks', 'Clics'),
            kpi('gsc', 'position', 'Posición media'),
            spec({ type: 'chart', binding: { source: 'ga4', metric: 'sessions_by_date' }, props: { chartType: 'line', title: 'Tendencia de visitas' } }),
            spec({ type: 'table', binding: { source: 'gsc', metric: 'top_queries' }, props: { title: 'Búsquedas top' }, style: { bars: true } }),
            spec({ type: 'narrative' }),
            spec({ type: 'cta' }),
        ],
    },
    {
        key: 'hourly_support',
        name: 'Soporte por horas',
        description: 'Justifica el plan: horas invertidas, tareas y desglose del trabajo.',
        build: () => [
            spec({ type: 'header' }),
            kpi('worklog', 'hours', 'Horas invertidas'),
            kpi('worklog', 'tasks', 'Tareas realizadas'),
            spec({ type: 'goal', binding: { source: 'worklog', metric: 'hours_vs_plan' }, props: { label: 'Horas vs plan' }, style: { width: 'third' } }),
            spec({
                type: 'chart',
                binding: { source: 'worklog', metric: 'by_category' },
                props: { chartType: 'donut', title: 'Horas por categoría' },
                style: { width: 'half', legend: true },
            }),
            spec({ type: 'worklog_timeline', props: { title: 'Lo que hicimos este mes' }, style: { width: 'half' } }),
            spec({ type: 'cta' }),
        ],
    },
    {
        key: 'security',
        name: 'Seguridad y mantenimiento',
        description: 'El trabajo invisible: ataques bloqueados, uptime y updates.',
        build: () => [
            spec({ type: 'header' }),
            spec({ type: 'healthscore' }),
            spec({ type: 'security_shield' }),
            kpi('cloudflare', 'threats_blocked', 'Amenazas bloqueadas'),
            kpi('mainwp', 'updates_available', 'Updates pendientes'),
            kpi('betteruptime', 'uptime_percent', 'Uptime', { format: 'percent' }),
            spec({ type: 'worklog_timeline', props: { title: 'Lo que hicimos este mes' }, style: { width: 'full' } }),
            spec({ type: 'cta' }),
        ],
    },
];
