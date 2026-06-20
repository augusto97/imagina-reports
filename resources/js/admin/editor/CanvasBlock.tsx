import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Columns2, Copy, GripVertical, Trash2 } from 'lucide-react';
import { type MouseEvent, type ReactElement } from 'react';

import { BlockRenderer } from '@shared/blocks/BlockRenderer';
import type { Block } from '@shared/blocks/types';
import { cn } from '@shared/lib/utils';

/**
 * A block on the visual canvas: renders the REAL block (WYSIWYG) and, on hover or
 * when selected, a floating toolbar to drag, cycle its width, or remove it. Clicking
 * selects the block for the inspector. Inner block content is non-interactive so a
 * click always selects rather than triggering links/tooltips.
 */
export function CanvasBlock({
    block,
    data,
    selected,
    onSelect,
    onRemove,
    onCycleWidth,
    onDuplicate,
}: {
    block: Block;
    data: unknown;
    selected: boolean;
    onSelect: () => void;
    onRemove: () => void;
    onCycleWidth: () => void;
    onDuplicate: () => void;
}): ReactElement {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: block.id });

    const stop = (handler: () => void) => (event: MouseEvent): void => {
        event.stopPropagation();
        handler();
    };

    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            onClick={onSelect}
            className={cn(
                'ir-group ir-relative ir-cursor-pointer ir-rounded-lg ir-transition',
                isDragging && 'ir-opacity-60',
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
                    className="ir-cursor-grab ir-rounded ir-p-1 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground"
                    title="Mover"
                    onClick={(event) => event.stopPropagation()}
                    {...attributes}
                    {...listeners}
                >
                    <GripVertical className="ir-size-4" />
                </button>
                <button
                    type="button"
                    className="ir-rounded ir-p-1 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground"
                    title="Ancho (completo / mitad / tercio)"
                    onClick={stop(onCycleWidth)}
                >
                    <Columns2 className="ir-size-4" />
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

            <div className="ir-pointer-events-none">
                <BlockRenderer block={block} data={data} />
            </div>
        </div>
    );
}
