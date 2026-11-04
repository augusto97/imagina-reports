import { describe, expect, it } from 'vitest';

import { ensureHeaderRoom, reflowGaps } from './layout';
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
