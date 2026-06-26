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
            return { w: 12, h: 2 };
        case 'cta':
            return { w: 12, h: 3 };
        case 'narrative':
            return { w: 12, h: 4 };
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
    { type: 'security_shield', label: 'Seguridad' },
    { type: 'worklog_timeline', label: 'Trabajo' },
    { type: 'sales_summary', label: 'Ventas' },
    { type: 'goal', label: 'Meta' },
    { type: 'control', label: 'Filtro' },
    { type: 'comments', label: 'Comentarios' },
    { type: 'image', label: 'Imagen' },
    { type: 'cta', label: 'CTA' },
    { type: 'divider', label: 'Separador' },
    { type: 'pagebreak', label: 'Salto de página' },
];

/** Placeholder data so the live preview shows something for each block type. */
export function sampleData(block: Block): unknown {
    switch (block.type) {
        case 'cover':
            // The cover reads client/site/period from the report context at render time;
            // in the editor we seed period + score so the title page previews realistically.
            return { period: 'junio 2026', score: '87' };
        case 'header': {
            // Realistic preview so merge-field headers read as a real cover in the editor.
            const headerTitle = typeof block.props?.title === 'string' && block.props.title !== '' ? block.props.title : 'Informe mensual';

            return { title: headerTitle, eyebrow: 'Tu Agencia', subtitle: 'Cliente · misitio.com · junio 2026' };
        }
        case 'kpi':
        case 'sales_summary':
            // Rich shape so the sample preview shows the professional card with a trend.
            return block.binding?.compare === 'prev_period'
                ? { value: 1234, previous: 1100, change_percent: 12.2 }
                : 1234;
        case 'goal':
            return 720;
        case 'healthscore':
            return 87;
        case 'security_shield':
            return { threats_blocked: 1840, attacks_blocked: 96, malware_found: 0 };
        case 'chart':
            return [
                { date: '01', value: 40 },
                { date: '02', value: 55 },
                { date: '03', value: 48 },
            ];
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
