// Shared block model — the TypeScript mirror of App\Reports\Blocks (CLAUDE.md §10).
// The BlockRenderer below is the single source of truth for the portal and the
// Chromium-printed PDF (§10.7 / §11.4).

export type BlockType =
    | 'cover'
    | 'back_cover'
    | 'header'
    | 'kpi'
    | 'chart'
    | 'table'
    | 'funnel'
    | 'geo_map'
    | 'narrative'
    | 'healthscore'
    | 'security_shield'
    | 'worklog_timeline'
    | 'image'
    | 'divider'
    | 'pagebreak'
    | 'sales_summary'
    | 'goal'
    | 'cta'
    | 'comments'
    | 'control'
    | 'custom';

/**
 * Page-navigation config (CLAUDE.md §11 — Looker/Power-BI parity). Lives in the report
 * theme. `position` mirrors Looker's navigation options (tabs / top bar / left sidebar /
 * none); `style` is a predesigned look for the page buttons; `collapsible` lets a sidebar
 * fold away behind a toggle.
 */
export interface ReportNav {
    position?: 'tabs' | 'top' | 'sidebar' | 'hidden';
    style?: 'pill' | 'underline' | 'solid';
    collapsible?: boolean;
}

/** One design-time filter on a dataset block ({dimension} {op} {value}). */
export interface DatasetFilter {
    dimension: string;
    op: string;
    value: string;
}

export interface BlockBinding {
    source: string;
    metric: string;
    dimension?: string;
    compare?: string;
    // Dataset modeling (CLAUDE.md §10 dashboards): pick a measure, break it down by a
    // dimension, and bake in filters. Block filters override the page/dashboard ones.
    measure?: string;
    breakdown?: string;
    filters?: DatasetFilter[];
}

/** Grid placement on the 12-column dashboard canvas (CLAUDE.md §11.3). x/w in columns, y/h in row units. */
export interface BlockLayout {
    x: number;
    y: number;
    w: number;
    h: number;
}

/**
 * Dashboard grid geometry — shared by the editor canvas (react-grid-layout) and the
 * shared renderer's CSS grid, so the editor, portal and PDF place widgets identically.
 */
export const GRID_COLS = 12;
export const GRID_ROW_HEIGHT = 30;
export const GRID_MARGIN = 12;

export interface Block {
    id: string;
    type: BlockType;
    binding?: BlockBinding | null;
    props?: Record<string, unknown>;
    style?: Record<string, unknown>;
    layout?: BlockLayout | null;
    /** Zero-based page index for multi-page reports (CLAUDE.md §11). Defaults to 0. */
    page?: number;
}

/** Resolved metric values keyed by block id (produced by the ReportGenerator, Task 9). */
export type ResolvedBlockData = Record<string, unknown>;

export interface BlockComponentProps {
    block: Block;
    /** The resolved value(s) bound to this block, if any. */
    data?: unknown;
}
