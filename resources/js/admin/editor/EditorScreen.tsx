import { type DragEndEvent, DndContext, PointerSensor, closestCenter, useSensor, useSensors } from '@dnd-kit/core';
import { SortableContext, arrayMove, rectSortingStrategy } from '@dnd-kit/sortable';
import { LayoutTemplate, RefreshCw, Sparkles } from 'lucide-react';
import { type ReactElement, useEffect, useState } from 'react';

import type { Block, BlockType } from '@shared/blocks/types';

import {
    type CalcMetric,
    type PreviewResult,
    useAiTemplate,
    useCreateReportTemplate,
    useDefaultTemplateBlocks,
    useMetricCatalog,
    usePreview,
    useReportTemplate,
    useSites,
    useSyncSite,
    useUpdateReportTemplate,
} from '../api';
import { Button, Card, Field, Input } from '../components/ui';
import type { CatalogEntry } from '../types';
import { useAdminUi } from '../store';
import { CanvasBlock } from './CanvasBlock';
import { makeBlock, nextWidth, PALETTE, sampleData, WIDTH_SPAN, widthOf } from './blockFactory';
import { Inspector } from './Inspector';

function currentMonth(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

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
    const defaultTpl = useDefaultTemplateBlocks();
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
    const [calcMetrics, setCalcMetrics] = useState<CalcMetric[]>([]);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [preview_, setPreview] = useState<PreviewResult | null>(null);
    const [errors, setErrors] = useState<string[]>([]);

    useEffect(() => {
        if (editingTemplateId === null) {
            setName('');
            setBlocks([makeBlock('header')]);
            setCalcMetrics([]);
            setSelectedId(null);
            setErrors([]);
        }
    }, [editingTemplateId]);

    useEffect(() => {
        if (editingTemplate !== undefined && editingTemplate.id === editingTemplateId) {
            const loaded = editingTemplate.blocks as Block[];
            setName(editingTemplate.name);
            setBlocks(loaded.length > 0 ? loaded : [makeBlock('header')]);
            setCalcMetrics(editingTemplate.calculated_metrics ?? []);
            setSelectedId(null);
            setErrors([]);
        }
    }, [editingTemplate, editingTemplateId]);

    const runPreview = preview.mutate;
    useEffect(() => {
        if (siteId === null) {
            setPreview(null);

            return;
        }

        const timer = setTimeout(() => {
            runPreview({ blocks, calculated_metrics: calcMetrics, ...monthPeriod(month) }, { onSuccess: (result) => setPreview(result) });
        }, 400);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [siteId, blocks, calcMetrics, month]);

    const loadDefaultTemplate = (): void => {
        defaultTpl.mutate(undefined, {
            onSuccess: (loaded) => {
                setBlocks(loaded.length > 0 ? loaded : [makeBlock('header')]);
                setSelectedId(null);
                setErrors([]);
            },
        });
    };

    const generateWithAi = (): void => {
        ai.mutate(aiPrompt, {
            onSuccess: (result) => {
                setBlocks(result.blocks.length > 0 ? result.blocks : [makeBlock('header')]);
                setSelectedId(null);
                setErrors([]);
            },
            onError: () => setErrors(['La IA no pudo generar un borrador válido.']),
        });
    };

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }));

    const addBlock = (type: BlockType): void => {
        const block = makeBlock(type);
        setBlocks((prev) => [...prev, block]);
        setSelectedId(block.id);
    };
    const updateBlock = (next: Block): void => setBlocks((prev) => prev.map((b) => (b.id === next.id ? next : b)));
    const removeBlock = (id: string): void => {
        setBlocks((prev) => prev.filter((b) => b.id !== id));
        setSelectedId((current) => (current === id ? null : current));
    };
    const cycleWidth = (block: Block): void => updateBlock({ ...block, style: { ...block.style, width: nextWidth(widthOf(block)) } });

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

        const payload = { name, blocks, calculated_metrics: calcMetrics };
        if (editingTemplateId !== null) {
            update.mutate(payload, handlers);
        } else {
            create.mutate(payload, handlers);
        }
    };

    const addCalc = (): void => setCalcMetrics((prev) => [...prev, { key: `m${prev.length + 1}`, label: '', formula: '' }]);
    const updateCalc = (index: number, patch: Partial<CalcMetric>): void =>
        setCalcMetrics((prev) => prev.map((metric, i) => (i === index ? { ...metric, ...patch } : metric)));
    const removeCalc = (index: number): void => setCalcMetrics((prev) => prev.filter((_, i) => i !== index));

    // Calculated metrics appear in the binding picker as a "calc" source.
    const fullCatalog: CatalogEntry[] = [
        ...catalog,
        ...calcMetrics
            .filter((metric) => metric.key !== '')
            .map((metric) => ({
                source: 'calc',
                metric: metric.key,
                key: `calc.${metric.key}`,
                label: metric.label !== '' ? metric.label : metric.key,
                type: 'number',
                unit: null,
                dimensions: [],
            })),
    ];

    const triggerSync = (): void => {
        if (siteId === null) {
            return;
        }
        syncSite.mutate(undefined, {
            onSuccess: () => {
                const delays = [2500, 3000, 4000, 5000, 6000];
                delays.forEach((_, index) => {
                    const elapsed = delays.slice(0, index + 1).reduce((sum, value) => sum + value, 0);
                    setTimeout(() => {
                        runPreview(
                            { blocks, calculated_metrics: calcMetrics, ...monthPeriod(month) },
                            { onSuccess: (result) => setPreview((current) => (current?.has_data === true ? current : result)) },
                        );
                    }, elapsed);
                });
            },
        });
    };

    const hasRealData = siteId !== null && preview_ !== null;
    const renderData: Record<string, unknown> = {};
    if (hasRealData) {
        Object.assign(renderData, preview_.data);
    } else {
        for (const block of blocks) {
            renderData[block.id] = sampleData(block);
        }
    }

    const selectedBlock = blocks.find((b) => b.id === selectedId) ?? null;

    return (
        <div className="ir-grid ir-grid-cols-[15rem_1fr_19rem] ir-gap-5">
            {/* ---- Left: palette + template settings ---- */}
            <aside className="ir-flex ir-flex-col ir-gap-4">
                <Card title="Plantilla">
                    <div className="ir-flex ir-flex-col ir-gap-3">
                        <div className="ir-flex ir-items-center ir-justify-between">
                            <span className="ir-text-xs ir-font-medium ir-text-muted-foreground">
                                {editingTemplateId !== null ? 'Editando' : 'Nueva'}
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
                        <Field label="Sitio (datos y métricas)">
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
                        <div className="ir-flex ir-gap-2">
                            <Input placeholder="Enfoque para la IA…" value={aiPrompt} onChange={(event) => setAiPrompt(event.target.value)} />
                            <Button variant="ghost" onClick={generateWithAi} disabled={siteId === null || ai.isPending}>
                                <Sparkles className="ir-size-4" />
                                IA
                            </Button>
                        </div>
                        <Button variant="ghost" onClick={loadDefaultTemplate} disabled={defaultTpl.isPending}>
                            <LayoutTemplate className="ir-size-4" />
                            Plantilla por defecto
                        </Button>
                        <Button onClick={save} disabled={create.isPending || update.isPending || name === ''}>
                            {editingTemplateId !== null ? 'Actualizar' : 'Guardar plantilla'}
                        </Button>
                        {(create.isSuccess || update.isSuccess) && <p className="ir-text-xs ir-text-emerald-600">Guardada.</p>}
                        {errors.map((error) => (
                            <p key={error} className="ir-text-xs ir-text-red-500">
                                {error}
                            </p>
                        ))}
                    </div>
                </Card>

                <Card title="Añadir bloque">
                    <div className="ir-flex ir-flex-wrap ir-gap-2">
                        {PALETTE.map((item) => (
                            <Button key={item.type} variant="ghost" onClick={() => addBlock(item.type)}>
                                + {item.label}
                            </Button>
                        ))}
                    </div>
                </Card>

                <Card title="Métricas calculadas">
                    <div className="ir-flex ir-flex-col ir-gap-3">
                        {calcMetrics.map((metric, index) => (
                            <div key={index} className="ir-flex ir-flex-col ir-gap-1 ir-rounded-md ir-border ir-p-2">
                                <div className="ir-flex ir-gap-1">
                                    <Input placeholder="clave" value={metric.key} onChange={(event) => updateCalc(index, { key: event.target.value })} />
                                    <button type="button" className="ir-px-2 ir-text-muted-foreground hover:ir-text-red-500" onClick={() => removeCalc(index)}>
                                        ×
                                    </button>
                                </div>
                                <Input placeholder="Etiqueta" value={metric.label} onChange={(event) => updateCalc(index, { label: event.target.value })} />
                                <Input
                                    placeholder="ga4.sessions / woocommerce.orders"
                                    value={metric.formula}
                                    onChange={(event) => updateCalc(index, { formula: event.target.value })}
                                />
                            </div>
                        ))}
                        <Button variant="ghost" onClick={addCalc}>
                            + Añadir métrica
                        </Button>
                        <p className="ir-text-xs ir-text-muted-foreground">
                            Usa claves de métricas (p. ej. <code>ga4.sessions</code>) y <code>+ - * / ( )</code>. Aparecen como fuente «calc» al
                            vincular un bloque.
                        </p>
                    </div>
                </Card>
            </aside>

            {/* ---- Center: the WYSIWYG canvas ---- */}
            <div className="ir-flex ir-flex-col ir-gap-3">
                <div className="ir-flex ir-items-end ir-justify-between ir-gap-3">
                    <Field label="Periodo de la vista previa">
                        <Input type="month" value={month} onChange={(event) => setMonth(event.target.value)} className="ir-w-44" />
                    </Field>
                    <Button variant="ghost" onClick={triggerSync} disabled={siteId === null || syncSite.isPending}>
                        <RefreshCw className={syncSite.isPending ? 'ir-size-4 ir-animate-spin' : 'ir-size-4'} />
                        Sincronizar ahora
                    </Button>
                </div>

                {siteId === null ? (
                    <p className="ir-text-xs ir-text-amber-600">Datos de ejemplo. Elige un sitio para ver datos reales.</p>
                ) : hasRealData && !preview_.has_data ? (
                    <p className="ir-text-xs ir-text-amber-600">Sin datos para este periodo. Usa «Sincronizar ahora».</p>
                ) : hasRealData ? (
                    <p className="ir-text-xs ir-text-emerald-600">Datos reales · {preview_.sources_with_data.length} fuente(s).</p>
                ) : (
                    <p className="ir-text-xs ir-text-muted-foreground">Cargando datos…</p>
                )}

                <div className="ir-rounded-xl ir-border ir-bg-card ir-p-5">
                    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                        <SortableContext items={blocks.map((b) => b.id)} strategy={rectSortingStrategy}>
                            <div className="ir-grid ir-grid-cols-6 ir-gap-4">
                                {blocks.map((block) => (
                                    <div key={block.id} className={WIDTH_SPAN[widthOf(block)]}>
                                        <CanvasBlock
                                            block={block}
                                            data={renderData[block.id]}
                                            selected={block.id === selectedId}
                                            onSelect={() => setSelectedId(block.id)}
                                            onRemove={() => removeBlock(block.id)}
                                            onCycleWidth={() => cycleWidth(block)}
                                        />
                                    </div>
                                ))}
                            </div>
                        </SortableContext>
                    </DndContext>
                    {blocks.length === 0 && (
                        <p className="ir-py-12 ir-text-center ir-text-sm ir-text-muted-foreground">
                            Lienzo vacío. Añade bloques desde la izquierda o empieza con la plantilla por defecto.
                        </p>
                    )}
                </div>
            </div>

            {/* ---- Right: inspector for the selected block ---- */}
            <aside className="ir-sticky ir-top-8 ir-self-start">
                <Card title="Bloque">
                    <Inspector block={selectedBlock} catalog={fullCatalog} onChange={updateBlock} />
                </Card>
            </aside>
        </div>
    );
}
