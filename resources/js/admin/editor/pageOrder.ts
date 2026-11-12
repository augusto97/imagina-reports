import type { Block } from '@shared/blocks/types';

import type { PageFilters } from '../types';

/** What a report's pages are made of: the blocks (by `page` index), their names and their filters. */
export interface PageLayout {
    blocks: Block[];
    names: string[];
    filters: PageFilters;
    pageCount: number;
}

/** Old page index → new one, for a page moved from `from` to `to` (the others shift to make room). */
export function movedIndex(page: number, from: number, to: number): number {
    if (page === from) {
        return to;
    }
    if (from < to && page > from && page <= to) {
        return page - 1;
    }
    if (from > to && page >= to && page < from) {
        return page + 1;
    }

    return page;
}

/** Re-key the per-page filters with a new numbering; «all» and dropped pages (null) are handled. */
function remapFilters(filters: PageFilters, map: (page: number) => number | null): PageFilters {
    const next: PageFilters = {};
    for (const [key, value] of Object.entries(filters)) {
        const page = Number(key);
        if (key === 'all' || !Number.isInteger(page)) {
            next[key] = value;
            continue;
        }
        const target = map(page);
        if (target !== null) {
            next[String(target)] = value;
        }
    }

    return next;
}

/** Move a whole page (its blocks, name and filters) to another position. */
export function movePage(layout: PageLayout, from: number, to: number): PageLayout {
    const last = layout.pageCount - 1;
    if (from === to || from < 0 || to < 0 || from > last || to > last) {
        return layout;
    }

    const names = Array.from({ length: layout.pageCount }, (_, index) => layout.names[index] ?? '');
    const [moved] = names.splice(from, 1);
    names.splice(to, 0, moved ?? '');

    return {
        blocks: layout.blocks.map((block) => {
            const page = block.page ?? 0;
            const target = movedIndex(page, from, to);

            return target === page ? block : { ...block, page: target };
        }),
        names,
        filters: remapFilters(layout.filters, (page) => movedIndex(page, from, to)),
        pageCount: layout.pageCount,
    };
}

/** Delete a page: its blocks and filters go, the pages after it move up one. */
export function removePage(layout: PageLayout, page: number): PageLayout {
    if (layout.pageCount <= 1) {
        return layout;
    }

    return {
        blocks: layout.blocks
            .filter((block) => (block.page ?? 0) !== page)
            .map((block) => ((block.page ?? 0) > page ? { ...block, page: (block.page ?? 0) - 1 } : block)),
        names: layout.names.filter((_, index) => index !== page),
        filters: remapFilters(layout.filters, (index) => (index === page ? null : index > page ? index - 1 : index)),
        pageCount: layout.pageCount - 1,
    };
}
