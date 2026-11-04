import { type Block, type BlockLayout, GRID_COLS, GRID_MARGIN, GRID_ROW_HEIGHT } from './types';

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

/** How the report is being shown — print shows every table row, the portal pages them. */
export type FitMode = 'screen' | 'print';

const PX_PER_ROW = GRID_ROW_HEIGHT + GRID_MARGIN;

function rowsFor(px: number): number {
    return Math.max(2, Math.ceil((px + GRID_MARGIN) / PX_PER_ROW));
}

function plainText(value: unknown): string {
    return typeof value === 'string' ? value.replace(/<\/(p|li|h\d)>/gi, '\n').replace(/<[^>]*>/g, '').trim() : '';
}

function textLines(text: string, cols: number, mode: FitMode): number {
    // Characters that fit per grid column at 14px Inter (a little conservative, so a wrap we
    // didn't predict adds air instead of cutting a line).
    const perLine = Math.max(12, cols * (mode === 'print' ? 8 : 9));

    return text.split('\n').filter((line) => line.trim() !== '').reduce((sum, line) => sum + Math.max(1, Math.ceil(line.length / perLine)), 0);
}

/**
 * How many grid rows a block's CONTENT needs, or null for blocks whose design height is the
 * point (charts, KPIs, images, covers…). Estimates in px from the renderer's own metrics
 * (card padding, title, table row, timeline item) and rounds up to whole rows.
 */
export function contentRows(block: Block, data: unknown, mode: FitMode, compact = false): number | null {
    const cols = block.layout?.w ?? GRID_COLS;
    const props = block.props ?? {};
    const hasTitle = typeof props.title === 'string' && props.title !== '' && block.style?.hideTitle !== true;
    const chrome = (compact ? 32 : 40) + (hasTitle ? 32 : 0);

    switch (block.type) {
        case 'table': {
            const rows = Array.isArray(data) ? data.length : 0;
            if (rows === 0) {
                return null;
            }
            const pageSizeRaw = Number(block.style?.rows_per_page);
            const pageSize = pageSizeRaw > 0 ? pageSizeRaw : 10;
            const shown = mode === 'print' ? rows : Math.min(rows, pageSize);
            const toolbar = mode === 'screen' && rows > pageSize ? 40 + 44 : 0; // search + pager

            return rowsFor((hasTitle ? chrome : chrome + 32) + 30 + shown * 34 + toolbar);
        }
        case 'worklog_timeline': {
            const entries = Array.isArray(data) ? data : [];
            if (entries.length === 0) {
                return null;
            }
            const items = entries.reduce<number>((sum, entry) => {
                const record = entry !== null && typeof entry === 'object' ? (entry as Record<string, unknown>) : {};
                const lines = textLines(typeof record.description === 'string' ? record.description : '', cols - 1, mode);
                const shot = typeof record.screenshot_url === 'string' && record.screenshot_url !== '' ? 48 : 0;

                return sum + Math.max(lines * 20 + 20, shot) + 12;
            }, 0);

            return rowsFor(chrome + items - 12 + 4);
        }
        case 'narrative':
        case 'advisory': {
            const text = plainText(typeof data === 'string' && data !== '' ? data : props.text);
            if (text === '') {
                return null;
            }
            const paragraphs = text.split('\n').filter((line) => line.trim() !== '').length;

            const frame = block.type === 'advisory' ? 40 + 20 : props.variant === 'footer_cta' ? 32 : chrome;
            const width = props.variant === 'footer_cta' ? cols - 2 : cols;

            return rowsFor(frame + textLines(text, width, mode) * 23 + (paragraphs - 1) * 12);
        }
        case 'healthscore':
            return rowsFor(chrome + (cols >= 6 ? 140 : 190));
        case 'kpi': {
            // Room for the sparkline when the metric has a daily series; otherwise as designed.
            const series = data !== null && typeof data === 'object' ? (data as Record<string, unknown>).series : null;

            return Array.isArray(series) && series.length >= 2 ? Math.max(block.layout?.h ?? 0, rowsFor((compact ? 32 : 40) + 32 + 8 + 34 + 6 + 20 + 8 + 28)) : null;
        }
        default:
            return null;
    }
}

/**
 * Size each row of the report to what its blocks actually hold — a 3-task work log no longer
 * sits in a half-empty card and a 7-row table no longer loses its last row to the cell edge.
 * Works on rows (blocks sharing the same y and height, crossed by nothing else): the row takes
 * the height its tallest need asks for; blocks without a content need (charts, KPIs) keep
 * their designed height, so a row only shrinks when everything in it can. Everything below
 * moves with it. Deterministic, so the portal and the PDF fit identically.
 */
export function fitContent(blocks: Block[], need: (block: Block) => number | null): Block[] {
    let result = blocks.map((block) => ({ ...block, layout: block.layout != null ? { ...block.layout } : block.layout }));
    const handled = new Set<number>();

    for (;;) {
        const laid = result.filter(hasLayout);
        const next = [...new Set(laid.map((block) => block.layout.y))].sort((a, b) => a - b).find((y) => !handled.has(y));
        if (next === undefined) {
            break;
        }
        handled.add(next);

        const band = laid.filter((block) => block.layout.y === next);
        const height = band[0]?.layout.h ?? 0;
        if (band.some((block) => block.layout.h !== height)) {
            continue;
        }
        const bottom = next + height;
        if (laid.some((block) => block.layout.y !== next && overlapsRows(block.layout, next, bottom))) {
            continue;
        }

        const target = Math.max(...band.map((block) => need(block) ?? height));
        const delta = target - height;
        if (delta === 0) {
            continue;
        }

        const ids = new Set(band.map((block) => block.id));
        result = result.map((block) => {
            if (!hasLayout(block)) {
                return block;
            }
            if (ids.has(block.id)) {
                return { ...block, layout: { ...block.layout, h: target } };
            }

            return block.layout.y >= bottom ? { ...block, layout: { ...block.layout, y: block.layout.y + delta } } : block;
        });
        // Rows below moved: their new y values haven't been visited yet.
        for (const y of [...handled]) {
            if (y >= bottom) {
                handled.delete(y);
            }
        }
    }

    return result;
}
