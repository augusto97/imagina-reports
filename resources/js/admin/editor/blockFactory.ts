import { GRID_COLS } from '@shared/blocks/types';
import type { Block, BlockLayout, BlockType } from '@shared/blocks/types';

let counter = 0;

/** Sensible default tile size (in 12-col grid units) per block type. */
export function defaultSize(type: BlockType): { w: number; h: number } {
    switch (type) {
        case 'cover':
        case 'back_cover':
            // A full page — covers stand alone on their own page.
            return { w: 12, h: 16 };
        case 'header':
            return { w: 12, h: 3 };
        case 'cta':
            return { w: 12, h: 3 };
        case 'narrative':
            return { w: 12, h: 4 };
        case 'advisory':
            return { w: 12, h: 5 };
        case 'divider':
        case 'pagebreak':
            return { w: 12, h: 1 };
        case 'control':
            return { w: 3, h: 3 };
        case 'kpi':
        case 'sales_summary':
            return { w: 4, h: 4 };
        case 'goal':
            return { w: 4, h: 5 };
        case 'goals':
            return { w: 12, h: 6 };
        case 'healthscore':
            return { w: 4, h: 7 };
        case 'chart':
        case 'table':
        case 'funnel':
        case 'geo_map':
        case 'worklog_timeline':
            return { w: 6, h: 7 };
        case 'security_shield':
        case 'comments':
        case 'image':
            return { w: 6, h: 5 };
        default:
            return { w: 6, h: 4 };
    }
}

/** Create a fresh block with sane default props + a grid tile size (dropped at the bottom). */
export function makeBlock(type: BlockType): Block {
    counter += 1;
    const id = `b_${Date.now().toString(36)}_${counter}`;
    const size = defaultSize(type);
    // y: a large value so react-grid-layout drops the new tile at the bottom of the canvas.
    const block: Block = { id, type, binding: null, props: {}, style: {}, layout: { x: 0, y: 9999, ...size } };

    if (type === 'narrative') {
        block.props = { text: '<p>Escribe aquí…</p>' };
    }
    if (type === 'chart') {
        block.props = { chartType: 'line', title: 'Gráfico' };
    }
    if (type === 'cta') {
        block.props = {
            headline: 'Tu plan de soporte está activo y protegiendo tu sitio.',
            text: '',
            buttonLabel: '',
            buttonUrl: '',
        };
    }
    if (type === 'goal') {
        block.props = { label: 'Meta', target: 100 };
    }
    if (type === 'goals') {
        block.props = { title: 'Metas del mes' };
    }
    if (type === 'advisory') {
        block.props = { title: 'Diagnóstico y recomendaciones' };
    }
    if (type === 'cover') {
        block.props = { title: 'Informe de soporte y rendimiento', subtitle: '', showScore: true };
    }
    if (type === 'back_cover') {
        block.props = {
            headline: 'Tu plan de soporte está activo y protegiendo tu sitio.',
            text: 'Gracias por confiar en nosotros para cuidar tu sitio cada mes.',
            contact: '',
        };
    }

    return block;
}

/** Legacy width keyword → column span (full/half/third on the old 6-col flow). */
function widthToCols(block: Block): number {
    const width = block.style?.width;
    if (width === 'third') {
        return 4;
    }
    if (width === 'half') {
        return 6;
    }
    return GRID_COLS;
}

/**
 * Guarantee every block has grid coordinates. Templates authored before the dashboard
 * grid (or by the AI / gallery) carry only a legacy `style.width`; we flow them into the
 * 12-column grid so they open as a real, resizable dashboard. Blocks that already have a
 * layout are left untouched.
 */
export function ensureLayouts(blocks: Block[]): Block[] {
    if (blocks.every((block) => block.layout != null)) {
        return blocks;
    }

    let x = 0;
    let y = 0;
    let rowHeight = 0;

    return blocks.map((block) => {
        const size = defaultSize(block.type);
        const w = Math.min(GRID_COLS, block.style?.width != null ? widthToCols(block) : size.w);
        const h = size.h;

        if (x + w > GRID_COLS) {
            x = 0;
            y += rowHeight;
            rowHeight = 0;
        }

        const layout: BlockLayout = { x, y, w, h };
        x += w;
        rowHeight = Math.max(rowHeight, h);

        return { ...block, layout };
    });
}

export const DATA_BLOCKS: BlockType[] = ['kpi', 'chart', 'table', 'funnel', 'geo_map', 'sales_summary', 'goal', 'control'];

/**
 * What each block can actually DRAW, so the picker only offers metrics it can render.
 *
 * Offering everything to every block meant a map listed campaign names and a KPI listed
 * page tables — bindings that can only ever come back empty. `dataset` is accepted almost
 * everywhere because the DatasetEngine reshapes it: aggregated to a single number without a
 * breakdown, to rows with one.
 */
export const BLOCK_METRIC_TYPES: Partial<Record<BlockType, string[]>> = {
    // Single figures: a scalar, or a dataset aggregated down to one.
    kpi: ['scalar', 'number', 'dataset'],
    goal: ['scalar', 'number', 'dataset'],
    // A site goal (Etapa 4) is measured against one plain number.
    goals: ['scalar', 'number'],
    sales_summary: ['scalar', 'number', 'dataset'],
    // Anything with a shape over time or across categories.
    chart: ['series', 'table', 'dataset'],
    table: ['table', 'dataset'],
    funnel: ['table', 'dataset'],
    // A map needs a place to put the pins — see GEO_DIMENSIONS below.
    geo_map: ['table', 'dataset'],
    // The client-facing filter control reads the values of a dimension.
    control: ['table', 'dataset'],
};

/** Dimensions a map can plot. A dataset without one of these has nothing to place. */
export const GEO_DIMENSIONS = ['country', 'region', 'city'];

export type BlockWidth = 'full' | 'half' | 'third';

/** A block's configured column width (defaults to full). */
export function widthOf(block: Block): BlockWidth {
    const width = block.style?.width;

    return width === 'half' || width === 'third' ? width : 'full';
}

/** Tailwind column span (out of 6) for each width — mirrors the shared BlockList grid. */
export const WIDTH_SPAN: Record<BlockWidth, string> = {
    full: 'ir-col-span-6',
    half: 'ir-col-span-6 sm:ir-col-span-3',
    third: 'ir-col-span-6 sm:ir-col-span-2',
};

/** Next width in the full → half → third → full cycle (for the toolbar button). */
export function nextWidth(current: BlockWidth): BlockWidth {
    return current === 'full' ? 'half' : current === 'half' ? 'third' : 'full';
}

export const PALETTE: { type: BlockType; label: string }[] = [
    { type: 'cover', label: 'Portada' },
    { type: 'back_cover', label: 'Contraportada' },
    { type: 'header', label: 'Cabecera' },
    { type: 'healthscore', label: 'Health score' },
    { type: 'kpi', label: 'KPI' },
    { type: 'chart', label: 'Gráfico' },
    { type: 'table', label: 'Tabla' },
    { type: 'narrative', label: 'Texto' },
    { type: 'advisory', label: 'Diagnóstico IA' },
    { type: 'security_shield', label: 'Seguridad' },
    { type: 'worklog_timeline', label: 'Trabajo' },
    { type: 'sales_summary', label: 'Ventas' },
    { type: 'goal', label: 'Meta' },
    { type: 'goals', label: 'Metas del cliente' },
    { type: 'control', label: 'Filtro' },
    { type: 'comments', label: 'Comentarios' },
    { type: 'image', label: 'Imagen' },
    { type: 'cta', label: 'CTA' },
    { type: 'divider', label: 'Separador' },
    { type: 'pagebreak', label: 'Salto de página' },
];

/** A plausible sample value for a KPI, from its metric name and number format. */
function sampleKpi(block: Block): number {
    const metric = `${block.binding?.metric ?? ''}`.toLowerCase();
    const format = typeof block.style?.format === 'string' ? block.style.format : '';
    if (format === 'percent' || /uptime|percent|rate|ctr|ratio/.test(metric)) {
        return metric.includes('uptime') ? 99.9 : 3.4;
    }
    if (metric.includes('position')) {
        return 12.4;
    }
    if (format === 'currency' || /revenue|cost|spend|sales/.test(metric)) {
        return 4820;
    }
    const seed = [...(block.id ?? metric)].reduce((sum, char) => sum + char.charCodeAt(0), 0);

    return 120 + (seed * 37) % 4800;
}

/** Placeholder data so the live preview shows something for each block type. */
export function sampleData(block: Block): unknown {
    switch (block.type) {
        case 'cover':
            // The cover reads client/site/period from the report context at render time;
            // in the editor we seed period + score so the title page previews realistically.
            return { period: 'junio 2026', score: '87' };
        case 'advisory':
            // The real text is written by the AI at generation; show a representative sample
            // so the editor previews how the insight reads.
            return 'Este mes tus ventas subieron un 30% y las visitas un 40%. Aplicamos 6 actualizaciones de seguridad y tu sitio estuvo disponible el 99,8% del tiempo, con 4 breves caídas. Ante este crecimiento de tráfico, recomendamos una revisión de rendimiento para que el sitio lo soporte con holgura.';
        case 'header':
            // The header shows its own props; the canvas fills {{agency}}/{{client}}/{{site}}/
            // {{period}} from the editor's context, so there's no sample to invent.
            return undefined;
        case 'kpi':
        case 'sales_summary': {
            // Believable numbers per kind of metric: a 1.234% uptime read as broken.
            const value = sampleKpi(block);

            return block.binding?.compare === 'prev_period'
                ? { value, previous: Math.round(value / 1.12 * 10) / 10, change_percent: 12.2 }
                : value;
        }
        case 'narrative':
            // The executive summary is written per period; show what it will read like.
            return typeof block.props?.html === 'string' && block.props.html.trim() !== ''
                ? undefined
                : '<p>Este mes tu sitio recibió <strong>7.607 visitas</strong> (+4,8%) y estuvo disponible el 100% del tiempo. Bloqueamos 3.662 ataques y aplicamos 17 actualizaciones de seguridad. Tus clics desde Google crecieron un 5%.</p>';
        case 'goal':
            return 720;
        case 'goals':
            // Each client's real goals come from its site; preview what the block looks like.
            return [
                { label: 'Visitas del mes', value: 4820, target: 5000, direction: 'at_least', format: 'number', progress: 96.4, met: false },
                { label: 'Disponibilidad', value: 99.98, target: 99.9, direction: 'at_least', format: 'percent', progress: 100.1, met: true },
                { label: 'Clics desde Google', value: 1310, target: 1200, direction: 'at_least', format: 'number', progress: 109.2, met: true },
                { label: 'Tiempo de carga', value: 2.4, target: 2, direction: 'at_most', format: 'number', progress: 120, met: false },
            ];
        case 'healthscore':
            // What the score is made of, so the preview explains itself like the real report.
            return {
                score: 87,
                signals: [
                    { key: 'uptime', label: 'Disponibilidad', detail: '99,9 % del tiempo en línea', score: 98, weight: 25 },
                    { key: 'updates', label: 'Actualizaciones', detail: '2 actualizaciones pendientes', score: 90, weight: 20 },
                    { key: 'security', label: 'Seguridad', detail: 'Sin malware ni puntos débiles conocidos', score: 100, weight: 20 },
                    { key: 'ssl', label: 'Certificado SSL', detail: 'Vence en 21 días', score: 70, weight: 10 },
                    { key: 'performance', label: 'Rendimiento', detail: '62 % servido desde la caché (carga más rápido)', score: 62, weight: 15 },
                ],
            };
        case 'security_shield':
            return { threats_blocked: 1840, attacks_blocked: 96, malware_found: 0 };
        case 'chart': {
            // Hours of the day and days of the week preview with the same labels the report uses.
            const metric = typeof block.binding?.metric === 'string' ? block.binding.metric : '';
            if (metric.endsWith('_by_hour')) {
                const curve = [3, 2, 1, 1, 1, 2, 5, 9, 14, 18, 21, 22, 20, 19, 21, 22, 20, 18, 16, 15, 14, 12, 9, 6];

                return curve.map((weight, hour) => ({ date: `${String(hour).padStart(2, '0')} h`, value: weight * 12 }));
            }
            if (metric.endsWith('_by_weekday')) {
                const week: [string, number][] = [['Lun', 1320], ['Mar', 1410], ['Mié', 1380], ['Jue', 1290], ['Vie', 1105], ['Sáb', 520], ['Dom', 410]];

                return week.map(([day, value]) => ({ date: day, value }));
            }

            return Array.from({ length: 30 }, (_, day) => ({
                date: `202606${String(day + 1).padStart(2, '0')}`,
                value: Math.round(220 + 60 * Math.sin(day / 3) + (day % 7) * 9),
            }));
        }
        case 'table': {
            // Binding-aware placeholders so structured tables (e.g. the MainWP work log)
            // preview with their real columns, not a generic label/value pair.
            const metric = typeof block.binding?.metric === 'string' ? block.binding.metric : '';
            if (metric === 'work_log') {
                return [
                    { Fecha: '22/06/2026', Tipo: 'Plugin', Elemento: 'WooCommerce', 'Versión': '10.7.0 → 10.8.1' },
                    { Fecha: '21/06/2026', Tipo: 'Plugin', Elemento: 'Rank Math SEO', 'Versión': '1.0.271 → 1.0.272' },
                    { Fecha: '18/06/2026', Tipo: 'Tema', Elemento: 'Astra', 'Versión': '4.0 → 4.6' },
                    { Fecha: '14/06/2026', Tipo: 'WordPress', Elemento: 'Núcleo de WordPress', 'Versión': '6.4.2 → 6.5' },
                ];
            }
            if (metric === 'pending_updates') {
                return [
                    { Tipo: 'Plugin', Elemento: 'Yoast SEO', Actual: '21.0', Nueva: '22.1' },
                    { Tipo: 'Tema', Elemento: 'Astra', Actual: '4.0', Nueva: '4.6' },
                ];
            }
            return [
                { label: '/home', value: 900 },
                { label: '/precios', value: 120 },
            ];
        }
        case 'funnel':
            return [
                { label: 'Sesiones', value: 1000 },
                { label: 'Añadido al carrito', value: 240 },
                { label: 'Checkout', value: 130 },
                { label: 'Compra', value: 78 },
            ];
        case 'geo_map':
            return [
                { label: 'Colombia', value: 1200 },
                { label: 'México', value: 800 },
                { label: 'España', value: 300 },
                { label: 'Argentina', value: 180 },
            ];
        case 'control':
            return [{ name: 'Escritorio', value: 1 }, { name: 'Móvil', value: 1 }, { name: 'Tablet', value: 1 }];
        case 'comments':
            return [{ body: 'Este mes reforzamos la seguridad tras detectar intentos de acceso sospechosos.', created_at: '2026-06-15' }];
        case 'worklog_timeline':
            return [
                { performed_at: '2026-06-10', description: 'Actualizaciones aplicadas', minutes: 45, category: 'Mantenimiento' },
                { performed_at: '2026-06-14', description: 'Limpieza de spam y backup', minutes: 30, category: 'Seguridad' },
            ];
        default:
            return undefined;
    }
}
