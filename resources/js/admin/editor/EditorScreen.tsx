import { type DragEndEvent, DndContext, PointerSensor, closestCenter, useSensor, useSensors } from '@dnd-kit/core';
import { SortableContext, arrayMove, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { RefreshCw, Sparkles } from 'lucide-react';
import { type ReactElement, useEffect, useState } from 'react';

import { BlockList } from '@shared/blocks/BlockRenderer';
import type { Block, BlockType } from '@shared/blocks/types';

import {
    type PreviewResult,
    useAiTemplate,
    useCreateReportTemplate,
    useMetricCatalog,
    usePreview,
    useReportTemplate,
    useSites,
    useSyncSite,
    useUpdateReportTemplate,
} from '../api';
import { Button, Card, Field, Input } from '../components/ui';
import { useAdminUi } from '../store';
import { makeBlock, PALETTE, sampleData } from './blockFactory';
import { SortableBlock } from './SortableBlock';

/** Current month as YYYY-MM, the default reporting period. */
function currentMonth(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

/** Expand a YYYY-MM month into an inclusive {period_start, period_end} window. */
function monthPeriod(month: string): { period_start: string; period_end: string } {
    const parts = month.split('-');
    const year = Number(parts[0] ?? new Date().getFullYear());
    const mon = Number(parts[1] ?? 1);
    const lastDay = new Date(year, mon, 0).getDate();

    return { period_start: `${month}-01`, period_end: `${month}-${String(lastDay).padStart(2, '0')}` };
}

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

    const editingTemplateId = useAdminUi((state) => state.editingTemplateId);
    const editTemplate = useAdminUi((state) => state.editTemplate);
    const { data: editingTemplate } = useReportTemplate(editingTemplateId);
    const update = useUpdateReportTemplate(editingTemplateId ?? 0);

    const preview = usePreview(siteId ?? 0);
    const syncSite = useSyncSite(siteId ?? 0);

    const [name, setName] = useState('');
    const [aiPrompt, setAiPrompt] = useState('');
    const [month, setMonth] = useState(currentMonth());
    const [blocks, setBlocks] = useState<Block[]>([makeBlock('header')]);
    const [preview_, setPreview] = useState<PreviewResult | null>(null);
    const [errors, setErrors] = useState<string[]>([]);

    // Reset to a blank template when "Nueva" is chosen.
    useEffect(() => {
        if (editingTemplateId === null) {
            setName('');
            setBlocks([makeBlock('header')]);
            setErrors([]);
        }
    }, [editingTemplateId]);

    // Load the selected template into the editor for re-editing.
    useEffect(() => {
        if (editingTemplate !== undefined && editingTemplate.id === editingTemplateId) {
            const loaded = editingTemplate.blocks as Block[];
            setName(editingTemplate.name);
            setBlocks(loaded.length > 0 ? loaded : [makeBlock('header')]);
            setErrors([]);
        }
    }, [editingTemplate, editingTemplateId]);

    // Live preview with REAL data: whenever the site, period or layout changes,
    // resolve the draft blocks against the site's snapshots (debounced).
    const runPreview = preview.mutate;
    useEffect(() => {
        if (siteId === null) {
            setPreview(null);

            return;
        }

        const timer = setTimeout(() => {
            runPreview(
                { blocks, ...monthPeriod(month) },
                { onSuccess: (result) => setPreview(result) },
            );
        }, 400);

        return () => clearTimeout(timer);
        // runPreview (react-query mutate) is stable; re-run on real inputs only.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [siteId, blocks, month]);

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
        const handlers = {
            onSuccess: () => setErrors([]),
            onError: (error: unknown) => setErrors(extractBlockErrors(error)),
        };

        if (editingTemplateId !== null) {
            update.mutate({ name, blocks }, handlers);
        } else {
            create.mutate({ name, blocks }, handlers);
        }
    };

    // The preview shows REAL data once a site is selected and resolved; otherwise
    // (no site, or while the first resolve is in flight) it falls back to clearly
    // labeled sample data so the layout is still visible.
    const hasRealData = siteId !== null && preview_ !== null;
    const renderBlocks = hasRealData ? preview_.blocks : blocks;
    const renderData: Record<string, unknown> = {};
    if (hasRealData) {
        Object.assign(renderData, preview_.data);
    } else {
        for (const block of blocks) {
            renderData[block.id] = sampleData(block);
        }
    }

    const triggerSync = (): void => {
        if (siteId === null) {
            return;
        }
        syncSite.mutate(undefined, {
            onSuccess: () => {
                // Snapshots land asynchronously; re-resolve shortly after.
                setTimeout(() => runPreview({ blocks, ...monthPeriod(month) }, { onSuccess: setPreview }), 2500);
            },
        });
    };

    return (
        <div className="ir-grid ir-grid-cols-[1fr_minmax(0,28rem)] ir-gap-6">
            <div className="ir-flex ir-flex-col ir-gap-6">
                <Card title="Plantilla">
                    <div className="ir-flex ir-flex-col ir-gap-3">
                        <div className="ir-flex ir-items-center ir-justify-between">
                            <span className="ir-text-xs ir-font-medium ir-text-muted-foreground">
                                {editingTemplateId !== null ? 'Editando plantilla' : 'Nueva plantilla'}
                            </span>
                            {editingTemplateId !== null && (
                                <Button variant="ghost" onClick={() => editTemplate(null)}>
                                    Nueva
                                </Button>
                            )}
                        </div>
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
                        <Button onClick={save} disabled={create.isPending || update.isPending || name === ''}>
                            {editingTemplateId !== null ? 'Actualizar plantilla' : 'Guardar plantilla'}
                        </Button>
                        {(create.isSuccess || update.isSuccess) && (
                            <p className="ir-text-xs ir-text-emerald-600">Plantilla guardada.</p>
                        )}
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

            <div className="ir-sticky ir-top-8 ir-self-start ir-flex ir-flex-col ir-gap-3">
                <Card title="Vista previa">
                    <div className="ir-mb-4 ir-flex ir-flex-col ir-gap-3">
                        <div className="ir-flex ir-items-end ir-gap-2">
                            <Field label="Periodo">
                                <Input type="month" value={month} onChange={(event) => setMonth(event.target.value)} />
                            </Field>
                            <Button
                                variant="ghost"
                                onClick={triggerSync}
                                disabled={siteId === null || syncSite.isPending}
                                title="Sincroniza las fuentes del sitio para este periodo"
                            >
                                <RefreshCw className={syncSite.isPending ? 'ir-size-4 ir-animate-spin' : 'ir-size-4'} />
                                Sincronizar ahora
                            </Button>
                        </div>

                        {siteId === null ? (
                            <p className="ir-text-xs ir-text-amber-600">
                                Datos de ejemplo. Selecciona un sitio para ver datos reales.
                            </p>
                        ) : preview.isPending && preview_ === null ? (
                            <p className="ir-text-xs ir-text-muted-foreground">Cargando datos reales…</p>
                        ) : hasRealData && !preview_.has_data ? (
                            <p className="ir-text-xs ir-text-amber-600">
                                No hay datos sincronizados para este periodo. Usa «Sincronizar ahora».
                            </p>
                        ) : hasRealData ? (
                            <p className="ir-text-xs ir-text-emerald-600">
                                Datos reales · {preview_.sources_with_data.length} fuente(s) con datos.
                            </p>
                        ) : null}

                        {syncSite.isSuccess && (
                            <p className="ir-text-xs ir-text-muted-foreground">
                                Sincronización en cola; los datos aparecerán en unos segundos.
                            </p>
                        )}
                    </div>

                    <BlockList blocks={renderBlocks} data={renderData} />
                </Card>
            </div>
        </div>
    );
}
