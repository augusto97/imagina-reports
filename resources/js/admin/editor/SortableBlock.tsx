import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical, Trash2 } from 'lucide-react';
import { type ReactElement } from 'react';

import type { Block } from '@shared/blocks/types';

import { Input } from '../components/ui';
import type { CatalogEntry } from '../types';
import { DATA_BLOCKS } from './blockFactory';
import { NarrativeEditor } from './NarrativeEditor';

function str(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

export function SortableBlock({
    block,
    catalog,
    onChange,
    onRemove,
}: {
    block: Block;
    catalog: CatalogEntry[];
    onChange: (block: Block) => void;
    onRemove: () => void;
}): ReactElement {
    const { attributes, listeners, setNodeRef, transform, transition } = useSortable({ id: block.id });
    const isData = DATA_BLOCKS.includes(block.type);
    const titleKey = block.type === 'kpi' ? 'label' : 'title';

    const setProp = (key: string, value: string): void => {
        onChange({ ...block, props: { ...block.props, [key]: value } });
    };

    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className="ir-rounded-lg ir-border ir-bg-card ir-p-4"
        >
            <div className="ir-flex ir-items-center ir-justify-between">
                <div className="ir-flex ir-items-center ir-gap-2">
                    <button type="button" className="ir-cursor-grab ir-text-muted-foreground" {...attributes} {...listeners}>
                        <GripVertical className="ir-size-4" />
                    </button>
                    <span className="ir-text-sm ir-font-medium">{block.type}</span>
                </div>
                <button type="button" onClick={onRemove} className="ir-text-muted-foreground hover:ir-text-red-500">
                    <Trash2 className="ir-size-4" />
                </button>
            </div>

            <div className="ir-mt-3 ir-flex ir-flex-col ir-gap-2">
                {block.type !== 'divider' && block.type !== 'narrative' && (
                    <Input
                        placeholder={titleKey === 'label' ? 'Etiqueta' : 'Título'}
                        value={str(block.props?.[titleKey])}
                        onChange={(event) => setProp(titleKey, event.target.value)}
                    />
                )}

                {isData && (
                    <select
                        className="ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm"
                        value={block.binding ? `${block.binding.source}|${block.binding.metric}` : ''}
                        onChange={(event) => {
                            const [source, metric] = event.target.value.split('|');
                            onChange({
                                ...block,
                                binding: source !== undefined && metric !== undefined ? { source, metric } : null,
                            });
                        }}
                    >
                        <option value="">Vincular métrica…</option>
                        {catalog.map((entry) => (
                            <option key={entry.key} value={`${entry.source}|${entry.metric}`}>
                                {entry.label} ({entry.source})
                            </option>
                        ))}
                    </select>
                )}

                {block.type === 'narrative' && (
                    <NarrativeEditor value={str(block.props?.text)} onChange={(html) => setProp('text', html)} />
                )}
            </div>
        </div>
    );
}
