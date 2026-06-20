import { GRID_COLS } from '@shared/blocks/types';
import type { Block, BlockLayout, BlockType } from '@shared/blocks/types';

let counter = 0;

/** Sensible default tile size (in 12-col grid units) per block type. */
export function defaultSize(type: BlockType): { w: number; h: number } {
    switch (type) {
        case 'header':
            return { w: 12, h: 2 };
        case 'cta':
            return { w: 12, h: 3 };
        case 'narrative':
            return { w: 12, h: 4 };
        case 'divider':
        case 'pagebreak':
            return { w: 12, h: 1 };
        case 'kpi':
        case 'sales_summary':
            return { w: 4, h: 4 };
        case 'goal':
            return { w: 4, h: 5 };
        case 'healthscore':
            return { w: 4, h: 7 };
        case 'chart':
        case 'table':
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

export const DATA_BLOCKS: BlockType[] = ['kpi', 'chart', 'table', 'sales_summary', 'goal'];

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
    { type: 'comments', label: 'Comentarios' },
    { type: 'image', label: 'Imagen' },
    { type: 'cta', label: 'CTA' },
    { type: 'divider', label: 'Separador' },
    { type: 'pagebreak', label: 'Salto de página' },
];

/** Placeholder data so the live preview shows something for each block type. */
export function sampleData(block: Block): unknown {
    switch (block.type) {
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
        case 'table':
            return [
                { label: '/home', value: 900 },
                { label: '/precios', value: 120 },
            ];
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
