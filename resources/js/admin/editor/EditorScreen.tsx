import {
    Activity,
    BookOpen,
    Bug,
    Calendar,
    Clock,
    Copy,
    Filter,
    Flag,
    FunctionSquare,
    Globe,
    Layers,
    LayoutTemplate,
    PanelLeftClose,
    PanelLeftOpen,
    PanelRightClose,
    Palette,
    Plus,
    Redo2,
    Save,
    Search,
    Shapes,
    ShieldAlert,
    ShieldCheck,
    ShoppingCart,
    Sparkles,
    Trash2,
    TrendingUp,
    Undo2,
    Wrench,
    Zap,
    Minus,
    MoreHorizontal,
    SlidersHorizontal,
    X,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import {
    type CSSProperties,
    type ReactElement,
    useCallback,
    useEffect,
    useRef,
    useState,
} from "react";
import GridLayout, { type Layout, WidthProvider } from "react-grid-layout";

/** The artboard's natural width in px (matches the report's intended layout). */
const ARTBOARD_WIDTH = 1024;

/**
 * Horizontal space the workspace consumes around the artboard: its padding plus the
 * scrollbar gutter it always reserves (`scrollbar-gutter: stable`). A fixed reserve —
 * generous enough for any platform's scrollbar — is deliberate: deriving it from a live
 * measurement is what let the fit scale feed back into itself (see the fit-scale effect).
 * Phones get tighter padding (`p-3`), where 48px of margin is a fifth of the screen.
 */
function workspaceInset(compact: boolean): number {
    return (compact ? 24 : 48) + 20;
}

/**
 * A sheet that slides up from the bottom edge — the phone equivalent of a side panel.
 *
 * Bottom, not top or side, because that's where the thumb is; the editor's controls are
 * used one-handed on a phone and a top-anchored dialog puts every target out of reach.
 */
function BottomSheet({
    title,
    onClose,
    children,
}: {
    title: string;
    onClose: () => void;
    children: ReactElement | ReactElement[];
}): ReactElement {
    return (
        <div className="ir-fixed ir-inset-0 ir-z-50 ir-flex ir-flex-col ir-justify-end">
            <button
                type="button"
                aria-label="Cerrar"
                onClick={onClose}
                className="ir-absolute ir-inset-0 ir-cursor-default ir-bg-black/40"
            />
            <div className="ir-relative ir-flex ir-max-h-[85vh] ir-flex-col ir-rounded-t-2xl ir-border-t ir-bg-card ir-shadow-ir-lg">
                <div className="ir-mx-auto ir-mt-2 ir-h-1 ir-w-10 ir-shrink-0 ir-rounded-full ir-bg-border" />
                <div className="ir-flex ir-shrink-0 ir-items-center ir-justify-between ir-gap-2 ir-px-4 ir-py-2.5">
                    <h2 className="ir-text-sm ir-font-semibold ir-tracking-tight">{title}</h2>
                    <button
                        type="button"
                        aria-label="Cerrar"
                        onClick={onClose}
                        className="ir-rounded-md ir-p-1.5 ir-text-muted-foreground ir-transition hover:ir-bg-muted hover:ir-text-foreground"
                    >
                        <X className="ir-size-4" />
                    </button>
                </div>
                <div
                    className="ir-min-h-0 ir-flex-1 ir-overflow-y-auto ir-px-4 ir-pt-1"
                    style={{ paddingBottom: "calc(1rem + env(safe-area-inset-bottom))" }}
                >
                    {children}
                </div>
            </div>
        </div>
    );
}

/** A full-width tap target for the overflow sheet — phone rows, not toolbar chips. */
function SheetAction({
    icon,
    label,
    hint,
    onClick,
    disabled = false,
    badge,
}: {
    icon: ReactElement;
    label: string;
    hint?: string;
    onClick: () => void;
    disabled?: boolean;
    badge?: number;
}): ReactElement {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className="ir-flex ir-w-full ir-items-center ir-gap-3 ir-rounded-lg ir-px-2 ir-py-3 ir-text-left ir-transition hover:ir-bg-muted disabled:ir-opacity-40"
        >
            <span className="ir-flex ir-size-9 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-lg ir-bg-muted ir-text-muted-foreground">
                {icon}
            </span>
            <span className="ir-min-w-0 ir-flex-1">
                <span className="ir-block ir-truncate ir-text-sm ir-font-medium">{label}</span>
                {hint !== undefined && (
                    <span className="ir-block ir-truncate ir-text-[11px] ir-text-muted-foreground">
                        {hint}
                    </span>
                )}
            </span>
            {badge !== undefined && badge > 0 && (
                <span className="ir-shrink-0 ir-rounded-full ir-bg-primary/15 ir-px-2 ir-py-0.5 ir-text-[11px] ir-font-medium ir-text-primary">
                    {badge}
                </span>
            )}
        </button>
    );
}

/** Left-panel sections, in panel order — also what the collapsed icon rail offers. */
const LEFT_SECTIONS: { key: "blocks" | "layers"; title: string; icon: LucideIcon }[] = [
    { key: "blocks", title: "Insertar bloque", icon: Shapes },
    { key: "layers", title: "Capas", icon: Layers },
];
import "react-grid-layout/css/styles.css";
import "react-resizable/css/styles.css";

import {
    ReportPageNav,
    ReportSettingsProvider,
} from "@shared/blocks/BlockRenderer";
import { GRID_COLS, GRID_MARGIN, GRID_ROW_HEIGHT } from "@shared/blocks/types";
import type { Block, BlockType } from "@shared/blocks/types";

import {
    type PreviewResult,
    useAgency,
    useAiSection,
    useAiTemplate,
    useCreateReportTemplate,
    useDefaultTemplateBlocks,
    useMetricCatalog,
    usePreview,
    useReportTemplate,
    useSites,
    useUpdateReportTemplate,
} from "../api";
import { hexToHslString } from "@shared/lib/color";
import { apiErrorMessage } from "@shared/lib/api";
import { SyncStatus } from "./SyncStatus";

import { Button, Card, Field, Input, Modal } from "../components/ui";
import { COMPACT_QUERY, useMediaQuery } from "../hooks/useMediaQuery";
import type { CatalogEntry, PageFilters, ReportTheme } from "../types";
import { useAdminUi } from "../store";
import { CanvasBlock } from "./CanvasBlock";
import {
    defaultSize,
    ensureLayouts,
    makeBlock,
    sampleData,
} from "./blockFactory";
import { BlockPalette, BLOCK_META } from "./BlockPalette";
import { PageFiltersPanel } from "./PageFiltersPanel";
import { CalcMetricsModal } from "../components/CalcMetricsModal";
import { GALLERY } from "./templateGallery";
import { Inspector } from "./Inspector";
import {
    ColorSwatch,
    Section,
    SegmentedControl,
    Toggle,
    ToolbarButton,
    ToolbarDivider,
} from "./controls";
import { cn } from "@shared/lib/utils";

/** A small icon per gallery template (keeps templateGallery.ts pure data). */
const GALLERY_ICONS: Record<string, LucideIcon> = {
    woocommerce: ShoppingCart,
    ecommerce: ShoppingCart,
    ga4_web: Globe,
    ga4_ecommerce: TrendingUp,
    seo: Search,
    trueranker: TrendingUp,
    hourly_support: Clock,
    security: ShieldCheck,
    cloudflare: Zap,
    uptime: Activity,
    crowdsec: ShieldAlert,
    maintenance: Wrench,
    virusdie: Bug,
    unified: Layers,
};

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
    const aiSection = useAiSection(siteId ?? 0);

    const editingTemplateId = useAdminUi((state) => state.editingTemplateId);
    const editTemplate = useAdminUi((state) => state.editTemplate);
    const { data: editingTemplate } = useReportTemplate(editingTemplateId);
    const update = useUpdateReportTemplate(editingTemplateId ?? 0);

    const preview = usePreview(siteId ?? 0);

    // Calculated metrics live in their own modal (CLAUDE.md §10.1): agency-level (reusable)
    // + site-level, edited there and merged server-side. The editor just opens the modal.
    const { data: agency } = useAgency();
    const [calcModalOpen, setCalcModalOpen] = useState(false);

    const [name, setName] = useState("");
    const [aiPrompt, setAiPrompt] = useState("");
    const [aiSectionPrompt, setAiSectionPrompt] = useState("");
    const [month, setMonth] = useState(currentMonth());
    const [blocks, setBlocks] = useState<Block[]>([makeBlock("header")]);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [preview_, setPreview] = useState<PreviewResult | null>(null);
    const [errors, setErrors] = useState<string[]>([]);
    const [aiNotice, setAiNotice] = useState<string | null>(null);
    // Multi-page: the page currently shown on the canvas + how many pages exist.
    const [currentPage, setCurrentPage] = useState(0);
    const [pageCount, setPageCount] = useState(1);
    // Per-report theme (accent + density).
    const [theme, setTheme] = useState<ReportTheme>({});
    // Page/dashboard filters (design-time), keyed by scope (`all` or page index).
    const [pageFilters, setPageFilters] = useState<PageFilters>({});
    // Canvas zoom. 'fit' scales the artboard down to whatever width is left after the
    // panels — so on a laptop the report keeps its real proportions instead of being
    // squeezed into a narrow column and looking broken.
    const [zoom, setZoom] = useState<number | "fit">("fit");
    const [fitScale, setFitScale] = useState(1);
    const [artboardHeight, setArtboardHeight] = useState(0);
    const canvasColumnRef = useRef<HTMLDivElement>(null);
    const artboardRef = useRef<HTMLDivElement>(null);
    // Named pages (§11 — Looker/Power-BI parity): the label of each page in the nav menu,
    // indexed by page. Empty string falls back to "Página N". Editing index for inline rename.
    const [pageNames, setPageNames] = useState<string[]>([]);
    const [renamingPage, setRenamingPage] = useState<number | null>(null);
    // Collapsible side panels — let the canvas take (almost) the whole workspace.
    // Panels start open on desktop, closed on phones (they overlay the canvas there).
    const wideViewport =
        typeof window !== "undefined" && window.innerWidth >= 1024;
    const [leftOpen, setLeftOpen] = useState(wideViewport);
    // Which left-panel tab is showing. The collapsed icon rail selects it directly.
    const [leftTab, setLeftTab] = useState<"blocks" | "layers">("blocks");
    const [aiOpen, setAiOpen] = useState(false);
    // Starts closed: nothing is selected yet, so the inspector would only be showing
    // "select a block" while eating 288px of canvas.
    const [rightOpen, setRightOpen] = useState(false);
    // Which right-panel tab is showing: the selected block's settings, or the report's own.
    const [rightTab, setRightTab] = useState<"block" | "report">("report");

    // Phone/tablet layout. A wrapping desktop toolbar stacked seven rows of chrome above
    // the canvas — more than the whole viewport — so below `lg` the bar collapses to one
    // row and everything secondary moves into this overflow sheet.
    const isCompact = useMediaQuery(COMPACT_QUERY);
    const [moreOpen, setMoreOpen] = useState(false);
    // The block type being dragged from the palette onto the canvas (null = none).
    const [draggingType, setDraggingType] = useState<BlockType | null>(null);
    // Undo/redo history — snapshots of the blocks array.
    const [past, setPast] = useState<Block[][]>([]);
    const [future, setFuture] = useState<Block[][]>([]);
    // Unsaved-work flag (FE-1): set by any canvas edit, cleared on load/save. Drives the
    // beforeunload guard so a reload/close doesn't silently discard an unsaved layout.
    const [dirty, setDirty] = useState(false);

    /** Apply a structural change to the canvas, recording it for undo. */
    const commit = (next: Block[]): void => {
        setPast((stack) => [...stack, blocks]);
        setFuture([]);
        setBlocks(next);
        setDirty(true);
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
            setTheme({});
            setPageFilters({});
            setPageNames([]);
            setSelectedId(null);
            setErrors([]);
            setDirty(false);
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
            setTheme(editingTemplate.theme ?? {});
            setPageFilters(editingTemplate.filters ?? {});
            setPageNames(
                (editingTemplate.pages ?? []).map((page) => page.name ?? ""),
            );
            setSelectedId(null);
            setErrors([]);
            setDirty(false);
        }
    }, [editingTemplate, editingTemplateId]);

    // Warn before a reload / tab close discards unsaved canvas work (FE-1).
    useEffect(() => {
        if (!dirty) {
            return;
        }

        const handler = (event: BeforeUnloadEvent): void => {
            event.preventDefault();
            event.returnValue = "";
        };
        window.addEventListener("beforeunload", handler);

        return () => window.removeEventListener("beforeunload", handler);
    }, [dirty]);

    // Keyboard shortcuts: Cmd/Ctrl+Z undo, Cmd/Ctrl+Shift+Z (or Ctrl+Y) redo.
    useEffect(() => {
        const onKey = (event: KeyboardEvent): void => {
            if (!(event.metaKey || event.ctrlKey)) {
                return;
            }
            // Don't hijack undo/redo while the user is typing in a text field or the Tiptap
            // rich-text editor — otherwise Ctrl+Z reverts the block structure instead of the
            // text they're editing (FE). Let the focused editor handle its own history.
            const target = event.target;
            if (
                target instanceof HTMLElement &&
                (target.isContentEditable || target.closest('input, textarea, [contenteditable="true"]') !== null)
            ) {
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
                    filters: pageFilters,
                    ...monthPeriod(month),
                },
                { onSuccess: (result) => setPreview(result) },
            );
        }, 400);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [siteId, blocks, month, pageFilters]);

    const loadDefaultTemplate = (): void => {
        defaultTpl.mutate(undefined, {
            onSuccess: (loaded) => {
                resetBlocks(loaded.length > 0 ? loaded : [makeBlock("header")]);
                setSelectedId(null);
                setErrors([]);
                setDirty(true);
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
                setDirty(true);
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

    // "Add a section with AI": builds only the described blocks and appends them below
    // the current page's content, leaving the rest of the layout untouched.
    const generateSectionWithAi = (): void => {
        if (aiSectionPrompt.trim() === "") {
            return;
        }
        aiSection.mutate(aiSectionPrompt, {
            onSuccess: (result) => {
                if (result.blocks.length === 0) {
                    setAiNotice(
                        "La IA no propuso bloques válidos para este sitio (puede que no tenga los datos que pediste).",
                    );
                    return;
                }
                appendTemplate(() => result.blocks);
                setAiSectionPrompt("");
                setErrors([]);
                setAiNotice(
                    result.dropped.length > 0
                        ? `Se añadió la sección. La IA omitió ${result.dropped.length} bloque(s) con métricas que este sitio no tiene: ${result.dropped
                              .map((block) => block.metric || block.type)
                              .join(", ")}.`
                        : null,
                );
            },
            onError: () => {
                setAiNotice(null);
                setErrors(["La IA no pudo generar la sección."]);
            },
        });
    };

    // Predesigned templates live in a top-bar modal (kept out of the sidebar to save space).
    const [galleryOpen, setGalleryOpen] = useState(false);

    // A gallery template the user picked while the canvas already has content — we ask
    // whether to append it below or replace everything (for building unified reports).
    const [pendingTpl, setPendingTpl] = useState<{
        build: () => Block[];
        name: string;
        pages?: string[];
    } | null>(null);

    const chooseTemplate = (template: {
        build: () => Block[];
        name: string;
        pages?: string[];
    }): void => {
        // An essentially empty canvas (just the starter header) just loads the template.
        if (blocks.length <= 1) {
            replaceWithTemplate(template);
            return;
        }
        setPendingTpl(template);
    };

    function replaceWithTemplate(template: {
        build: () => Block[];
        pages?: string[];
    }): void {
        const next = template.build();
        resetBlocks(next);
        // Carry the template's named pages (multi-page templates), or clear them.
        setPageNames(template.pages ?? []);
        setSelectedId(next[0]?.id ?? null);
        setPendingTpl(null);
    }

    function appendTemplate(build: () => Block[]): void {
        const incoming = ensureLayouts(build());
        if (incoming.length === 0) {
            setPendingTpl(null);
            return;
        }
        // Stack the template below whatever is already on the current page.
        const onPage = blocks.filter(
            (block) => (block.page ?? 0) === currentPage,
        );
        const offsetY = onPage.reduce(
            (max, block) =>
                Math.max(max, (block.layout?.y ?? 0) + (block.layout?.h ?? 4)),
            0,
        );
        const shifted = incoming.map((block) => ({
            ...block,
            page: currentPage,
            layout: block.layout
                ? { ...block.layout, y: (block.layout.y ?? 0) + offsetY }
                : block.layout,
        }));
        commit([...blocks, ...shifted]);
        setSelectedId(shifted[0]?.id ?? null);
        setPendingTpl(null);
    }

    const addBlock = (type: BlockType): void => {
        const block = { ...makeBlock(type), page: currentPage };
        commit([...blocks, block]);
        setSelectedId(block.id);
        // On a phone the palette is a sheet covering the canvas: leaving it up would hide
        // the block that was just added (and the inspector that opens for it).
        if (isCompact) {
            setLeftOpen(false);
        }
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
        setPageNames((names) => [...names, ""]);
        setSelectedId(null);
    };

    /** One-click "Portada": a NEW first page with a cover block, shifting everything down. */
    const addCoverPage = (): void => {
        const cover = { ...makeBlock("cover"), page: 0 };
        commit([
            cover,
            ...blocks.map((block) => ({
                ...block,
                page: (block.page ?? 0) + 1,
            })),
        ]);
        setPageCount((count) => count + 1);
        setPageNames((names) => ["Portada", ...names]);
        setCurrentPage(0);
        setSelectedId(cover.id);
    };

    /** One-click "Contraportada": a NEW last page with a back-cover block. */
    const addBackCoverPage = (): void => {
        const page = pageCount;
        const back = { ...makeBlock("back_cover"), page };
        commit([...blocks, back]);
        setPageCount((count) => count + 1);
        setPageNames((names) => {
            const next = [...names];
            while (next.length < page) {
                next.push("");
            }
            next.push("Contraportada");
            return next;
        });
        setCurrentPage(page);
        setSelectedId(back.id);
    };

    const hasCover = blocks.some((block) => block.type === "cover");
    const hasBackCover = blocks.some((block) => block.type === "back_cover");

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
        setPageNames((names) => names.filter((_, index) => index !== page));
        setCurrentPage((current) =>
            current >= page && current > 0 ? current - 1 : current,
        );
        setSelectedId(null);
    };

    /** Rename a page (the nav-menu label). Empty names fall back to "Página N". */
    const renamePage = (page: number, name: string): void =>
        setPageNames((names) => {
            const next = [...names];
            while (next.length <= page) {
                next.push("");
            }
            next[page] = name;
            return next;
        });
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
            onSuccess: () => {
                setErrors([]);
                setDirty(false);
            },
            onError: (error: unknown) => {
                // Block-binding errors list per block; anything else (422 name, 403, 500) would
                // otherwise be a silent failed click — fall back to the server's message.
                const blockErrors = extractBlockErrors(error);
                setErrors(blockErrors.length > 0 ? blockErrors : [apiErrorMessage(error, "No se pudo guardar. Revisa el nombre e inténtalo de nuevo.")]);
            },
        };

        // Only send a theme when something is set, so an unstyled template stays null.
        const themePayload =
            theme.accent != null || theme.density != null || theme.nav != null
                ? theme
                : null;
        // Only persist filters when some scope actually has rules.
        const filtersPayload =
            Object.keys(pageFilters).length > 0 ? pageFilters : null;
        // Named pages for the nav menu — one entry per page, in order.
        const pagesPayload = Array.from({ length: pageCount }, (_, index) => ({
            name: pageNames[index] ?? "",
        }));
        // Calculated metrics are agency-level now (saved from their modal), not on the template.
        const payload = {
            name,
            blocks,
            theme: themePayload,
            filters: filtersPayload,
            pages: pagesPayload,
        };
        if (editingTemplateId !== null) {
            update.mutate(payload, handlers);
        } else {
            create.mutate(payload, {
                onError: handlers.onError,
                onSuccess: (created) => {
                    setErrors([]);
                    setDirty(false);
                    // Switch into edit mode for the just-created template, so pressing Guardar
                    // again UPDATES it instead of creating a duplicate (the reported bug).
                    editTemplate(created.id);
                },
            });
        }
    };

    // Calculated metrics already arrive in the catalog as `calc.*` entries (the server
    // merges agency + site formulas into the metric catalog), so the binding picker shows
    // them without any client-side mapping.
    const fullCatalog: CatalogEntry[] = catalog;

    // Re-run the live preview against the freshly-synced snapshots. Driven by the
    // SyncStatus panel once it detects every source has finished.
    const refreshPreview = useCallback((): void => {
        if (siteId === null) {
            return;
        }
        runPreview(
            {
                blocks,
                filters: pageFilters,
                ...monthPeriod(month),
            },
            { onSuccess: (result) => setPreview(result) },
        );
    }, [siteId, blocks, month, pageFilters, runPreview]);

    const hasRealData = siteId !== null && preview_ !== null;

    /** Where the previewed numbers come from, in one word and in one sentence. */
    const previewStatus: { short: string; long: string; tone: string } =
        siteId === null
            ? {
                  short: "Ejemplo",
                  long: "Datos de ejemplo · elige un sitio para datos reales.",
                  tone: "ir-text-amber-600",
              }
            : hasRealData && !preview_.has_data
              ? {
                    short: "Sin datos",
                    long: "Sin datos para este periodo · usa «Sincronizar».",
                    tone: "ir-text-amber-600",
                }
              : hasRealData
                ? {
                      short: "Reales",
                      long: `Datos reales · ${preview_.sources_with_data.length} fuente(s).`,
                      tone: "ir-text-emerald-600",
                  }
                : preview.isError
                  ? {
                        short: "Error",
                        long: "No se pudieron cargar los datos del sitio.",
                        tone: "ir-text-danger",
                    }
                  : {
                        short: "Cargando",
                        long: "Cargando datos…",
                        tone: "ir-text-muted-foreground",
                    };

    const renderData: Record<string, unknown> = {};
    if (hasRealData) {
        // Real preview: show exactly what the site has. Empty blocks render an honest
        // "Sin datos" state in CanvasBlock — never sample data, which would contradict
        // the real KPI values (e.g. a populated table next to a "0 aplicadas" card).
        Object.assign(renderData, preview_.data);
    } else {
        // Template-design mode (no site / no preview): representative sample data so the
        // layout is meaningful while designing.
        for (const block of blocks) {
            renderData[block.id] = sampleData(block);
        }
    }

    // Keep the fit scale in step with the space actually available: toggling a panel or
    // resizing the window changes it, and the artboard must follow without a reload.
    //
    // Measured on the canvas COLUMN, never on the scrolling workspace inside it. Measuring
    // the scroller closed a feedback loop: a smaller scale made the artboard shorter, the
    // vertical scrollbar disappeared, `clientWidth` grew by its width, the scale grew, the
    // scrollbar came back — at the handful of viewport widths that sit right on that
    // threshold it never settled, and the canvas visibly vibrated. The column's width can't
    // be changed by anything the scale does, so the loop can't close.
    useEffect(() => {
        const node = canvasColumnRef.current;
        if (node === null) {
            return;
        }

        let frame = 0;
        const measure = (): void => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                const available = node.clientWidth - workspaceInset(isCompact);
                // Quantised to whole percent so sub-pixel width churn can't restyle anything.
                const next =
                    Math.round(Math.min(1, Math.max(0.35, available / ARTBOARD_WIDTH)) * 100) / 100;
                setFitScale((current) => (current === next ? current : next));
            });
        };

        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(node);

        return () => {
            cancelAnimationFrame(frame);
            observer.disconnect();
        };
    }, [isCompact]);

    // The artboard's natural height, so the scaled wrapper can reserve exactly the space
    // the scaled render occupies — transforms don't change layout height.
    useEffect(() => {
        const node = artboardRef.current;
        if (node === null) {
            return;
        }

        let frame = 0;
        const measure = (): void => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                const height = node.offsetHeight;
                // The wrapper's reserved height derives from this, so sub-pixel jitter here
                // would keep re-laying out the workspace for no visible gain.
                setArtboardHeight((current) =>
                    Math.abs(current - height) < 1 ? current : height,
                );
            });
        };

        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(node);

        return () => {
            cancelAnimationFrame(frame);
            observer.disconnect();
        };
    }, [currentPage, blocks.length]);

    const scale = zoom === "fit" ? fitScale : zoom;

    // The inspector follows the selection: it opens on the block's tab when you pick one,
    // and closes when nothing is selected — including after switching page or section. An
    // inspector with nothing to inspect is just lost canvas width; the report's own settings
    // stay one click away in the toolbar (and on this panel's second tab).
    //
    // Keyed on the selection alone, so opening the report tab by hand — or closing the panel
    // while a block is selected — sticks until the selection changes.
    useEffect(() => {
        if (selectedId !== null) {
            setRightTab("block");
            setRightOpen(true);

            return;
        }
        setRightTab("report");
        setRightOpen(false);
    }, [selectedId]);

    // Escape deselects, the same as clicking empty canvas — without stealing the key from a
    // text field or the rich-text editor, which use it for their own dismissals.
    useEffect(() => {
        if (selectedId === null) {
            return;
        }

        const onKey = (event: KeyboardEvent): void => {
            if (event.key !== "Escape") {
                return;
            }
            const target = event.target;
            if (
                target instanceof HTMLElement &&
                (target.isContentEditable ||
                    target.closest('input, textarea, [contenteditable="true"]') !== null)
            ) {
                return;
            }
            setSelectedId(null);
        };
        window.addEventListener("keydown", onKey);

        return () => window.removeEventListener("keydown", onKey);
    }, [selectedId]);

    const selectedBlock = blocks.find((b) => b.id === selectedId) ?? null;

    // Report-wide + current-page filters reach every dataset block on this page. The
    // inspector shows them so a block being cut by a page filter isn't a silent mystery
    // (the block's own filters still win per dimension — the cascade in DatasetEngine).
    const inheritedFilters = [...(pageFilters.all ?? []), ...(pageFilters[String(currentPage)] ?? [])];
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

    // Live navigation preview (so the editor shows the configured nav exactly as the client
    // will see it — chrome around the artboard, never inside it). Wired to switch pages.
    const navPos = theme.nav?.position ?? "tabs";
    const navStyle = theme.nav?.style ?? "pill";
    const navLabels = Array.from({ length: pageCount }, (_, index) =>
        pageNames[index]?.trim()
            ? (pageNames[index] as string)
            : `Página ${index + 1}`,
    );
    const selectPage = (index: number): void => {
        setCurrentPage(index);
        setSelectedId(null);
    };
    const showNavPreview = pageCount > 1 && navPos !== "hidden";

    return (
        <div className="ir-flex ir-h-full ir-min-h-0 ir-flex-col ir-bg-background">
            {/* ---- Top toolbar (full width) ----
                 One row below `lg`. Left to wrap, this bar stacked five rows on a phone and
                 the page navigator two more, pushing the canvas off the bottom of the screen
                 before you could see a single block. */}
            <header className="ir-flex ir-flex-nowrap ir-items-center ir-gap-2 ir-border-b ir-bg-card ir-px-3 ir-py-2 lg:ir-flex-wrap">
                <ToolbarButton
                    icon={
                        leftOpen ? (
                            <PanelLeftClose className="ir-size-4" />
                        ) : (
                            <PanelLeftOpen className="ir-size-4" />
                        )
                    }
                    title={leftOpen ? "Ocultar panel" : "Mostrar panel"}
                    onClick={() => setLeftOpen((open) => !open)}
                    active={leftOpen}
                />

                {/* Template name as an inline document-style title. */}
                <input
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    placeholder="Plantilla sin título"
                    className="ir-min-w-0 ir-flex-1 lg:ir-w-52 lg:ir-flex-none ir-rounded-md ir-border ir-border-transparent ir-bg-transparent ir-px-2 ir-py-1 ir-text-sm ir-font-semibold ir-text-foreground ir-transition placeholder:ir-font-normal placeholder:ir-text-muted-foreground hover:ir-border-border focus:ir-border-border focus:ir-bg-background focus:ir-outline-none"
                />

                {/* Everything from here to the right cluster is desktop-only: on a phone it
                    lives in the "⋯" sheet, where each item gets a full-width tap target
                    instead of a 32px chip on its own wrapped line. */}
                {!isCompact && (
                    <>
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

                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setGalleryOpen(true)}
                            title="Elegir una plantilla prediseñada"
                        >
                            <LayoutTemplate className="ir-size-4" />
                            Plantillas
                        </Button>

                        {/* Beside "Plantillas" on purpose: these are the two ways to START a
                            report, and someone asking "where do I begin?" should see both. */}
                        <Button
                            variant="accent"
                            size="sm"
                            onClick={() => setAiOpen(true)}
                            title="Generar el informe (o una sección) con IA"
                        >
                            <Sparkles className="ir-size-4" />
                            Generar con IA
                        </Button>

                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setCalcModalOpen(true)}
                            title="Crear métricas calculadas (fórmulas) reutilizables"
                        >
                            <FunctionSquare className="ir-size-4" />
                            Métricas calculadas
                        </Button>
                    </>
                )}

                {isCompact ? (
                    /* Save stays on the bar — it's the one action you must never have to
                       go looking for. Everything else is one tap behind "⋯". */
                    <div className="ir-ml-auto ir-flex ir-shrink-0 ir-items-center ir-gap-1">
                        <Button
                            onClick={save}
                            size="sm"
                            disabled={create.isPending || update.isPending || name === ""}
                            title={editingTemplateId !== null ? "Actualizar" : "Guardar"}
                        >
                            <Save className="ir-size-4" />
                        </Button>
                        <ToolbarButton
                            icon={<MoreHorizontal className="ir-size-4" />}
                            title="Más opciones"
                            onClick={() => setMoreOpen(true)}
                            active={moreOpen}
                        />
                    </div>
                ) : (
                <div className="ir-ml-auto ir-flex ir-flex-wrap ir-items-center ir-justify-end ir-gap-2">
                    {/* Compact preview-data control — site + period live here (preview only),
                        not as a giant panel widget. */}
                    <div className="ir-flex ir-h-8 ir-min-w-0 ir-items-center ir-rounded-lg ir-border ir-bg-background ir-pl-2 ir-text-sm">
                        <Globe className="ir-size-4 ir-shrink-0 ir-text-muted-foreground" />
                        <select
                            value={siteId ?? ""}
                            onChange={(event) =>
                                setSiteId(
                                    event.target.value === ""
                                        ? null
                                        : Number(event.target.value),
                                )
                            }
                            title="Sitio para la vista previa (los datos reales)"
                            className="ir-min-w-0 ir-max-w-[7.5rem] sm:ir-max-w-[10rem] ir-cursor-pointer ir-truncate ir-border-0 ir-bg-transparent ir-py-1 ir-pl-1.5 ir-pr-1 ir-text-sm focus:ir-outline-none"
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
                            className="ir-w-[7rem] sm:ir-w-[8.5rem] ir-min-w-0 ir-border-0 ir-bg-transparent ir-py-1 ir-pl-1 ir-pr-2 ir-text-sm focus:ir-outline-none"
                        />
                    </div>

                    <ToolbarDivider />

                    <ToolbarButton
                        icon={<Undo2 className="ir-size-4" />}
                        title="Deshacer (Ctrl+Z)"
                        onClick={undo}
                        disabled={past.length === 0}
                    />
                    <ToolbarButton
                        icon={<Redo2 className="ir-size-4" />}
                        title="Rehacer (Ctrl+Shift+Z)"
                        onClick={redo}
                        disabled={future.length === 0}
                    />

                    <ToolbarDivider />

                    <SyncStatus
                        siteId={siteId}
                        period={monthPeriod(month)}
                        monthLabel={new Date(
                            `${month}-01T00:00:00`,
                        ).toLocaleDateString("es", {
                            month: "long",
                            year: "numeric",
                        })}
                        onSynced={refreshPreview}
                    />
                    <Button
                        onClick={save}
                        disabled={
                            create.isPending || update.isPending || name === ""
                        }
                    >
                        <Save className="ir-size-4" />
                        {editingTemplateId !== null ? "Actualizar" : "Guardar"}
                    </Button>

                    {rightOpen ? (
                        <ToolbarButton
                            icon={<PanelRightClose className="ir-size-4" />}
                            title="Ocultar panel"
                            onClick={() => setRightOpen(false)}
                            active
                        />
                    ) : (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setRightTab("report");
                                setRightOpen(true);
                            }}
                            title="Filtros, tema y navegación de todo el informe"
                        >
                            <SlidersHorizontal className="ir-size-4" />
                            Ajustes del informe
                            {inheritedFilters.length > 0 && (
                                <span className="ir-ml-1 ir-rounded-full ir-bg-primary/15 ir-px-1.5 ir-text-[10px] ir-font-medium ir-text-primary">
                                    {inheritedFilters.length}
                                </span>
                            )}
                        </Button>
                    )}
                </div>
                )}
            </header>

            {/* ---- Body: left panel · canvas · inspector ---- */}
            <div className="ir-relative ir-flex ir-min-h-0 ir-flex-1">
                {/* On mobile the panels are overlays; a backdrop lets you tap outside to
                    dismiss them (desktop keeps them in-flow, so the backdrop is hidden). */}
                {(leftOpen || rightOpen) && (
                    <button
                        type="button"
                        aria-label="Cerrar paneles"
                        onClick={() => {
                            setLeftOpen(false);
                            setRightOpen(false);
                        }}
                        className="ir-absolute ir-inset-0 ir-z-10 ir-bg-black/20 lg:ir-hidden"
                    />
                )}
                {/* ---- Left panel (collapsible): config + blocks ---- */}
                {!leftOpen && (
                    <div className="ir-hidden ir-w-12 ir-shrink-0 ir-flex-col ir-items-center ir-gap-1 ir-border-r ir-bg-card ir-py-2 lg:ir-flex">
                        {LEFT_SECTIONS.map(({ key, title, icon: SectionIcon }) => (
                            <button
                                key={key}
                                type="button"
                                title={title}
                                onClick={() => {
                                    setLeftTab(key);
                                    setLeftOpen(true);
                                }}
                                className="ir-rounded-md ir-p-2 ir-text-muted-foreground ir-transition hover:ir-bg-muted hover:ir-text-foreground"
                            >
                                <SectionIcon className="ir-size-4" />
                            </button>
                        ))}
                    </div>
                )}

                {/* Below `lg` the left panel is a bottom SHEET, not a side drawer: the editor
                    is used one-handed on a phone and a full-height side panel puts every
                    control at the top of the screen, out of thumb reach. */}
                {leftOpen && (
                    <aside className="ir-absolute ir-inset-x-0 ir-bottom-0 ir-z-20 ir-flex ir-max-h-[70vh] ir-w-full ir-shrink-0 ir-flex-col ir-rounded-t-2xl ir-border-t ir-bg-card ir-shadow-xl lg:ir-static lg:ir-z-auto lg:ir-max-h-none lg:ir-w-64 lg:ir-rounded-none lg:ir-border-r lg:ir-border-t-0 lg:ir-shadow-none">
                        <div className="ir-mx-auto ir-mt-2 ir-h-1 ir-w-10 ir-shrink-0 ir-rounded-full ir-bg-border lg:ir-hidden" />
                        {/* Two tabs, not five stacked accordions: this column is only for
                            what you compose WITH. Report-level settings moved to the right
                            panel and the AI action to the toolbar — buried at the bottom of
                            a scrolling column, nobody found them. */}
                        <div className="ir-flex ir-gap-1 ir-border-b ir-p-2">
                            {([["blocks", "Bloques", Shapes], ["layers", "Capas", Layers]] as const).map(([key, label, TabIcon]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setLeftTab(key)}
                                    className={cn(
                                        "ir-inline-flex ir-flex-1 ir-items-center ir-justify-center ir-gap-1.5 ir-rounded-md ir-px-2 ir-py-1.5 ir-text-xs ir-font-medium ir-transition-colors",
                                        leftTab === key
                                            ? "ir-bg-primary/10 ir-text-primary"
                                            : "ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground",
                                    )}
                                >
                                    <TabIcon className="ir-size-3.5" />
                                    {label}
                                </button>
                            ))}
                        </div>

                        <div className="ir-min-h-0 ir-flex-1 ir-overflow-y-auto ir-p-3">
                            {leftTab === "blocks" ? (
                                <>
                            <BlockPalette
                                onAdd={addBlock}
                                onDragType={setDraggingType}
                            />
                                </>
                            ) : (
                                <>
                                    <p className="ir-mb-2 ir-text-[11px] ir-font-semibold ir-uppercase ir-tracking-wider ir-text-muted-foreground">
                                        Página {currentPage + 1}
                                    </p>
                            {pageBlocks.length === 0 ? (
                                <p className="ir-text-[11px] ir-text-muted-foreground">
                                    Sin bloques en esta página.
                                </p>
                            ) : (
                                <div className="ir-flex ir-flex-col ir-gap-0.5">
                                    {pageBlocks.map((block) => {
                                        const meta = BLOCK_META[block.type];
                                        const LayerIcon = meta.icon;
                                        const isSelected =
                                            block.id === selectedId;

                                        return (
                                            <div
                                                key={block.id}
                                                onClick={() =>
                                                    setSelectedId(block.id)
                                                }
                                                className={cn(
                                                    "ir-group ir-flex ir-cursor-pointer ir-items-center ir-gap-2 ir-rounded-md ir-px-2 ir-py-1.5 ir-text-xs ir-transition",
                                                    isSelected
                                                        ? "ir-bg-primary/10 ir-text-foreground"
                                                        : "ir-text-muted-foreground hover:ir-bg-muted",
                                                )}
                                            >
                                                <LayerIcon className="ir-size-3.5 ir-shrink-0" />
                                                <span className="ir-flex-1 ir-truncate">
                                                    {meta.label}
                                                    {block.binding != null && (
                                                        <span className="ir-text-muted-foreground/70">
                                                            {" "}
                                                            ·{" "}
                                                            {
                                                                block.binding
                                                                    .metric
                                                            }
                                                        </span>
                                                    )}
                                                </span>
                                                <button
                                                    type="button"
                                                    title="Duplicar"
                                                    onClick={(event) => {
                                                        event.stopPropagation();
                                                        duplicateBlock(
                                                            block.id,
                                                        );
                                                    }}
                                                    className="ir-hidden ir-text-muted-foreground hover:ir-text-foreground group-hover:ir-block"
                                                >
                                                    <Copy className="ir-size-3.5" />
                                                </button>
                                                <button
                                                    type="button"
                                                    title="Eliminar"
                                                    onClick={(event) => {
                                                        event.stopPropagation();
                                                        removeBlock(block.id);
                                                    }}
                                                    className="ir-hidden ir-text-muted-foreground hover:ir-text-red-500 group-hover:ir-block"
                                                >
                                                    <Trash2 className="ir-size-3.5" />
                                                </button>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                                </>
                            )}
                        </div>
                    </aside>
                )}

                {/* ---- Center: the WYSIWYG canvas (a centered artboard on a gray workspace) ---- */}
                <div
                    ref={canvasColumnRef}
                    className="ir-flex ir-min-w-0 ir-flex-1 ir-flex-col ir-bg-muted/40"
                >
                    {/* Page navigator + preview-data status. `flex-wrap` only from `lg`:
                        on a phone this row must stay a single line — the tabs scroll
                        sideways instead of stacking. */}
                    <div className="ir-flex ir-flex-nowrap ir-items-center ir-justify-between ir-gap-x-3 ir-gap-y-2 ir-border-b ir-bg-background/70 ir-px-3 ir-py-2 lg:ir-flex-wrap lg:ir-gap-x-4 lg:ir-px-4">
                        {/* min-w-0 + scroll: with several pages the tabs used to overflow the
                            canvas column and run underneath the inspector. */}
                        <div className="ir-flex ir-min-w-0 ir-flex-1 ir-items-center ir-gap-1 ir-overflow-x-auto">
                            {Array.from({ length: pageCount }, (_, index) => (
                                <div
                                    key={index}
                                    className="ir-group ir-relative ir-shrink-0"
                                >
                                    {renamingPage === index ? (
                                        <input
                                            autoFocus
                                            value={pageNames[index] ?? ""}
                                            placeholder={`Página ${index + 1}`}
                                            onChange={(event) =>
                                                renamePage(
                                                    index,
                                                    event.target.value,
                                                )
                                            }
                                            onBlur={() => setRenamingPage(null)}
                                            onKeyDown={(event) => {
                                                if (
                                                    event.key === "Enter" ||
                                                    event.key === "Escape"
                                                ) {
                                                    setRenamingPage(null);
                                                }
                                            }}
                                            className="ir-w-28 ir-rounded-md ir-border ir-border-primary ir-bg-background ir-px-2 ir-py-1 ir-text-sm"
                                        />
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setCurrentPage(index);
                                                setSelectedId(null);
                                            }}
                                            onDoubleClick={() =>
                                                setRenamingPage(index)
                                            }
                                            title="Doble clic para renombrar"
                                            className={cn(
                                                // whitespace-nowrap: "Tráfico y SEO" was
                                                // breaking inside its own chip, making the
                                                // whole navigator two rows tall on a phone.
                                                "ir-whitespace-nowrap ir-rounded-md ir-border ir-px-3 ir-py-1 ir-text-sm",
                                                index === currentPage
                                                    ? "ir-border-primary ir-bg-primary/5 ir-font-medium"
                                                    : "ir-text-muted-foreground hover:ir-border-primary/60",
                                            )}
                                        >
                                            {pageNames[index]?.trim()
                                                ? pageNames[index]
                                                : `Página ${index + 1}`}
                                        </button>
                                    )}
                                    {pageCount > 1 &&
                                        renamingPage !== index && (
                                            <button
                                                type="button"
                                                title="Eliminar página"
                                                onClick={() =>
                                                    removePage(index)
                                                }
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
                                className="ir-shrink-0"
                            >
                                <Plus className="ir-size-4" />
                            </Button>
                            {/* Cover/back-cover are once-per-report actions — on a phone they
                                sit in the "⋯" sheet rather than eat the navigator. */}
                            {!isCompact && (
                                <>
                                    <span className="ir-mx-1 ir-h-5 ir-w-px ir-shrink-0 ir-bg-border" />
                                    {!hasCover && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={addCoverPage}
                                            title="Añadir una página de portada al inicio"
                                            className="ir-shrink-0"
                                        >
                                            <BookOpen className="ir-size-4" />
                                            Portada
                                        </Button>
                                    )}
                                    {!hasBackCover && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={addBackCoverPage}
                                            title="Añadir una página de contraportada al final"
                                            className="ir-shrink-0"
                                        >
                                            <Flag className="ir-size-4" />
                                            Contraportada
                                        </Button>
                                    )}
                                </>
                            )}
                        </div>

                        <div className="ir-flex ir-shrink-0 ir-items-center ir-gap-2 lg:ir-gap-3">
                            {/* Zoom: "Ajustar" is the default because it's what makes the
                                report look like itself on a laptop. */}
                            <div className="ir-flex ir-shrink-0 ir-items-center ir-gap-0.5 ir-rounded-md ir-border ir-bg-background ir-p-0.5">
                                <button
                                    type="button"
                                    title="Reducir"
                                    onClick={() => setZoom(Math.max(0.35, Math.round((scale - 0.1) * 100) / 100))}
                                    className="ir-rounded ir-px-1.5 ir-py-1 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground"
                                >
                                    <Minus className="ir-size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    title="Ajustar al ancho disponible"
                                    onClick={() => setZoom(zoom === "fit" ? 1 : "fit")}
                                    className={cn(
                                        "ir-min-w-[3.25rem] ir-rounded ir-px-1.5 ir-py-1 ir-text-[11px] ir-font-medium ir-tabular-nums ir-transition",
                                        zoom === "fit"
                                            ? "ir-bg-primary/10 ir-text-primary"
                                            : "ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground",
                                    )}
                                >
                                    {zoom === "fit" ? "Ajustar" : `${Math.round(scale * 100)}%`}
                                </button>
                                <button
                                    type="button"
                                    title="Ampliar"
                                    onClick={() => setZoom(Math.min(1.5, Math.round((scale + 0.1) * 100) / 100))}
                                    className="ir-rounded ir-px-1.5 ir-py-1 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground"
                                >
                                    <Plus className="ir-size-3.5" />
                                </button>
                            </div>

                            {/* Same state, two lengths: the full sentence on a laptop, a
                                colour-coded word on a phone (where the sentence alone was
                                wrapping the whole row). The long form stays available in
                                the "⋯" sheet. */}
                            <div className="ir-shrink-0 ir-text-xs">
                                {isCompact ? (
                                    <span
                                        title={previewStatus.long}
                                        className={cn(
                                            "ir-inline-flex ir-items-center ir-gap-1.5 ir-whitespace-nowrap",
                                            previewStatus.tone,
                                        )}
                                    >
                                        <span className="ir-size-1.5 ir-rounded-full ir-bg-current" />
                                        {previewStatus.short}
                                    </span>
                                ) : (
                                    <span className={previewStatus.tone}>{previewStatus.long}</span>
                                )}
                            </div>
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
                            <p className="ir-text-xs ir-text-amber-700">
                                {aiNotice}
                            </p>
                            <button
                                type="button"
                                className="ir-shrink-0 ir-text-xs ir-text-amber-700 hover:ir-underline"
                                onClick={() => setAiNotice(null)}
                            >
                                Cerrar
                            </button>
                        </div>
                    )}

                    {/* Scrollable workspace with the centered artboard + live nav chrome.
                        The sidebar preview is a real column to the LEFT of the artboard (it
                        mirrors the viewer's fixed left rail) — laid out in flow so it never
                        overlaps the report. It stays put on scroll (sticky). */}
                    {/* mousedown, not click: it fires before the grid starts a drag, and a
                        click on empty canvas is the standard way out of a selection (there
                        was no way to deselect at all, so the report's settings — which show
                        when nothing is selected — were unreachable once you'd picked a block).
                        `scrollbar-gutter: stable` keeps the scrollbar from changing the
                        available width, the other half of the vibration fix. */}
                    <div
                        onMouseDown={(event) => {
                            const target = event.target;
                            if (
                                target instanceof HTMLElement &&
                                // `.react-grid-item` covers the grid's own resize handle,
                                // which is a sibling of the tile rather than inside it —
                                // resizing must not drop the selection.
                                target.closest("[data-block-tile], .react-grid-item") !== null
                            ) {
                                return;
                            }
                            setSelectedId(null);
                        }}
                        className="ir-min-h-0 ir-flex-1 ir-overflow-auto ir-p-3 lg:ir-p-6"
                        style={{ scrollbarGutter: "stable" }}
                    >
                        {/* The artboard renders at its natural width and is SCALED to fit,
                            so the report keeps the proportions the client will see instead
                            of reflowing into whatever narrow column the panels leave. */}
                        {/* Outer box reserves the SCALED footprint so `mx-auto` centres the
                            real thing; the inner artboard keeps its natural width and is
                            scaled from its top-LEFT corner. Scaling from the centre made a
                            1024px box inside a narrower column hang off to the right. */}
                        <div
                            className="ir-mx-auto"
                            style={{ width: ARTBOARD_WIDTH * scale, height: artboardHeight * scale }}
                        >
                            <div
                                ref={artboardRef}
                                className="ir-flex ir-items-start ir-justify-center ir-gap-5"
                                style={{
                                    width: ARTBOARD_WIDTH,
                                    transform: `scale(${scale})`,
                                    transformOrigin: "top left",
                                }}
                            >
                            {showNavPreview && navPos === "sidebar" && (
                                <aside
                                    className="ir-sticky ir-top-0 ir-w-44 ir-shrink-0 ir-rounded-xl ir-border ir-bg-card ir-p-3 ir-shadow-sm"
                                    style={canvasThemeStyle}
                                >
                                    <p className="ir-mb-2 ir-px-1 ir-text-[11px] ir-font-semibold ir-uppercase ir-tracking-wider ir-text-muted-foreground">
                                        Páginas
                                    </p>
                                    <ReportPageNav
                                        labels={navLabels}
                                        active={currentPage}
                                        onSelect={selectPage}
                                        navStyle={navStyle}
                                        orientation="v"
                                    />
                                </aside>
                            )}
                            <div
                                className="ir-flex ir-w-full ir-max-w-5xl ir-min-w-0 ir-flex-col ir-gap-3"
                                style={canvasThemeStyle}
                            >
                                {showNavPreview && navPos !== "sidebar" && (
                                    <div
                                        className={
                                            navPos === "top"
                                                ? "ir-rounded-xl ir-border ir-bg-card ir-px-3 ir-py-2 ir-shadow-sm"
                                                : undefined
                                        }
                                    >
                                        <ReportPageNav
                                            labels={navLabels}
                                            active={currentPage}
                                            onSelect={selectPage}
                                            navStyle={navStyle}
                                            orientation="h"
                                        />
                                    </div>
                                )}
                                <div className="ir-border ir-bg-card ir-p-6 ir-shadow-sm">
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
                                                        data={
                                                            renderData[block.id]
                                                        }
                                                        selected={
                                                            block.id ===
                                                            selectedId
                                                        }
                                                        onSelect={() =>
                                                            setSelectedId(
                                                                block.id,
                                                            )
                                                        }
                                                        onRemove={() =>
                                                            removeBlock(
                                                                block.id,
                                                            )
                                                        }
                                                        onDuplicate={() =>
                                                            duplicateBlock(
                                                                block.id,
                                                            )
                                                        }
                                                    />
                                                </div>
                                            ))}
                                        </Grid>
                                    </ReportSettingsProvider>
                                    {pageBlocks.length === 0 && (
                                        <div className="ir-flex ir-flex-col ir-items-center ir-justify-center ir-gap-3 ir-py-16 ir-text-center">
                                            <span className="ir-flex ir-size-12 ir-items-center ir-justify-center ir-rounded-xl ir-bg-muted ir-text-muted-foreground">
                                                <Shapes className="ir-size-6" />
                                            </span>
                                            <div>
                                                <p className="ir-text-sm ir-font-medium ir-text-foreground">
                                                    Página en blanco
                                                </p>
                                                <p className="ir-mt-1 ir-text-xs ir-text-muted-foreground">
                                                    Arrastra un bloque desde
                                                    «Insertar» o haz clic para
                                                    añadirlo.
                                                </p>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* ---- Right panel (collapsible): block settings · report settings ---- */}
                {rightOpen && (
                    <aside className="ir-absolute ir-inset-x-0 ir-bottom-0 ir-z-20 ir-flex ir-max-h-[70vh] ir-w-full ir-shrink-0 ir-flex-col ir-rounded-t-2xl ir-border-t ir-bg-card ir-shadow-xl lg:ir-static lg:ir-z-auto lg:ir-max-h-none lg:ir-w-72 lg:ir-rounded-none lg:ir-border-l lg:ir-border-t-0 lg:ir-shadow-none">
                        <div className="ir-mx-auto ir-mt-2 ir-h-1 ir-w-10 ir-shrink-0 ir-rounded-full ir-bg-border lg:ir-hidden" />
                        {/* Two tabs, mirroring the left panel. Showing one OR the other purely
                            by selection left no way to reach the report's settings while a
                            block was selected. */}
                        <div className="ir-flex ir-gap-1 ir-border-b ir-p-2">
                            {(
                                [
                                    ["block", "Bloque", Shapes],
                                    ["report", "Informe", SlidersHorizontal],
                                ] as const
                            ).map(([key, label, TabIcon]) => {
                                const disabled = key === "block" && selectedBlock === null;

                                return (
                                    <button
                                        key={key}
                                        type="button"
                                        disabled={disabled}
                                        onClick={() => setRightTab(key)}
                                        title={
                                            disabled
                                                ? "Selecciona un bloque del lienzo"
                                                : undefined
                                        }
                                        className={cn(
                                            "ir-inline-flex ir-flex-1 ir-items-center ir-justify-center ir-gap-1.5 ir-rounded-md ir-px-2 ir-py-1.5 ir-text-xs ir-font-medium ir-transition-colors",
                                            disabled
                                                ? "ir-cursor-not-allowed ir-text-muted-foreground/40"
                                                : rightTab === key
                                                  ? "ir-bg-primary/10 ir-text-primary"
                                                  : "ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground",
                                        )}
                                    >
                                        <TabIcon className="ir-size-3.5" />
                                        {label}
                                        {key === "report" &&
                                            inheritedFilters.length > 0 && (
                                                <span className="ir-rounded-full ir-bg-primary/15 ir-px-1.5 ir-text-[10px] ir-font-medium ir-text-primary">
                                                    {inheritedFilters.length}
                                                </span>
                                            )}
                                    </button>
                                );
                            })}
                        </div>

                        <div className="ir-min-h-0 ir-flex-1 ir-overflow-y-auto ir-p-3">
                            {rightTab === "block" && selectedBlock !== null ? (
                                <Inspector
                                    block={selectedBlock}
                                    catalog={fullCatalog}
                                    siteId={siteId}
                                    inheritedFilters={inheritedFilters}
                                    onChange={updateBlock}
                                />
                            ) : (
                                /* The document's own properties, the Figma/Canva pattern.
                                   These used to be two accordions at the bottom of the left
                                   column, where nobody found them. */
                                <div className="ir-flex ir-flex-col ir-gap-4">
                                    <div>
                                        <h2 className="ir-text-sm ir-font-semibold ir-tracking-tight">Ajustes del informe</h2>
                                        <p className="ir-mt-0.5 ir-text-[11px] ir-text-muted-foreground">
                                            Se aplican a todo el informe, en todas sus páginas.
                                        </p>
                                    </div>

                                    <Section title="Filtros de página" icon={<Filter className="ir-size-4" />}>
                            <PageFiltersPanel
                                catalog={catalog}
                                currentPage={currentPage}
                                filters={pageFilters}
                                onChange={setPageFilters}
                            />
                                    </Section>

                                    <Section title="Tema del reporte" icon={<Palette className="ir-size-4" />}>
                            <div className="ir-flex ir-flex-col ir-gap-3">
                                <Field label="Color de acento">
                                    <ColorSwatch
                                        value={theme.accent ?? ""}
                                        onChange={(value) =>
                                            setTheme((current) => ({
                                                ...current,
                                                accent: value ?? null,
                                            }))
                                        }
                                    />
                                    <p className="ir-mt-1 ir-text-[11px] ir-text-muted-foreground">
                                        Sin color = usa la marca de la agencia.
                                    </p>
                                </Field>
                                <Field label="Densidad">
                                    <SegmentedControl
                                        value={theme.density ?? "normal"}
                                        onChange={(value) =>
                                            setTheme((current) => ({
                                                ...current,
                                                density: value,
                                            }))
                                        }
                                        options={[
                                            {
                                                value: "normal",
                                                label: "Normal",
                                            },
                                            {
                                                value: "compact",
                                                label: "Compacta",
                                            },
                                        ]}
                                    />
                                </Field>
                                <Field label="Navegación entre páginas">
                                    <SegmentedControl
                                        value={theme.nav?.position ?? "tabs"}
                                        onChange={(value) =>
                                            setTheme((current) => ({
                                                ...current,
                                                nav: {
                                                    ...current.nav,
                                                    position: value,
                                                },
                                            }))
                                        }
                                        options={[
                                            {
                                                value: "tabs",
                                                label: "Pestañas",
                                            },
                                            { value: "top", label: "Barra" },
                                            {
                                                value: "sidebar",
                                                label: "Lateral",
                                            },
                                            {
                                                value: "hidden",
                                                label: "Ninguna",
                                            },
                                        ]}
                                    />
                                    <p className="ir-mt-1 ir-text-[11px] ir-text-muted-foreground">
                                        Cómo cambia de página el cliente (estilo
                                        Looker/Power BI).
                                    </p>
                                </Field>
                                <Field label="Estilo del menú">
                                    <SegmentedControl
                                        value={theme.nav?.style ?? "pill"}
                                        onChange={(value) =>
                                            setTheme((current) => ({
                                                ...current,
                                                nav: {
                                                    ...current.nav,
                                                    style: value,
                                                },
                                            }))
                                        }
                                        options={[
                                            { value: "pill", label: "Píldora" },
                                            {
                                                value: "underline",
                                                label: "Subrayado",
                                            },
                                            { value: "solid", label: "Sólido" },
                                        ]}
                                    />
                                </Field>
                                {theme.nav?.position === "sidebar" && (
                                    <Toggle
                                        checked={
                                            theme.nav?.collapsible ?? false
                                        }
                                        onChange={(checked) =>
                                            setTheme((current) => ({
                                                ...current,
                                                nav: {
                                                    ...current.nav,
                                                    collapsible: checked,
                                                },
                                            }))
                                        }
                                        label="Menú lateral colapsable"
                                    />
                                )}
                            </div>
                                    </Section>
                                </div>
                            )}
                        </div>
                    </aside>
                )}
            </div>

            {/* ---- Phone overflow: everything the one-row bar can't hold ---- */}
            {moreOpen && (
                <BottomSheet title="Opciones del editor" onClose={() => setMoreOpen(false)}>
                    <div className="ir-flex ir-flex-col ir-gap-4">
                        {/* Preview data first: it decides what every number on the canvas
                            says, and it's the control people go looking for. */}
                        <div>
                            <p className="ir-mb-1.5 ir-text-[11px] ir-font-semibold ir-uppercase ir-tracking-wider ir-text-muted-foreground">
                                Vista previa
                            </p>
                            <div className="ir-flex ir-flex-col ir-gap-2">
                                <label className="ir-flex ir-h-11 ir-items-center ir-gap-2 ir-rounded-lg ir-border ir-bg-background ir-px-3">
                                    <Globe className="ir-size-4 ir-shrink-0 ir-text-muted-foreground" />
                                    <select
                                        value={siteId ?? ""}
                                        onChange={(event) =>
                                            setSiteId(
                                                event.target.value === ""
                                                    ? null
                                                    : Number(event.target.value),
                                            )
                                        }
                                        className="ir-min-w-0 ir-flex-1 ir-cursor-pointer ir-border-0 ir-bg-transparent ir-text-sm focus:ir-outline-none"
                                    >
                                        <option value="">Datos de ejemplo</option>
                                        {sites.map((site) => (
                                            <option key={site.id} value={site.id}>
                                                {site.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="ir-flex ir-h-11 ir-items-center ir-gap-2 ir-rounded-lg ir-border ir-bg-background ir-px-3">
                                    <Calendar className="ir-size-4 ir-shrink-0 ir-text-muted-foreground" />
                                    <input
                                        type="month"
                                        value={month}
                                        onChange={(event) => setMonth(event.target.value)}
                                        className="ir-min-w-0 ir-flex-1 ir-border-0 ir-bg-transparent ir-text-sm focus:ir-outline-none"
                                    />
                                </label>
                                <p className={cn("ir-text-[11px]", previewStatus.tone)}>
                                    {previewStatus.long}
                                </p>
                                <SyncStatus
                                    siteId={siteId}
                                    period={monthPeriod(month)}
                                    monthLabel={new Date(
                                        `${month}-01T00:00:00`,
                                    ).toLocaleDateString("es", {
                                        month: "long",
                                        year: "numeric",
                                    })}
                                    onSynced={refreshPreview}
                                />
                            </div>
                        </div>

                        <div className="ir-border-t ir-pt-2">
                            <p className="ir-mb-1 ir-text-[11px] ir-font-semibold ir-uppercase ir-tracking-wider ir-text-muted-foreground">
                                Empezar
                            </p>
                            <SheetAction
                                icon={<Sparkles className="ir-size-4" />}
                                label="Generar con IA"
                                hint="Crea el informe completo, o una sección"
                                onClick={() => {
                                    setMoreOpen(false);
                                    setAiOpen(true);
                                }}
                            />
                            <SheetAction
                                icon={<LayoutTemplate className="ir-size-4" />}
                                label="Plantillas"
                                hint="Partir de un diseño prediseñado"
                                onClick={() => {
                                    setMoreOpen(false);
                                    setGalleryOpen(true);
                                }}
                            />
                        </div>

                        <div className="ir-border-t ir-pt-2">
                            <p className="ir-mb-1 ir-text-[11px] ir-font-semibold ir-uppercase ir-tracking-wider ir-text-muted-foreground">
                                Informe
                            </p>
                            <SheetAction
                                icon={<SlidersHorizontal className="ir-size-4" />}
                                label="Ajustes del informe"
                                hint="Filtros, tema y navegación"
                                badge={inheritedFilters.length}
                                onClick={() => {
                                    setMoreOpen(false);
                                    setRightTab("report");
                                    setRightOpen(true);
                                }}
                            />
                            <SheetAction
                                icon={<FunctionSquare className="ir-size-4" />}
                                label="Métricas calculadas"
                                hint="Fórmulas reutilizables"
                                onClick={() => {
                                    setMoreOpen(false);
                                    setCalcModalOpen(true);
                                }}
                            />
                            {!hasCover && (
                                <SheetAction
                                    icon={<BookOpen className="ir-size-4" />}
                                    label="Añadir portada"
                                    onClick={() => {
                                        setMoreOpen(false);
                                        addCoverPage();
                                    }}
                                />
                            )}
                            {!hasBackCover && (
                                <SheetAction
                                    icon={<Flag className="ir-size-4" />}
                                    label="Añadir contraportada"
                                    onClick={() => {
                                        setMoreOpen(false);
                                        addBackCoverPage();
                                    }}
                                />
                            )}
                            {editingTemplateId !== null && (
                                <SheetAction
                                    icon={<Plus className="ir-size-4" />}
                                    label="Nueva plantilla"
                                    hint="Dejar de editar la actual"
                                    onClick={() => {
                                        setMoreOpen(false);
                                        editTemplate(null);
                                    }}
                                />
                            )}
                        </div>

                        <div className="ir-border-t ir-pt-2">
                            <p className="ir-mb-1 ir-text-[11px] ir-font-semibold ir-uppercase ir-tracking-wider ir-text-muted-foreground">
                                Historial
                            </p>
                            <SheetAction
                                icon={<Undo2 className="ir-size-4" />}
                                label="Deshacer"
                                disabled={past.length === 0}
                                onClick={undo}
                            />
                            <SheetAction
                                icon={<Redo2 className="ir-size-4" />}
                                label="Rehacer"
                                disabled={future.length === 0}
                                onClick={redo}
                            />
                        </div>
                    </div>
                </BottomSheet>
            )}

            {galleryOpen && (
                <Modal
                    onClose={() => setGalleryOpen(false)}
                    className="ir-max-w-6xl xl:ir-max-w-7xl"
                >
                    <Card
                        title="Plantillas prediseñadas"
                        description="Elige un punto de partida. Si ya tienes contenido, te preguntaremos si añadirla debajo o reemplazar."
                        actions={
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setGalleryOpen(false)}
                            >
                                Cerrar
                            </Button>
                        }
                    >
                        <div className="ir-grid ir-max-h-[70vh] ir-gap-3 ir-overflow-y-auto sm:ir-grid-cols-2 lg:ir-grid-cols-3 xl:ir-grid-cols-4">
                            <button
                                type="button"
                                onClick={() => {
                                    loadDefaultTemplate();
                                    setGalleryOpen(false);
                                }}
                                disabled={defaultTpl.isPending}
                                className="ir-flex ir-items-start ir-gap-2.5 ir-rounded-md ir-border ir-bg-background ir-p-3 ir-text-left ir-transition hover:ir-border-primary hover:ir-bg-primary/5 disabled:ir-opacity-50"
                            >
                                <span className="ir-mt-0.5 ir-flex ir-size-7 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ir-bg-primary/10 ir-text-primary">
                                    <LayoutTemplate className="ir-size-4" />
                                </span>
                                <span className="ir-min-w-0">
                                    <span className="ir-block ir-text-sm ir-font-medium">
                                        Plantilla por defecto
                                    </span>
                                    <span className="ir-block ir-text-xs ir-text-muted-foreground">
                                        El informe narrativo estándar de Imagina
                                        (§11.5).
                                    </span>
                                </span>
                            </button>

                            {GALLERY.map((template) => {
                                const GalleryIcon =
                                    GALLERY_ICONS[template.key] ??
                                    LayoutTemplate;

                                return (
                                    <button
                                        key={template.key}
                                        type="button"
                                        onClick={() => {
                                            chooseTemplate(template);
                                            setGalleryOpen(false);
                                        }}
                                        className="ir-flex ir-items-start ir-gap-2.5 ir-rounded-md ir-border ir-bg-background ir-p-3 ir-text-left ir-transition hover:ir-border-primary hover:ir-bg-primary/5"
                                    >
                                        <span className="ir-mt-0.5 ir-flex ir-size-7 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ir-bg-primary/10 ir-text-primary">
                                            <GalleryIcon className="ir-size-4" />
                                        </span>
                                        <span className="ir-min-w-0">
                                            <span className="ir-block ir-text-sm ir-font-medium">
                                                {template.name}
                                            </span>
                                            <span className="ir-block ir-text-xs ir-text-muted-foreground">
                                                {template.description}
                                            </span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </Card>
                </Modal>
            )}

            {aiOpen && (
                <div className="ir-fixed ir-inset-0 ir-z-50 ir-flex ir-items-start ir-justify-center ir-bg-black/40 ir-p-4 ir-pt-[10vh]">
                    <button type="button" aria-label="Cerrar" onClick={() => setAiOpen(false)} className="ir-fixed ir-inset-0 ir-cursor-default" />
                    <div className="ir-relative ir-z-10 ir-w-full ir-max-w-lg ir-rounded-xl ir-border ir-bg-card ir-p-5 ir-shadow-ir-lg">
                        <div className="ir-mb-4 ir-flex ir-items-start ir-justify-between ir-gap-3">
                            <div>
                                <h2 className="ir-text-sm ir-font-semibold ir-tracking-tight">Generar con IA</h2>
                                <p className="ir-mt-0.5 ir-text-xs ir-text-muted-foreground">
                                    Crea el informe completo a partir de los datos conectados, o añade una sección al actual.
                                </p>
                            </div>
                            <Button variant="ghost" size="sm" onClick={() => setAiOpen(false)}>
                                Cerrar
                            </Button>
                        </div>
                            <div className="ir-flex ir-flex-col ir-gap-2.5">
                                <div className="ir-flex ir-gap-2">
                                    <Input
                                        placeholder="Enfoque para la IA…"
                                        value={aiPrompt}
                                        onChange={(event) =>
                                            setAiPrompt(event.target.value)
                                        }
                                    />
                                    <Button
                                        variant="ghost"
                                        onClick={generateWithAi}
                                        disabled={
                                            siteId === null || ai.isPending
                                        }
                                    >
                                        <Sparkles className="ir-size-4" />
                                        IA
                                    </Button>
                                </div>
                                {siteId === null && (
                                    <p className="ir-text-[11px] ir-text-muted-foreground">
                                        Elige un sitio en la barra superior para
                                        generar con IA.
                                    </p>
                                )}
                                <div className="ir-border-t ir-border-border ir-pt-2.5">
                                    <p className="ir-mb-1.5 ir-text-[11px] ir-font-medium ir-text-muted-foreground">
                                        Añadir sección al informe actual
                                    </p>
                                    <div className="ir-flex ir-gap-2">
                                        <Input
                                            placeholder="Ej.: sección de rendimiento de anuncios…"
                                            value={aiSectionPrompt}
                                            onChange={(event) =>
                                                setAiSectionPrompt(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <Button
                                            variant="ghost"
                                            onClick={generateSectionWithAi}
                                            disabled={
                                                siteId === null ||
                                                aiSection.isPending ||
                                                aiSectionPrompt.trim() === ""
                                            }
                                        >
                                            <Sparkles className="ir-size-4" />
                                            Añadir
                                        </Button>
                                    </div>
                                </div>
                                <p className="ir-text-[11px] ir-text-muted-foreground">
                                    ¿Prefieres partir de una plantilla? Pulsa{" "}
                                    <strong>«Plantillas»</strong> en la barra
                                    superior.
                                </p>
                                {(create.isSuccess || update.isSuccess) && (
                                    <p className="ir-text-xs ir-text-emerald-600">
                                        Guardada.
                                    </p>
                                )}
                            </div>
                    </div>
                </div>
            )}

            {calcModalOpen && (
                <CalcMetricsModal
                    siteId={siteId}
                    catalog={fullCatalog}
                    periodStart={monthPeriod(month).period_start}
                    periodEnd={monthPeriod(month).period_end}
                    agencyMetrics={agency?.calculated_metrics ?? []}
                    siteMetrics={
                        sites.find((site) => site.id === siteId)
                            ?.calculated_metrics ?? []
                    }
                    onClose={() => setCalcModalOpen(false)}
                />
            )}

            {pendingTpl !== null && (
                <div
                    className="ir-fixed ir-inset-0 ir-z-50 ir-flex ir-items-center ir-justify-center ir-bg-black/40 ir-p-4"
                    onClick={() => setPendingTpl(null)}
                >
                    <div
                        className="ir-w-full ir-max-w-sm ir-rounded-lg ir-border ir-bg-card ir-p-4 ir-shadow-xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <h3 className="ir-text-sm ir-font-semibold ir-text-foreground">
                            Añadir «{pendingTpl.name}»
                        </h3>
                        <p className="ir-mt-1 ir-text-xs ir-text-muted-foreground">
                            Ya tienes contenido en el lienzo. ¿Añadir esta
                            plantilla debajo de lo actual, o reemplazar todo el
                            informe?
                        </p>
                        <div className="ir-mt-4 ir-flex ir-flex-col ir-gap-2">
                            <Button
                                onClick={() => appendTemplate(pendingTpl.build)}
                                title="Añade los bloques debajo (las páginas se aplanan en la actual)"
                            >
                                <Plus className="ir-size-4" />
                                Añadir debajo
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => replaceWithTemplate(pendingTpl)}
                            >
                                <LayoutTemplate className="ir-size-4" />
                                Reemplazar todo
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => setPendingTpl(null)}
                            >
                                Cancelar
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
