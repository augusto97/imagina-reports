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
    // KPI cards read best three-per-row with a "vs previous period" trend.
    if (type === 'kpi') {
        block.style = { width: 'third' };
    }

    return block;
}

export const DATA_BLOCKS: BlockType[] = ['kpi', 'chart', 'table', 'sales_summary'];

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
