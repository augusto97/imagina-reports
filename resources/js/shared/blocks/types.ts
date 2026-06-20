// Shared block model — the TypeScript mirror of App\Reports\Blocks (CLAUDE.md §10).
// The BlockRenderer below is the single source of truth for the portal and the
// Chromium-printed PDF (§10.7 / §11.4).

export type BlockType =
    | 'header'
    | 'kpi'
    | 'chart'
    | 'table'
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
    | 'custom';

export interface BlockBinding {
    source: string;
    metric: string;
    dimension?: string;
    compare?: string;
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
}

/** Resolved metric values keyed by block id (produced by the ReportGenerator, Task 9). */
export type ResolvedBlockData = Record<string, unknown>;

export interface BlockComponentProps {
    block: Block;
    /** The resolved value(s) bound to this block, if any. */
    data?: unknown;
}
