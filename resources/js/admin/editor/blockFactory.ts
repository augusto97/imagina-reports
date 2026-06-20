import type { Block, BlockType } from '@shared/blocks/types';

let counter = 0;

/** Create a fresh block with sane default props for the editor. */
export function makeBlock(type: BlockType): Block {
    counter += 1;
    const id = `b_${Date.now().toString(36)}_${counter}`;
    const block: Block = { id, type, binding: null, props: {}, style: {} };

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
    // KPI cards read best three-per-row with a "vs previous period" trend.
    if (type === 'kpi') {
        block.style = { width: 'third' };
    }

    return block;
}

export const DATA_BLOCKS: BlockType[] = ['kpi', 'chart', 'table', 'sales_summary'];

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
    { type: 'image', label: 'Imagen' },
    { type: 'cta', label: 'CTA' },
    { type: 'divider', label: 'Separador' },
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
        case 'worklog_timeline':
            return [{ performed_at: '2026-06-10', description: 'Actualizaciones aplicadas' }];
        default:
            return undefined;
    }
}
