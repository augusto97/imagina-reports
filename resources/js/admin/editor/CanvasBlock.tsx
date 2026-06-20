import { Copy, GripVertical, Trash2 } from 'lucide-react';
import { type MouseEvent, type ReactElement } from 'react';

import { BlockRenderer } from '@shared/blocks/BlockRenderer';
import type { Block } from '@shared/blocks/types';
import { cn } from '@shared/lib/utils';

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

    return (
        <div
            onClick={onSelect}
            className={cn(
                'ir-group ir-relative ir-h-full ir-cursor-pointer ir-overflow-hidden ir-rounded-lg ir-transition',
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
                <BlockRenderer block={block} data={data} />
            </div>
        </div>
    );
}
