import { type DragEndEvent, DndContext, PointerSensor, closestCenter, useSensor, useSensors } from '@dnd-kit/core';
import { SortableContext, arrayMove, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { Sparkles } from 'lucide-react';
import { type ReactElement, useState } from 'react';

import { BlockList } from '@shared/blocks/BlockRenderer';
import type { Block, BlockType } from '@shared/blocks/types';

import { useAiTemplate, useCreateReportTemplate, useMetricCatalog, useSites } from '../api';
import { Button, Card, Field, Input } from '../components/ui';
import { makeBlock, PALETTE, sampleData } from './blockFactory';
import { SortableBlock } from './SortableBlock';

function extractBlockErrors(error: unknown): string[] {
    if (typeof error === 'object' && error !== null && 'response' in error) {
        const response = (error as { response?: { data?: { errors?: { blocks?: unknown } } } }).response;
        const blocks = response?.data?.errors?.blocks;
        if (Array.isArray(blocks)) {
            return blocks.filter((item): item is string => typeof item === 'string');
        }
    }

    return [];
}

export function EditorScreen(): ReactElement {
    const { data: sites = [] } = useSites();
    const [siteId, setSiteId] = useState<number | null>(null);
    const { data: catalog = [] } = useMetricCatalog(siteId);
    const create = useCreateReportTemplate();
    const ai = useAiTemplate(siteId ?? 0);

    const [name, setName] = useState('');
    const [aiPrompt, setAiPrompt] = useState('');
    const [blocks, setBlocks] = useState<Block[]>([makeBlock('header')]);
    const [errors, setErrors] = useState<string[]>([]);

    const generateWithAi = (): void => {
        ai.mutate(aiPrompt, {
            onSuccess: (result) => {
                setBlocks(result.blocks.length > 0 ? result.blocks : [makeBlock('header')]);
                setErrors([]);
            },
            onError: () => setErrors(['La IA no pudo generar un borrador válido.']),
        });
    };

    const sensors = useSensors(useSensor(PointerSensor));

    const addBlock = (type: BlockType): void => setBlocks((prev) => [...prev, makeBlock(type)]);
    const updateBlock = (next: Block): void => setBlocks((prev) => prev.map((b) => (b.id === next.id ? next : b)));
    const removeBlock = (id: string): void => setBlocks((prev) => prev.filter((b) => b.id !== id));

    const onDragEnd = (event: DragEndEvent): void => {
        const { active, over } = event;
        if (over !== null && active.id !== over.id) {
            setBlocks((prev) => {
                const oldIndex = prev.findIndex((b) => b.id === String(active.id));
                const newIndex = prev.findIndex((b) => b.id === String(over.id));

                return oldIndex < 0 || newIndex < 0 ? prev : arrayMove(prev, oldIndex, newIndex);
            });
        }
    };

    const save = (): void => {
        create.mutate(
            { name, blocks },
            {
                onSuccess: () => setErrors([]),
                onError: (error) => setErrors(extractBlockErrors(error)),
            },
        );
    };

    const previewData: Record<string, unknown> = {};
    for (const block of blocks) {
        previewData[block.id] = sampleData(block);
    }

    return (
        <div className="ir-grid ir-grid-cols-[1fr_minmax(0,28rem)] ir-gap-6">
            <div className="ir-flex ir-flex-col ir-gap-6">
                <Card title="Plantilla">
                    <div className="ir-flex ir-flex-col ir-gap-3">
                        <Field label="Nombre">
                            <Input value={name} onChange={(event) => setName(event.target.value)} />
                        </Field>
                        <Field label="Sitio (para el catálogo de métricas)">
                            <select
                                className="ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm"
                                value={siteId ?? ''}
                                onChange={(event) => setSiteId(event.target.value === '' ? null : Number(event.target.value))}
                            >
                                <option value="">Selecciona…</option>
                                {sites.map((site) => (
                                    <option key={site.id} value={site.id}>
                                        {site.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Generar con IA (opcional: enfoque)">
                            <div className="ir-flex ir-gap-2">
                                <Input
                                    placeholder="p. ej. enfoque en SEO y seguridad"
                                    value={aiPrompt}
                                    onChange={(event) => setAiPrompt(event.target.value)}
                                />
                                <Button
                                    variant="ghost"
                                    onClick={generateWithAi}
                                    disabled={siteId === null || ai.isPending}
                                >
                                    <Sparkles className="ir-size-4" />
                                    IA
                                </Button>
                            </div>
                        </Field>
                        <div className="ir-flex ir-flex-wrap ir-gap-2">
                            {PALETTE.map((item) => (
                                <Button key={item.type} variant="ghost" onClick={() => addBlock(item.type)}>
                                    + {item.label}
                                </Button>
                            ))}
                        </div>
                        <Button onClick={save} disabled={create.isPending || name === ''}>
                            Guardar plantilla
                        </Button>
                        {create.isSuccess && <p className="ir-text-xs ir-text-emerald-600">Plantilla guardada.</p>}
                        {errors.map((error) => (
                            <p key={error} className="ir-text-xs ir-text-red-500">
                                {error}
                            </p>
                        ))}
                    </div>
                </Card>

                <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                    <SortableContext items={blocks.map((b) => b.id)} strategy={verticalListSortingStrategy}>
                        <div className="ir-flex ir-flex-col ir-gap-3">
                            {blocks.map((block) => (
                                <SortableBlock
                                    key={block.id}
                                    block={block}
                                    catalog={catalog}
                                    onChange={updateBlock}
                                    onRemove={() => removeBlock(block.id)}
                                />
                            ))}
                        </div>
                    </SortableContext>
                </DndContext>
            </div>

            <div className="ir-sticky ir-top-8 ir-self-start">
                <Card title="Vista previa">
                    <BlockList blocks={blocks} data={previewData} />
                </Card>
            </div>
        </div>
    );
}
