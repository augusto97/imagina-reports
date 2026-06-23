import { Copy, GripVertical, Trash2 } from 'lucide-react';
import { type MouseEvent, type ReactElement } from 'react';

import { BlockRenderer } from '@shared/blocks/BlockRenderer';
import type { Block } from '@shared/blocks/types';
import { cn } from '@shared/lib/utils';
import { DATA_BLOCKS } from './blockFactory';

/**
 * Corner-radius classes, mirroring the renderer's `Section` (single source of truth).
 * The canvas tile clips its content, so it must follow the block's own `style.radius`
 * — otherwise the wrapper's rounding would override "Esquinas → Rectas" in the editor.
 */
const RADIUS: Record<string, string> = { none: 'ir-rounded-none', sm: 'ir-rounded', md: 'ir-rounded-lg', lg: 'ir-rounded-2xl' };

/**
 * A widget on the dashboard grid: renders the REAL block (WYSIWYG) filling its tile,
 * with a floating toolbar to drag (the grip is react-grid-layout's drag handle),
 * duplicate or remove. Clicking selects it for the inspector; inner content is
 * non-interactive so a click always selects rather than triggering links/tooltips.
 * Resizing is handled by the grid's corner handle.
 */
export function CanvasBlock({
    block,
    data,
    selected,
    onSelect,
    onRemove,
    onDuplicate,
}: {
    block: Block;
    data: unknown;
    selected: boolean;
    onSelect: () => void;
    onRemove: () => void;
    onDuplicate: () => void;
}): ReactElement {
    const stop = (handler: () => void) => (event: MouseEvent): void => {
        event.stopPropagation();
        handler();
    };

    const radius = RADIUS[typeof block.style?.radius === 'string' ? block.style.radius : ''] ?? 'ir-rounded-lg';

    // A data block whose bound metric has no value for the previewed period. Show an
    // honest placeholder (keeps the block visible/selectable) instead of letting the
    // renderer hide it — and never fake sample rows, which would contradict the KPIs.
    const isEmpty = data === undefined || data === null || (Array.isArray(data) && data.length === 0);
    const showEmptyState = isEmpty && DATA_BLOCKS.includes(block.type);
    const emptyTitle =
        typeof block.props?.title === 'string' && block.props.title !== ''
            ? block.props.title
            : typeof block.props?.label === 'string'
              ? block.props.label
              : '';

    return (
        <div
            onClick={onSelect}
            className={cn(
                'ir-group ir-relative ir-h-full ir-cursor-pointer ir-overflow-hidden ir-transition',
                radius,
                selected ? 'ir-ring-2 ir-ring-primary' : 'hover:ir-ring-1 hover:ir-ring-border',
            )}
        >
            <div
                className={cn(
                    'ir-absolute ir-right-2 ir-top-2 ir-z-10 ir-flex ir-gap-0.5 ir-rounded-md ir-border ir-bg-card ir-p-1 ir-shadow-sm ir-transition',
                    selected ? 'ir-opacity-100' : 'ir-opacity-0 group-hover:ir-opacity-100',
                )}
            >
                <button
                    type="button"
                    className="ir-drag-handle ir-cursor-grab ir-rounded ir-p-1 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground"
                    title="Mover"
                    onClick={(event) => event.stopPropagation()}
                >
                    <GripVertical className="ir-size-4" />
                </button>
                <button
                    type="button"
                    className="ir-rounded ir-p-1 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground"
                    title="Duplicar"
                    onClick={stop(onDuplicate)}
                >
                    <Copy className="ir-size-4" />
                </button>
                <button
                    type="button"
                    className="ir-rounded ir-p-1 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-red-500"
                    title="Eliminar"
                    onClick={stop(onRemove)}
                >
                    <Trash2 className="ir-size-4" />
                </button>
            </div>

            <div className="ir-pointer-events-none ir-h-full ir-overflow-hidden">
                {showEmptyState ? (
                    <div className="ir-flex ir-h-full ir-flex-col ir-gap-2 ir-p-4">
                        {emptyTitle !== '' && (
                            <span className="ir-text-[11px] ir-font-semibold ir-uppercase ir-tracking-wide ir-text-muted-foreground">
                                {emptyTitle}
                            </span>
                        )}
                        <div className="ir-flex ir-flex-1 ir-items-center ir-justify-center ir-rounded-lg ir-border ir-border-dashed ir-border-border ir-px-3 ir-text-center ir-text-xs ir-text-muted-foreground">
                            Sin datos para este periodo
                        </div>
                    </div>
                ) : (
                    <BlockRenderer block={block} data={data} />
                )}
            </div>
        </div>
    );
}
