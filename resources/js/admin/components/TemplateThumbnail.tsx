import type { ReactElement } from 'react';

import type { Block, BlockLayout } from '@shared/blocks/types';

/** Tint per kind of block, so the thumbnail reads like the report it will produce. */
function tone(type: string): string {
    if (type === 'header' || type === 'cover' || type === 'back_cover') return 'hsl(var(--ir-foreground) / 0.75)';
    if (type === 'kpi' || type === 'sales_summary' || type === 'goal' || type === 'healthscore') return 'hsl(var(--ir-accent) / 0.45)';
    if (type === 'chart' || type === 'geo_map' || type === 'funnel') return 'hsl(var(--ir-info) / 0.35)';
    if (type === 'narrative' || type === 'advisory' || type === 'cta') return 'hsl(var(--ir-muted-foreground) / 0.25)';

    return 'hsl(var(--ir-muted-foreground) / 0.18)';
}

/**
 * A miniature wireframe of a template's first page, drawn from the blocks' grid layout —
 * so a template is recognised at a glance (gallery, the editor's start screen, pickers).
 * A first page that is only a cover is skipped for the next one, which shows the content.
 */
export function TemplateThumbnail({ blocks }: { blocks: Block[] }): ReactElement {
    const laid = blocks.filter((block) => block.layout != null) as (Block & { layout: BlockLayout })[];
    const pages = [...new Set(laid.map((block) => block.page ?? 0))].sort((a, b) => a - b);
    const coverOnly = (page: number): boolean => laid.filter((block) => (block.page ?? 0) === page).every((block) => block.type === 'cover');
    const shown = pages.find((page) => !coverOnly(page)) ?? pages[0] ?? 0;
    const page = laid.filter((block) => (block.page ?? 0) === shown);
    const rows = Math.max(12, ...page.map((block) => block.layout.y + block.layout.h));
    const visibleRows = Math.min(rows, 30);

    return (
        <svg viewBox={`0 0 120 ${visibleRows * 4}`} preserveAspectRatio="xMidYMin slice" className="ir-h-full ir-w-full" aria-hidden>
            {page
                .filter((block) => block.layout.y < visibleRows)
                .map((block) => (
                    <rect
                        key={block.id}
                        x={block.layout.x * 10 + 1}
                        y={block.layout.y * 4 + 1}
                        width={Math.max(1, block.layout.w * 10 - 2)}
                        height={Math.max(1, block.layout.h * 4 - 2)}
                        rx={1.5}
                        fill={tone(block.type)}
                    />
                ))}
        </svg>
    );
}
