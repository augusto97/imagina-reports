import {
    BarChart3,
    Box,
    DollarSign,
    Gauge,
    Hash,
    Heading,
    Image as ImageIcon,
    ListChecks,
    type LucideIcon,
    Megaphone,
    MessageSquare,
    Minus,
    Scissors,
    ShieldCheck,
    SlidersHorizontal,
    Table2,
    Target,
    Type,
} from 'lucide-react';
import { type ReactElement } from 'react';

import type { BlockType } from '@shared/blocks/types';

/** Per-block-type metadata for the palette + (later) layer list: label + icon. */
export const BLOCK_META: Record<BlockType, { label: string; icon: LucideIcon }> = {
    header: { label: 'Cabecera', icon: Heading },
    healthscore: { label: 'Health score', icon: Gauge },
    kpi: { label: 'KPI', icon: Hash },
    chart: { label: 'Gráfico', icon: BarChart3 },
    table: { label: 'Tabla', icon: Table2 },
    narrative: { label: 'Texto', icon: Type },
    security_shield: { label: 'Seguridad', icon: ShieldCheck },
    worklog_timeline: { label: 'Trabajo', icon: ListChecks },
    sales_summary: { label: 'Ventas', icon: DollarSign },
    goal: { label: 'Meta', icon: Target },
    control: { label: 'Filtro', icon: SlidersHorizontal },
    comments: { label: 'Comentarios', icon: MessageSquare },
    image: { label: 'Imagen', icon: ImageIcon },
    cta: { label: 'Llamada (CTA)', icon: Megaphone },
    divider: { label: 'Separador', icon: Minus },
    pagebreak: { label: 'Salto de página', icon: Scissors },
    custom: { label: 'Personalizado', icon: Box },
};

/** Palette grouping — mirrors how the default narrative report reads top-to-bottom (§11.5). */
const GROUPS: { title: string; types: BlockType[] }[] = [
    { title: 'KPIs & datos', types: ['kpi', 'sales_summary', 'goal', 'healthscore'] },
    { title: 'Gráficos & tablas', types: ['chart', 'table'] },
    { title: 'Texto & marca', types: ['header', 'narrative', 'cta', 'image'] },
    { title: 'Seguridad & soporte', types: ['security_shield', 'worklog_timeline', 'comments'] },
    { title: 'Interacción & layout', types: ['control', 'divider', 'pagebreak'] },
];

/**
 * The visual block palette: grouped icon tiles. Click adds the block to the current page;
 * dragging a tile onto the canvas drops it where you release (the grid is droppable). The
 * dragged type is reported via onDragType so the EditorScreen can size the drop placeholder.
 */
export function BlockPalette({
    onAdd,
    onDragType,
}: {
    onAdd: (type: BlockType) => void;
    onDragType: (type: BlockType | null) => void;
}): ReactElement {
    return (
        <div className="ir-flex ir-flex-col ir-gap-3">
            {GROUPS.map((group) => (
                <div key={group.title}>
                    <p className="ir-mb-1.5 ir-text-[10px] ir-font-semibold ir-uppercase ir-tracking-wider ir-text-muted-foreground/70">
                        {group.title}
                    </p>
                    <div className="ir-grid ir-grid-cols-2 ir-gap-1.5">
                        {group.types.map((type) => {
                            const meta = BLOCK_META[type];
                            const Icon = meta.icon;

                            return (
                                <button
                                    key={type}
                                    type="button"
                                    draggable
                                    onClick={() => onAdd(type)}
                                    onDragStart={(event) => {
                                        // RGL reads the drop position; we carry the type ourselves.
                                        event.dataTransfer.setData('text/plain', type);
                                        event.dataTransfer.effectAllowed = 'copy';
                                        onDragType(type);
                                    }}
                                    onDragEnd={() => onDragType(null)}
                                    title={`Añadir ${meta.label} — clic o arrastra al lienzo`}
                                    className="ir-group ir-flex ir-cursor-grab ir-flex-col ir-items-center ir-gap-1.5 ir-rounded-lg ir-border ir-bg-background ir-px-2 ir-py-2.5 ir-text-center ir-transition hover:ir-border-primary hover:ir-bg-primary/5 hover:ir-shadow-sm active:ir-cursor-grabbing"
                                >
                                    <Icon className="ir-size-4 ir-text-muted-foreground ir-transition group-hover:ir-text-primary" />
                                    <span className="ir-text-[11px] ir-font-medium ir-leading-tight ir-text-foreground">{meta.label}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            ))}
            <p className="ir-text-[11px] ir-text-muted-foreground">Clic para añadir, o arrastra un bloque al lienzo.</p>
        </div>
    );
}
