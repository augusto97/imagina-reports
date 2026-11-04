import { type Block, type BlockLayout, GRID_COLS } from './types';

/** A header needs three grid rows (eyebrow + title + subtitle); two used to clip the subtitle. */
export const HEADER_MIN_ROWS = 3;

/** Only stretch a row when the hole left behind is at least this many columns wide. */
const MIN_GAP_COLS = 3;

type Laid = Block & { layout: BlockLayout };

function hasLayout(block: Block): block is Laid {
    return block.layout != null;
}

function overlapsRows(a: BlockLayout, from: number, to: number): boolean {
    return a.y < to && a.y + a.h > from;
}

/**
 * Give every header block room for its subtitle, pushing what's below it down.
 * Templates saved with a 2-row header (the old default) printed a cut-off subtitle.
 */
export function ensureHeaderRoom(blocks: Block[]): Block[] {
    let result = blocks.map((block) => ({ ...block, layout: block.layout != null ? { ...block.layout } : block.layout }));

    for (const header of result.filter((block): block is Laid => block.type === 'header' && hasLayout(block))) {
        const missing = HEADER_MIN_ROWS - header.layout.h;
        if (missing <= 0) {
            continue;
        }
        const bottom = header.layout.y + header.layout.h;
        result = result.map((block) => {
            if (block.id === header.id) {
                return { ...block, layout: { ...header.layout, h: HEADER_MIN_ROWS } };
            }
            if (hasLayout(block) && (block.page ?? 0) === (header.page ?? 0) && block.layout.y >= bottom) {
                return { ...block, layout: { ...block.layout, y: block.layout.y + missing } };
            }

            return block;
        });
    }

    return result;
}

/**
 * Close the holes a generated report leaves when blocks without data are hidden (no
 * WooCommerce → no sales KPIs; no AI key → no summary next to the health gauge):
 *
 * 1. A row whose blocks no longer reach across the page — and that nothing else overlaps —
 *    stretches them proportionally to fill the width again.
 * 2. Rows left completely empty are removed, pulling everything below up.
 *
 * Purely visual and deterministic, so the portal and the PDF reflow identically.
 */
export function reflowGaps(blocks: Block[]): Block[] {
    const laid = blocks.filter(hasLayout);
    if (laid.length === 0) {
        return blocks;
    }

    const layouts = new Map<string, BlockLayout>(laid.map((block) => [block.id, { ...block.layout }]));

    // 1. Stretch rows (blocks starting on the same y).
    const rows = [...new Set(laid.map((block) => block.layout.y))].sort((a, b) => a - b);
    for (const y of rows) {
        const band = laid.filter((block) => block.layout.y === y).sort((a, b) => a.layout.x - b.layout.x);
        const used = band.reduce((sum, block) => sum + block.layout.w, 0);
        if (GRID_COLS - used < MIN_GAP_COLS) {
            continue;
        }
        const bandBottom = Math.max(...band.map((block) => block.layout.y + block.layout.h));
        const crossed = laid.some((block) => block.layout.y !== y && overlapsRows(block.layout, y, bandBottom));
        if (crossed) {
            continue;
        }

        let x = 0;
        band.forEach((block, index) => {
            const width = index === band.length - 1 ? GRID_COLS - x : Math.max(1, Math.round((block.layout.w / used) * GRID_COLS));
            layouts.set(block.id, { ...block.layout, x, w: width });
            x += width;
        });
    }

    // 2. Drop fully empty rows between blocks.
    const bottom = Math.max(...[...layouts.values()].map((layout) => layout.y + layout.h));
    const occupied = new Array<boolean>(bottom).fill(false);
    for (const layout of layouts.values()) {
        for (let row = layout.y; row < layout.y + layout.h; row += 1) {
            occupied[row] = true;
        }
    }
    const shiftAt: number[] = [];
    let removed = 0;
    for (let row = 0; row < bottom; row += 1) {
        shiftAt[row] = removed;
        if (!occupied[row]) {
            removed += 1;
        }
    }
    for (const [id, layout] of layouts) {
        layouts.set(id, { ...layout, y: layout.y - (shiftAt[layout.y] ?? 0) });
    }

    return blocks.map((block) => (block.layout != null ? { ...block, layout: layouts.get(block.id) ?? block.layout } : block));
}
