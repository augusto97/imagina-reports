import { describe, expect, it } from 'vitest';

import { contentRows, ensureHeaderRoom, fitContent, reflowGaps } from './layout';
import type { Block } from './types';

const block = (id: string, x: number, y: number, w: number, h: number, type: Block['type'] = 'kpi'): Block => ({ id, type, layout: { x, y, w, h } });

describe('reflowGaps', () => {
    it('stretches a row whose neighbour was hidden', () => {
        // Health gauge (4 wide) lost its 8-wide summary neighbour.
        const [gauge] = reflowGaps([block('health', 0, 2, 4, 7)]);
        expect(gauge?.layout).toEqual({ x: 0, y: 0, w: 12, h: 7 });
    });

    it('keeps full rows untouched and pulls the next row up over an emptied one', () => {
        const result = reflowGaps([block('a', 0, 0, 6, 4), block('b', 6, 0, 6, 4), block('c', 0, 8, 12, 4)]);
        expect(result.map((item) => item.layout)).toEqual([
            { x: 0, y: 0, w: 6, h: 4 },
            { x: 6, y: 0, w: 6, h: 4 },
            { x: 0, y: 4, w: 12, h: 4 },
        ]);
    });

    it('does not stretch a row crossed by a taller block', () => {
        const result = reflowGaps([block('tall', 0, 0, 4, 8), block('side', 4, 0, 4, 4), block('below', 4, 4, 8, 4)]);
        expect(result.find((item) => item.id === 'side')?.layout?.w).toBe(4);
    });
});

describe('ensureHeaderRoom', () => {
    it('grows a 2-row header and pushes the rest down', () => {
        const result = ensureHeaderRoom([block('h', 0, 0, 12, 2, 'header'), block('k', 0, 2, 3, 4)]);
        expect(result[0]?.layout?.h).toBe(3);
        expect(result[1]?.layout?.y).toBe(3);
    });
});

describe('fitContent', () => {
    const need = (map: Record<string, number | null>) => (b: Block) => map[b.id] ?? null;

    it('shrinks a row that holds less than its height and pulls the rest up', () => {
        const result = fitContent([block('log', 0, 0, 12, 7, 'worklog_timeline'), block('next', 0, 7, 12, 4)], need({ log: 4 }));
        expect(result.map((item) => item.layout)).toEqual([
            { x: 0, y: 0, w: 12, h: 4 },
            { x: 0, y: 4, w: 12, h: 4 },
        ]);
    });

    it('grows a row whose content would be cut and keeps neighbours aligned', () => {
        const result = fitContent([block('t1', 0, 0, 6, 7, 'table'), block('t2', 6, 0, 6, 7, 'table'), block('next', 0, 7, 12, 3)], need({ t1: 9, t2: 5 }));
        expect(result.map((item) => item.layout?.h)).toEqual([9, 9, 3]);
        expect(result[2]?.layout?.y).toBe(9);
    });

    it('never shrinks a row below a block that keeps its designed height', () => {
        const result = fitContent([block('chart', 0, 0, 6, 6, 'chart'), block('table', 6, 0, 6, 6, 'table')], need({ table: 3 }));
        expect(result.map((item) => item.layout?.h)).toEqual([6, 6]);
    });

    it('leaves rows crossed by a taller block alone', () => {
        const result = fitContent([block('tall', 0, 0, 4, 8, 'chart'), block('side', 4, 0, 8, 4, 'table'), block('below', 4, 4, 8, 4, 'table')], need({ side: 2, below: 2 }));
        expect(result.map((item) => item.layout)).toEqual([
            { x: 0, y: 0, w: 4, h: 8 },
            { x: 4, y: 0, w: 8, h: 4 },
            { x: 4, y: 4, w: 8, h: 4 },
        ]);
    });
});

describe('contentRows', () => {
    it('sizes a work log by its entries and a table by its rows', () => {
        const log = { ...block('log', 0, 0, 12, 7, 'worklog_timeline'), props: { title: 'Lo que hicimos' } };
        expect(contentRows(log, [{ description: 'Renovamos el SSL' }, { description: 'Optimizamos imágenes' }, { description: 'Actualizamos WordPress' }], 'screen')).toBeLessThan(7);

        const table = { ...block('t', 0, 0, 6, 7, 'table'), props: { title: 'Páginas' } };
        const rows = Array.from({ length: 25 }, (_, index) => ({ label: `/p${index}`, value: index }));
        // The portal pages tables (10 rows + pager); the PDF prints every row.
        expect(contentRows(table, rows, 'print')).toBeGreaterThan(contentRows(table, rows, 'screen') ?? 0);
    });

    it('sizes the goals block by how many goals the site has, two per line when wide', () => {
        const goal = { label: 'Visitas', value: 10, target: 20, progress: 50, met: false };
        const wide = block('g', 0, 0, 12, 6, 'goals');
        const narrow = block('g', 0, 0, 6, 6, 'goals');

        expect(contentRows(wide, [], 'screen')).toBeNull();
        expect(contentRows(wide, [goal, goal, goal, goal], 'screen')).toBeLessThan(contentRows(narrow, [goal, goal, goal, goal], 'screen') ?? 0);
    });

    it('leaves charts and KPIs without a daily series at their designed height', () => {
        expect(contentRows(block('c', 0, 0, 6, 6, 'chart'), [{ name: 'a', value: 1 }], 'screen')).toBeNull();
        expect(contentRows(block('k', 0, 0, 3, 4, 'kpi'), { value: 3 }, 'screen')).toBeNull();
        expect(contentRows(block('k', 0, 0, 3, 4, 'kpi'), { value: 3, series: [1, 2, 3] }, 'screen')).toBeGreaterThanOrEqual(5);
    });
});
