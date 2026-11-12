import { describe, expect, it } from 'vitest';

import type { Block } from '@shared/blocks/types';
import { movePage, removePage, type PageLayout } from './pageOrder';

const block = (id: string, page: number): Block => ({ id, type: 'kpi', page }) as Block;

const layout = (): PageLayout => ({
    blocks: [block('a', 0), block('b', 1), block('c', 2), block('d', 3)],
    names: ['Portada', 'Resumen', 'Seguridad', 'Cierre'],
    filters: { all: [], '1': [{ dimension: 'country', op: 'eq', value: 'CO' }] as never, '3': [] },
    pageCount: 4,
});

const pageOf = (result: PageLayout, id: string): number | undefined => result.blocks.find((item) => item.id === id)?.page;

describe('movePage', () => {
    it('moves a page forward with its blocks, name and filters', () => {
        const result = movePage(layout(), 1, 3);

        expect(result.names).toEqual(['Portada', 'Seguridad', 'Cierre', 'Resumen']);
        expect([pageOf(result, 'a'), pageOf(result, 'b'), pageOf(result, 'c'), pageOf(result, 'd')]).toEqual([0, 3, 1, 2]);
        // «Resumen» keeps its own filter at its new place; «Cierre» moved up one.
        expect(Object.keys(result.filters).sort()).toEqual(['2', '3', 'all']);
        expect(result.filters['3']).toEqual(layout().filters['1']);
    });

    it('moves a page back', () => {
        const result = movePage(layout(), 3, 0);

        expect(result.names).toEqual(['Cierre', 'Portada', 'Resumen', 'Seguridad']);
        expect(pageOf(result, 'd')).toBe(0);
        expect(pageOf(result, 'a')).toBe(1);
    });

    it('ignores a move outside the report', () => {
        expect(movePage(layout(), 0, 9)).toEqual(layout());
    });
});

describe('removePage', () => {
    it('drops the page and its filters and moves the rest up', () => {
        const result = removePage(layout(), 1);

        expect(result.names).toEqual(['Portada', 'Seguridad', 'Cierre']);
        expect(result.blocks.map((item) => item.id)).toEqual(['a', 'c', 'd']);
        expect(pageOf(result, 'd')).toBe(2);
        expect(Object.keys(result.filters).sort()).toEqual(['2', 'all']);
        expect(result.pageCount).toBe(3);
    });
});
