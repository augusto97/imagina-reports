import {
    Calendar,
    FunctionSquare,
    Globe,
    LayoutTemplate,
    PanelLeftClose,
    PanelLeftOpen,
    PanelRightClose,
    PanelRightOpen,
    Palette,
    Plus,
    Redo2,
    RefreshCw,
    Save,
    Shapes,
    Sparkles,
    Undo2,
} from "lucide-react";
import {
    type CSSProperties,
    type ReactElement,
    useEffect,
    useState,
} from "react";
import GridLayout, { type Layout, WidthProvider } from "react-grid-layout";
import "react-grid-layout/css/styles.css";
import "react-resizable/css/styles.css";

import { ReportSettingsProvider } from "@shared/blocks/BlockRenderer";
import { GRID_COLS, GRID_MARGIN, GRID_ROW_HEIGHT } from "@shared/blocks/types";
import type { Block, BlockType } from "@shared/blocks/types";

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
} from "../api";
import { hexToHslString } from "@shared/lib/color";

import { Button, Card, Field, Input } from "../components/ui";
import type { CatalogEntry, ReportTheme } from "../types";
import { useAdminUi } from "../store";
import { CanvasBlock } from "./CanvasBlock";
import {
    defaultSize,
    ensureLayouts,
    makeBlock,
    sampleData,
} from "./blockFactory";
import { BlockPalette } from "./BlockPalette";
import { GALLERY } from "./templateGallery";
import { Inspector } from "./Inspector";
import {
    Section,
    SegmentedControl,
    ToolbarButton,
    ToolbarDivider,
} from "./controls";

/** Width-measuring dashboard grid (react-grid-layout) for the editor canvas. */
const Grid = WidthProvider(GridLayout);

function currentMonth(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
}

function monthPeriod(month: string): {
    period_start: string;
    period_end: string;
} {
    const parts = month.split("-");
    const year = Number(parts[0] ?? new Date().getFullYear());
    const mon = Number(parts[1] ?? 1);
    const lastDay = new Date(year, mon, 0).getDate();

    return {
        period_start: `${month}-01`,
        period_end: `${month}-${String(lastDay).padStart(2, "0")}`,
    };
}

function extractBlockErrors(error: unknown): string[] {
    if (typeof error === "object" && error !== null && "response" in error) {
        const response = (
            error as { response?: { data?: { errors?: { blocks?: unknown } } } }
        ).response;
        const blocks = response?.data?.errors?.blocks;
        if (Array.isArray(blocks)) {
            return blocks.filter(
                (item): item is string => typeof item === "string",
            );
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

    const [name, setName] = useState("");
    const [aiPrompt, setAiPrompt] = useState("");
    const [month, setMonth] = useState(currentMonth());
    const [blocks, setBlocks] = useState<Block[]>([makeBlock("header")]);
    const [calcMetrics, setCalcMetrics] = useState<CalcMetric[]>([]);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [preview_, setPreview] = useState<PreviewResult | null>(null);
    const [errors, setErrors] = useState<string[]>([]);
    const [aiNotice, setAiNotice] = useState<string | null>(null);
    // Multi-page: the page currently shown on the canvas + how many pages exist.
    const [currentPage, setCurrentPage] = useState(0);
    const [pageCount, setPageCount] = useState(1);
    // Per-report theme (accent + density).
    const [theme, setTheme] = useState<ReportTheme>({});
    // Collapsible side panels — let the canvas take (almost) the whole workspace.
    const [leftOpen, setLeftOpen] = useState(true);
    const [rightOpen, setRightOpen] = useState(true);
    // The block type being dragged from the palette onto the canvas (null = none).
    const [draggingType, setDraggingType] = useState<BlockType | null>(null);
    // Undo/redo history — snapshots of the blocks array.
    const [past, setPast] = useState<Block[][]>([]);
    const [future, setFuture] = useState<Block[][]>([]);

    /** Apply a structural change to the canvas, recording it for undo. */
    const commit = (next: Block[]): void => {
        setPast((stack) => [...stack, blocks]);
        setFuture([]);
        setBlocks(next);
    };

    /** Replace the canvas wholesale (load/AI/reset) and clear the undo history. */
    const resetBlocks = (next: Block[]): void => {
        const prepared = ensureLayouts(next);
        setPast([]);
        setFuture([]);
        setBlocks(prepared);
        setCurrentPage(0);
        setPageCount(
            Math.max(1, ...prepared.map((block) => (block.page ?? 0) + 1)),
        );
    };

    const undo = (): void => {
        if (past.length === 0) {
            return;
        }
        const previous = past[past.length - 1] as Block[];
        setPast(past.slice(0, -1));
        setFuture((stack) => [blocks, ...stack]);
        setBlocks(previous);
    };

    const redo = (): void => {
        if (future.length === 0) {
            return;
        }
        const next = future[0] as Block[];
        setFuture(future.slice(1));
        setPast((stack) => [...stack, blocks]);
        setBlocks(next);
    };

    useEffect(() => {
        if (editingTemplateId === null) {
            setName("");
            resetBlocks([makeBlock("header")]);
            setCalcMetrics([]);
            setTheme({});
            setSelectedId(null);
            setErrors([]);
        }
    }, [editingTemplateId]);

    useEffect(() => {
        if (
            editingTemplate !== undefined &&
            editingTemplate.id === editingTemplateId
        ) {
            const loaded = editingTemplate.blocks as Block[];
            setName(editingTemplate.name);
            resetBlocks(loaded.length > 0 ? loaded : [makeBlock("header")]);
            setCalcMetrics(editingTemplate.calculated_metrics ?? []);
            setTheme(editingTemplate.theme ?? {});
            setSelectedId(null);
            setErrors([]);
        }
    }, [editingTemplate, editingTemplateId]);

    // Keyboard shortcuts: Cmd/Ctrl+Z undo, Cmd/Ctrl+Shift+Z (or Ctrl+Y) redo.
    useEffect(() => {
        const onKey = (event: KeyboardEvent): void => {
            if (!(event.metaKey || event.ctrlKey)) {
                return;
            }
            const key = event.key.toLowerCase();
            if (key === "z" && !event.shiftKey) {
                event.preventDefault();
                undo();
            } else if ((key === "z" && event.shiftKey) || key === "y") {
                event.preventDefault();
                redo();
            }
        };
        window.addEventListener("keydown", onKey);

        return () => window.removeEventListener("keydown", onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [past, future, blocks]);

    const runPreview = preview.mutate;
    useEffect(() => {
        if (siteId === null) {
            setPreview(null);

            return;
        }

        const timer = setTimeout(() => {
            runPreview(
                {
                    blocks,
                    calculated_metrics: calcMetrics,
                    ...monthPeriod(month),
                },
                { onSuccess: (result) => setPreview(result) },
            );
        }, 400);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [siteId, blocks, calcMetrics, month]);

    const loadDefaultTemplate = (): void => {
        defaultTpl.mutate(undefined, {
            onSuccess: (loaded) => {
                resetBlocks(loaded.length > 0 ? loaded : [makeBlock("header")]);
                setSelectedId(null);
                setErrors([]);
            },
        });
    };

    const generateWithAi = (): void => {
        ai.mutate(aiPrompt, {
            onSuccess: (result) => {
                resetBlocks(
                    result.blocks.length > 0
                        ? result.blocks
                        : [makeBlock("header")],
                );
                setSelectedId(null);
                setErrors([]);
                // Tell the user which blocks the AI proposed but were dropped because the
                // site has no data for them (so the layout shrank for a reason).
                setAiNotice(
                    result.dropped.length > 0
                        ? `La IA propuso ${result.dropped.length} bloque(s) con métricas que este sitio no tiene y se omitieron: ${result.dropped
                              .map((block) => block.metric || block.type)
                              .join(", ")}.`
                        : null,
                );
            },
            onError: () => {
                setAiNotice(null);
                setErrors(["La IA no pudo generar un borrador válido."]);
            },
        });
    };

    const loadTemplate = (build: () => Block[]): void => {
        if (
            blocks.length > 1 &&
            !window.confirm("Esto reemplazará el lienzo actual. ¿Continuar?")
        ) {
            return;
        }
        const next = build();
        resetBlocks(next);
        setSelectedId(next[0]?.id ?? null);
    };

    const addBlock = (type: BlockType): void => {
        const block = { ...makeBlock(type), page: currentPage };
        commit([...blocks, block]);
        setSelectedId(block.id);
    };

    /** Drop a palette tile onto the grid at the released position (drag-to-canvas). */
    const dropBlock = (_layout: Layout[], item: Layout): void => {
        if (draggingType === null) {
            return;
        }
        const size = defaultSize(draggingType);
        const block: Block = {
            ...makeBlock(draggingType),
            page: currentPage,
            layout: { x: item.x, y: item.y, w: size.w, h: size.h },
        };
        commit([...blocks, block]);
        setSelectedId(block.id);
        setDraggingType(null);
    };

    const addPage = (): void => {
        setPageCount((count) => count + 1);
        setCurrentPage(pageCount);
        setSelectedId(null);
    };

    /** Delete a page: drop its blocks and renumber the pages after it. */
    const removePage = (page: number): void => {
        if (pageCount <= 1) {
            return;
        }
        commit(
            blocks
                .filter((block) => (block.page ?? 0) !== page)
                .map((block) =>
                    (block.page ?? 0) > page
                        ? { ...block, page: (block.page ?? 0) - 1 }
                        : block,
                ),
        );
        setPageCount((count) => Math.max(1, count - 1));
        setCurrentPage((current) =>
            current >= page && current > 0 ? current - 1 : current,
        );
        setSelectedId(null);
    };
    const updateBlock = (next: Block): void =>
        commit(blocks.map((b) => (b.id === next.id ? next : b)));
    const removeBlock = (id: string): void => {
        commit(blocks.filter((b) => b.id !== id));
        setSelectedId((current) => (current === id ? null : current));
    };
    const duplicateBlock = (id: string): void => {
        const index = blocks.findIndex((b) => b.id === id);
        const source = blocks[index];
        if (source === undefined) {
            return;
        }
        const clone: Block = {
            ...source,
            id: `b_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 6)}`,
            binding: source.binding ? { ...source.binding } : source.binding,
            props: { ...source.props },
            style: { ...source.style },
            // Drop the copy at the bottom of the grid so it doesn't overlap the original.
            layout:
                source.layout != null
                    ? { ...source.layout, y: 9999 }
                    : source.layout,
        };
        commit([
            ...blocks.slice(0, index + 1),
            clone,
            ...blocks.slice(index + 1),
        ]);
        setSelectedId(clone.id);
    };

    /**
     * Sync grid coordinates from react-grid-layout back into the blocks. Called on every
     * layout change (drag, resize, and the initial compaction that normalises new tiles
     * dropped at y:9999). Updates coords WITHOUT touching the undo history to avoid spam,
     * and bails when nothing actually changed so it can't loop.
     */
    const syncLayouts = (next: Layout[]): void => {
        const byId = new Map(next.map((item) => [item.i, item]));
        setBlocks((prev) => {
            let changed = false;
            const updated = prev.map((block) => {
                const item = byId.get(block.id);
                if (item === undefined) {
                    return block;
                }
                const current = block.layout;
                if (
                    current != null &&
                    current.x === item.x &&
                    current.y === item.y &&
                    current.w === item.w &&
                    current.h === item.h
                ) {
                    return block;
                }
                changed = true;
                return {
                    ...block,
                    layout: { x: item.x, y: item.y, w: item.w, h: item.h },
                };
            });
            return changed ? updated : prev;
        });
    };

    const save = (): void => {
        const handlers = {
            onSuccess: () => setErrors([]),
            onError: (error: unknown) => setErrors(extractBlockErrors(error)),
        };

        // Only send a theme when something is set, so an unstyled template stays null.
        const themePayload =
            theme.accent != null || theme.density != null ? theme : null;
        const payload = {
            name,
            blocks,
            calculated_metrics: calcMetrics,
            theme: themePayload,
        };
        if (editingTemplateId !== null) {
            update.mutate(payload, handlers);
        } else {
            create.mutate(payload, handlers);
        }
    };

    const addCalc = (): void =>
        setCalcMetrics((prev) => [
            ...prev,
            { key: `m${prev.length + 1}`, label: "", formula: "" },
        ]);
    const updateCalc = (index: number, patch: Partial<CalcMetric>): void =>
        setCalcMetrics((prev) =>
            prev.map((metric, i) =>
                i === index ? { ...metric, ...patch } : metric,
            ),
        );
    const removeCalc = (index: number): void =>
        setCalcMetrics((prev) => prev.filter((_, i) => i !== index));

    // Calculated metrics appear in the binding picker as a "calc" source.
    const fullCatalog: CatalogEntry[] = [
        ...catalog,
        ...calcMetrics
            .filter((metric) => metric.key !== "")
            .map((metric) => ({
                source: "calc",
                metric: metric.key,
                key: `calc.${metric.key}`,
                label: metric.label !== "" ? metric.label : metric.key,
                type: "number",
                unit: null,
                dimensions: [],
            })),
    ];

    const triggerSync = (): void => {
        if (siteId === null) {
            return;
        }
        syncSite.mutate(monthPeriod(month), {
            onSuccess: () => {
                const delays = [2500, 3000, 4000, 5000, 6000];
                delays.forEach((_, index) => {
                    const elapsed = delays
                        .slice(0, index + 1)
                        .reduce((sum, value) => sum + value, 0);
                    setTimeout(() => {
                        runPreview(
                            {
                                blocks,
                                calculated_metrics: calcMetrics,
                                ...monthPeriod(month),
                            },
                            {
                                onSuccess: (result) =>
                                    setPreview((current) =>
                                        current?.has_data === true
                                            ? current
                                            : result,
                                    ),
                            },
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
    const siteCurrency =
        sites.find((site) => site.id === siteId)?.currency ?? "USD";
    // Only the current page's blocks are shown/edited on the canvas (multi-page).
    const pageBlocks = blocks.filter(
        (block) => (block.page ?? 0) === currentPage,
    );
    // Apply the report accent to the canvas as a scoped CSS var (matches portal/PDF).
    const accentHsl =
        theme.accent != null ? hexToHslString(theme.accent) : null;
    const canvasThemeStyle: CSSProperties | undefined =
        accentHsl !== null
            ? ({
                  "--ir-primary": accentHsl,
                  "--ir-ring": accentHsl,
              } as CSSProperties)
            : undefined;

    return (
        <div className="ir-flex ir-h-full ir-min-h-0 ir-flex-col ir-bg-background">
            {/* ---- Top toolbar (full width) ---- */}
            <header className="ir-flex ir-items-center ir-gap-2 ir-border-b ir-bg-card ir-px-3 ir-py-2">
                <ToolbarButton
                    icon={leftOpen ? <PanelLeftClose className="ir-size-4" /> : <PanelLeftOpen className="ir-size-4" />}
                    title={leftOpen ? "Ocultar panel" : "Mostrar panel"}
                    onClick={() => setLeftOpen((open) => !open)}
                    active={leftOpen}
                />

                {/* Template name as an inline document-style title. */}
                <input
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    placeholder="Plantilla sin título"
                    className="ir-w-52 ir-rounded-md ir-border ir-border-transparent ir-bg-transparent ir-px-2 ir-py-1 ir-text-sm ir-font-semibold ir-text-foreground ir-transition placeholder:ir-font-normal placeholder:ir-text-muted-foreground hover:ir-border-border focus:ir-border-border focus:ir-bg-background focus:ir-outline-none"
                />
                <span className="ir-rounded-full ir-bg-muted ir-px-2 ir-py-0.5 ir-text-[11px] ir-font-medium ir-text-muted-foreground">
                    {editingTemplateId !== null ? "Editando" : "Borrador"}
                </span>
                {editingTemplateId !== null && (
                    <button
                        type="button"
                        onClick={() => editTemplate(null)}
                        className="ir-text-xs ir-text-muted-foreground hover:ir-text-foreground"
                    >
                        + Nueva
                    </button>
                )}

                <div className="ir-ml-auto ir-flex ir-items-center ir-gap-2">
                    {/* Compact preview-data control — site + period live here (preview only),
                        not as a giant panel widget. */}
                    <div className="ir-flex ir-h-8 ir-items-center ir-rounded-lg ir-border ir-bg-background ir-pl-2 ir-text-sm">
                        <Globe className="ir-size-4 ir-shrink-0 ir-text-muted-foreground" />
                        <select
                            value={siteId ?? ""}
                            onChange={(event) =>
                                setSiteId(event.target.value === "" ? null : Number(event.target.value))
                            }
                            title="Sitio para la vista previa (los datos reales)"
                            className="ir-max-w-[10rem] ir-cursor-pointer ir-truncate ir-border-0 ir-bg-transparent ir-py-1 ir-pl-1.5 ir-pr-1 ir-text-sm focus:ir-outline-none"
                        >
                            <option value="">Datos de ejemplo</option>
                            {sites.map((site) => (
                                <option key={site.id} value={site.id}>
                                    {site.name}
                                </option>
                            ))}
                        </select>
                        <span className="ir-h-5 ir-w-px ir-bg-border" />
                        <Calendar className="ir-ml-1.5 ir-size-4 ir-shrink-0 ir-text-muted-foreground" />
                        <input
                            type="month"
                            value={month}
                            onChange={(event) => setMonth(event.target.value)}
                            title="Periodo de la vista previa"
                            className="ir-w-[8.5rem] ir-border-0 ir-bg-transparent ir-py-1 ir-pl-1 ir-pr-2 ir-text-sm focus:ir-outline-none"
                        />
                    </div>

                    <ToolbarDivider />

                    <ToolbarButton icon={<Undo2 className="ir-size-4" />} title="Deshacer (Ctrl+Z)" onClick={undo} disabled={past.length === 0} />
                    <ToolbarButton icon={<Redo2 className="ir-size-4" />} title="Rehacer (Ctrl+Shift+Z)" onClick={redo} disabled={future.length === 0} />

                    <ToolbarDivider />

                    <Button variant="ghost" onClick={triggerSync} disabled={siteId === null || syncSite.isPending}>
                        <RefreshCw className={syncSite.isPending ? "ir-size-4 ir-animate-spin" : "ir-size-4"} />
                        Sincronizar
                    </Button>
                    <Button onClick={save} disabled={create.isPending || update.isPending || name === ""}>
                        <Save className="ir-size-4" />
                        {editingTemplateId !== null ? "Actualizar" : "Guardar"}
                    </Button>

                    <ToolbarButton
                        icon={rightOpen ? <PanelRightClose className="ir-size-4" /> : <PanelRightOpen className="ir-size-4" />}
                        title={rightOpen ? "Ocultar inspector" : "Mostrar inspector"}
                        onClick={() => setRightOpen((open) => !open)}
                        active={rightOpen}
                    />
                </div>
            </header>

            {/* ---- Body: left panel · canvas · inspector ---- */}
            <div className="ir-flex ir-min-h-0 ir-flex-1">
                {/* ---- Left panel (collapsible): config + blocks ---- */}
                {leftOpen && (
                    <aside className="ir-flex ir-w-64 ir-shrink-0 ir-flex-col ir-overflow-y-auto ir-border-r ir-bg-card">
                        <Section title="Insertar bloque" icon={<Shapes className="ir-size-4" />}>
                            <BlockPalette onAdd={addBlock} onDragType={setDraggingType} />
                        </Section>

                        <Section title="Plantillas e IA" icon={<LayoutTemplate className="ir-size-4" />}>
                            <div className="ir-flex ir-flex-col ir-gap-2.5">
                                <div className="ir-flex ir-gap-2">
                                    <Input
                                        placeholder="Enfoque para la IA…"
                                        value={aiPrompt}
                                        onChange={(event) => setAiPrompt(event.target.value)}
                                    />
                                    <Button variant="ghost" onClick={generateWithAi} disabled={siteId === null || ai.isPending}>
                                        <Sparkles className="ir-size-4" />
                                        IA
                                    </Button>
                                </div>
                                {siteId === null && (
                                    <p className="ir-text-[11px] ir-text-muted-foreground">Elige un sitio en la barra superior para generar con IA.</p>
                                )}
                                <Button variant="ghost" onClick={loadDefaultTemplate} disabled={defaultTpl.isPending}>
                                    <LayoutTemplate className="ir-size-4" />
                                    Plantilla por defecto
                                </Button>
                                <div className="ir-flex ir-flex-col ir-gap-1.5">
                                    {GALLERY.map((template) => (
                                        <button
                                            key={template.key}
                                            type="button"
                                            onClick={() => loadTemplate(template.build)}
                                            className="ir-rounded-md ir-border ir-bg-background ir-p-2 ir-text-left ir-transition hover:ir-border-primary hover:ir-bg-primary/5"
                                        >
                                            <span className="ir-block ir-text-sm ir-font-medium">{template.name}</span>
                                            <span className="ir-block ir-text-xs ir-text-muted-foreground">{template.description}</span>
                                        </button>
                                    ))}
                                </div>
                                {(create.isSuccess || update.isSuccess) && (
                                    <p className="ir-text-xs ir-text-emerald-600">Guardada.</p>
                                )}
                            </div>
                        </Section>

                        <Section title="Métricas calculadas" icon={<FunctionSquare className="ir-size-4" />} defaultOpen={false}>
                            <div className="ir-flex ir-flex-col ir-gap-3">
                                {calcMetrics.map((metric, index) => (
                                    <div key={index} className="ir-flex ir-flex-col ir-gap-1 ir-rounded-md ir-border ir-bg-background ir-p-2">
                                        <div className="ir-flex ir-gap-1">
                                            <Input placeholder="clave" value={metric.key} onChange={(event) => updateCalc(index, { key: event.target.value })} />
                                            <button type="button" className="ir-px-2 ir-text-muted-foreground hover:ir-text-red-500" onClick={() => removeCalc(index)}>
                                                ×
                                            </button>
                                        </div>
                                        <Input placeholder="Etiqueta" value={metric.label} onChange={(event) => updateCalc(index, { label: event.target.value })} />
                                        <Input placeholder="ga4.sessions / woocommerce.orders" value={metric.formula} onChange={(event) => updateCalc(index, { formula: event.target.value })} />
                                    </div>
                                ))}
                                <Button variant="ghost" onClick={addCalc}>
                                    + Añadir métrica
                                </Button>
                                <p className="ir-text-[11px] ir-text-muted-foreground">
                                    Usa claves de métricas (p. ej. <code>ga4.sessions</code>) y <code>+ - * / ( )</code>. Aparecen como fuente «calc» al vincular un bloque.
                                </p>
                            </div>
                        </Section>

                        <Section title="Tema del reporte" icon={<Palette className="ir-size-4" />} defaultOpen={false}>
                            <div className="ir-flex ir-flex-col ir-gap-3">
                                <Field label="Color de acento">
                                    <div className="ir-flex ir-items-center ir-gap-2">
                                        <input
                                            type="color"
                                            value={theme.accent ?? "#6366f1"}
                                            onChange={(event) => setTheme((current) => ({ ...current, accent: event.target.value }))}
                                            className="ir-h-8 ir-w-10 ir-cursor-pointer ir-rounded ir-border"
                                        />
                                        {theme.accent != null && (
                                            <button
                                                type="button"
                                                className="ir-text-xs ir-text-muted-foreground hover:ir-text-foreground"
                                                onClick={() => setTheme((current) => ({ ...current, accent: null }))}
                                            >
                                                usar marca de agencia
                                            </button>
                                        )}
                                    </div>
                                </Field>
                                <Field label="Densidad">
                                    <SegmentedControl
                                        value={theme.density ?? "normal"}
                                        onChange={(value) => setTheme((current) => ({ ...current, density: value }))}
                                        options={[
                                            { value: "normal", label: "Normal" },
                                            { value: "compact", label: "Compacta" },
                                        ]}
                                    />
                                </Field>
                            </div>
                        </Section>
                    </aside>
                )}

                {/* ---- Center: the WYSIWYG canvas (a centered artboard on a gray workspace) ---- */}
                <div className="ir-flex ir-min-w-0 ir-flex-1 ir-flex-col ir-bg-muted/40">
                    {/* Page navigator + preview-data status */}
                    <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-x-4 ir-gap-y-2 ir-border-b ir-bg-background/70 ir-px-4 ir-py-2">
                        <div className="ir-flex ir-items-center ir-gap-1">
                            {Array.from({ length: pageCount }, (_, index) => (
                                <div
                                    key={index}
                                    className="ir-group ir-relative"
                                >
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setCurrentPage(index);
                                            setSelectedId(null);
                                        }}
                                        className={
                                            index === currentPage
                                                ? "ir-rounded-md ir-border ir-border-primary ir-bg-primary/5 ir-px-3 ir-py-1 ir-text-sm ir-font-medium"
                                                : "ir-rounded-md ir-border ir-px-3 ir-py-1 ir-text-sm ir-text-muted-foreground hover:ir-border-primary/60"
                                        }
                                    >
                                        Página {index + 1}
                                    </button>
                                    {pageCount > 1 && (
                                        <button
                                            type="button"
                                            title="Eliminar página"
                                            onClick={() => removePage(index)}
                                            className="ir-absolute -ir-right-1 -ir-top-1 ir-hidden ir-size-4 ir-items-center ir-justify-center ir-rounded-full ir-bg-muted ir-text-xs ir-text-muted-foreground group-hover:ir-flex hover:ir-text-red-500"
                                        >
                                            ×
                                        </button>
                                    )}
                                </div>
                            ))}
                            <Button
                                variant="ghost"
                                onClick={addPage}
                                title="Añadir página"
                            >
                                <Plus className="ir-size-4" />
                            </Button>
                        </div>

                        <div className="ir-text-xs">
                            {siteId === null ? (
                                <span className="ir-text-amber-600">
                                    Datos de ejemplo · elige un sitio para datos
                                    reales.
                                </span>
                            ) : hasRealData && !preview_.has_data ? (
                                <span className="ir-text-amber-600">
                                    Sin datos para este periodo · usa
                                    «Sincronizar».
                                </span>
                            ) : hasRealData ? (
                                <span className="ir-text-emerald-600">
                                    Datos reales ·{" "}
                                    {preview_.sources_with_data.length}{" "}
                                    fuente(s).
                                </span>
                            ) : (
                                <span className="ir-text-muted-foreground">
                                    Cargando datos…
                                </span>
                            )}
                        </div>
                    </div>

                    {errors.length > 0 && (
                        <div className="ir-border-b ir-border-red-200 ir-bg-red-50 ir-px-4 ir-py-2">
                            {errors.map((error) => (
                                <p
                                    key={error}
                                    className="ir-text-xs ir-text-red-600"
                                >
                                    {error}
                                </p>
                            ))}
                        </div>
                    )}

                    {aiNotice !== null && (
                        <div className="ir-flex ir-items-start ir-justify-between ir-gap-3 ir-border-b ir-border-amber-200 ir-bg-amber-50 ir-px-4 ir-py-2">
                            <p className="ir-text-xs ir-text-amber-700">{aiNotice}</p>
                            <button
                                type="button"
                                className="ir-shrink-0 ir-text-xs ir-text-amber-700 hover:ir-underline"
                                onClick={() => setAiNotice(null)}
                            >
                                Cerrar
                            </button>
                        </div>
                    )}

                    {/* Scrollable workspace with the centered artboard */}
                    <div className="ir-min-h-0 ir-flex-1 ir-overflow-auto ir-p-6 lg:ir-p-10">
                        <div
                            className="ir-mx-auto ir-w-full ir-max-w-5xl ir-rounded-xl ir-border ir-bg-card ir-p-6 ir-shadow-sm"
                            style={canvasThemeStyle}
                        >
                            <ReportSettingsProvider
                                currency={siteCurrency}
                                density={
                                    theme.density === "compact"
                                        ? "compact"
                                        : "normal"
                                }
                            >
                                <Grid
                                    key={currentPage}
                                    cols={GRID_COLS}
                                    rowHeight={GRID_ROW_HEIGHT}
                                    margin={[GRID_MARGIN, GRID_MARGIN]}
                                    containerPadding={[0, 0]}
                                    layout={pageBlocks.map((block) => ({
                                        i: block.id,
                                        x: block.layout?.x ?? 0,
                                        y: block.layout?.y ?? 0,
                                        w: block.layout?.w ?? 6,
                                        h: block.layout?.h ?? 4,
                                        minW: 2,
                                        minH: 1,
                                    }))}
                                    draggableHandle=".ir-drag-handle"
                                    resizeHandles={["se"]}
                                    compactType="vertical"
                                    onLayoutChange={syncLayouts}
                                    isDroppable={draggingType !== null}
                                    onDrop={dropBlock}
                                    onDropDragOver={() =>
                                        draggingType !== null
                                            ? defaultSize(draggingType)
                                            : false
                                    }
                                >
                                    {pageBlocks.map((block) => (
                                        <div
                                            key={block.id}
                                            className="ir-h-full"
                                        >
                                            <CanvasBlock
                                                block={block}
                                                data={renderData[block.id]}
                                                selected={
                                                    block.id === selectedId
                                                }
                                                onSelect={() =>
                                                    setSelectedId(block.id)
                                                }
                                                onRemove={() =>
                                                    removeBlock(block.id)
                                                }
                                                onDuplicate={() =>
                                                    duplicateBlock(block.id)
                                                }
                                            />
                                        </div>
                                    ))}
                                </Grid>
                            </ReportSettingsProvider>
                            {pageBlocks.length === 0 && (
                                <p className="ir-py-12 ir-text-center ir-text-sm ir-text-muted-foreground">
                                    Página vacía. Añade bloques desde la
                                    izquierda.
                                </p>
                            )}
                        </div>
                    </div>
                </div>

                {/* ---- Right panel (collapsible): inspector for the selected block ---- */}
                {rightOpen && (
                    <aside className="ir-w-72 ir-shrink-0 ir-overflow-y-auto ir-border-l ir-bg-card ir-p-3">
                        <Card title="Bloque">
                            <Inspector
                                block={selectedBlock}
                                catalog={fullCatalog}
                                onChange={updateBlock}
                            />
                        </Card>
                    </aside>
                )}
            </div>
        </div>
    );
}
