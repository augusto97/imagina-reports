import { Copy, LayoutTemplate, Pencil, Plus, Sparkles, Star, Trash2 } from 'lucide-react';
import { type ReactElement, useState } from 'react';

import type { Block, BlockLayout } from '@shared/blocks/types';

import { useCreateReportTemplate, useDeleteReportTemplate, useReportTemplates } from '../api';
import { ActionMenu, Badge, Button, EmptyState, PageHeader } from '../components/ui';
import { useAdminUi } from '../store';
import type { ReportTemplateDto } from '../types';

function deleteErrorMessage(error: unknown): string {
    if (typeof error === 'object' && error !== null && 'response' in error) {
        const message = (error as { response?: { data?: { message?: unknown } } }).response?.data?.message;
        if (typeof message === 'string') {
            return message;
        }
    }

    return 'No se pudo eliminar la plantilla.';
}

/** Tint per kind of block, so the thumbnail reads like the report it will produce. */
function tone(type: string): string {
    if (type === 'header' || type === 'cover' || type === 'back_cover') return 'hsl(var(--ir-foreground) / 0.75)';
    if (type === 'kpi' || type === 'sales_summary' || type === 'goal' || type === 'healthscore') return 'hsl(var(--ir-accent) / 0.45)';
    if (type === 'chart' || type === 'geo_map' || type === 'funnel') return 'hsl(var(--ir-info) / 0.35)';
    if (type === 'narrative' || type === 'advisory' || type === 'cta') return 'hsl(var(--ir-muted-foreground) / 0.25)';

    return 'hsl(var(--ir-muted-foreground) / 0.18)';
}

/** A miniature wireframe of the template's first page, drawn from the blocks' grid layout. */
function Thumbnail({ blocks }: { blocks: Block[] }): ReactElement {
    const page = blocks.filter((block) => (block.page ?? 0) === 0 && block.layout != null) as (Block & { layout: BlockLayout })[];
    const rows = Math.max(12, ...page.map((block) => block.layout.y + block.layout.h));
    const visibleRows = Math.min(rows, 30);

    return (
        <svg viewBox={`0 0 120 ${visibleRows * 4}`} preserveAspectRatio="xMidYMin slice" className="ir-h-full ir-w-full">
            {page
                .filter((block) => block.layout.y < visibleRows)
                .map((block) => (
                    <rect
                        key={block.id}
                        x={block.layout.x * 10 + 1}
                        y={block.layout.y * 4 + 1}
                        width={Math.max(1, block.layout.w * 10 - 2)}
                        height={Math.max(1, block.layout.h * 4 - 2)}
                        rx={1.5}
                        fill={tone(block.type)}
                    />
                ))}
        </svg>
    );
}

/**
 * Plantillas: a gallery, not a list. Each template shows a wireframe of its first page so
 * the agency recognises it at a glance; editing is the main action, the rest is in a menu.
 */
export function TemplatesScreen(): ReactElement {
    const { data: templates = [], isLoading } = useReportTemplates();
    const editTemplate = useAdminUi((state) => state.editTemplate);
    const create = useCreateReportTemplate();
    const remove = useDeleteReportTemplate();
    const [removeError, setRemoveError] = useState<string | null>(null);

    const duplicate = (template: ReportTemplateDto): void => {
        create.mutate({ name: `${template.name} (copia)`, blocks: template.blocks as Block[] });
    };

    const confirmRemove = (id: number): void => {
        if (window.confirm('¿Eliminar esta plantilla? Los reportes ya generados no cambian, pero las definiciones que la usan volverán a la plantilla por defecto.')) {
            setRemoveError(null);
            remove.mutate(id, { onError: (error) => setRemoveError(deleteErrorMessage(error)) });
        }
    };

    return (
        <div className="ir-flex ir-flex-col">
            <PageHeader
                title="Plantillas"
                description="El diseño de tus reportes. Una plantilla sirve para todos los clientes que quieras; cada reporte la llena con los datos de su sitio."
                actions={
                    <Button onClick={() => editTemplate(null)}>
                        <Plus className="ir-size-4" />
                        Nueva plantilla
                    </Button>
                }
            />

            {removeError !== null && <p className="ir-mb-4 ir-rounded-lg ir-bg-danger/10 ir-px-3 ir-py-2 ir-text-sm ir-text-danger">{removeError}</p>}

            {isLoading ? (
                <p className="ir-text-sm ir-text-muted-foreground">Cargando plantillas…</p>
            ) : templates.length === 0 ? (
                <EmptyState
                    icon={LayoutTemplate}
                    title="Aún no tienes plantillas"
                    description="Créala desde cero, parte de la galería de modelos o pídesela a la IA dentro del editor."
                    action={
                        <Button onClick={() => editTemplate(null)}>
                            <Plus className="ir-size-4" />
                            Crear la primera
                        </Button>
                    }
                />
            ) : (
                <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2 xl:ir-grid-cols-3 2xl:ir-grid-cols-4 min-[1800px]:ir-grid-cols-5">
                    {templates.map((template) => {
                        const blocks = template.blocks as Block[];
                        const pages = Math.max(1, ...blocks.map((block) => (block.page ?? 0) + 1));

                        return (
                            <div key={template.id} className="ir-group ir-flex ir-flex-col ir-overflow-hidden ir-rounded-xl ir-border ir-bg-card ir-shadow-ir-xs ir-transition hover:ir-border-foreground/20 hover:ir-shadow-ir-sm">
                                <button type="button" onClick={() => editTemplate(template.id)} className="ir-relative ir-h-40 ir-overflow-hidden ir-border-b ir-bg-muted/40 ir-px-8 ir-pt-5" title="Abrir en el editor">
                                    {template.is_default && (
                                        <Badge tone="accent" className="ir-absolute ir-left-3 ir-top-3 ir-z-10 ir-bg-card ir-shadow-ir-xs">
                                            <Star className="ir-size-3" />
                                            Por defecto
                                        </Badge>
                                    )}
                                    <div className="ir-h-full ir-rounded-t-md ir-bg-card ir-p-2 ir-shadow-ir-sm ir-transition-transform group-hover:-ir-translate-y-0.5">
                                        <Thumbnail blocks={blocks} />
                                    </div>
                                </button>
                                <div className="ir-flex ir-items-start ir-justify-between ir-gap-2 ir-p-4">
                                    <div className="ir-min-w-0">
                                        <p className="ir-truncate ir-text-sm ir-font-semibold" title={template.name}>{template.name}</p>
                                        <p className="ir-mt-0.5 ir-text-xs ir-text-muted-foreground">
                                            {blocks.length} bloques · {pages} {pages === 1 ? 'página' : 'páginas'}
                                        </p>
                                    </div>
                                    <div className="ir-flex ir-shrink-0 ir-items-center ir-gap-1">
                                        <Button variant="ghost" size="sm" onClick={() => editTemplate(template.id)}>
                                            <Pencil className="ir-size-3.5" />
                                            Editar
                                        </Button>
                                        <ActionMenu
                                            label={`Opciones de ${template.name}`}
                                            items={[
                                                { label: 'Duplicar', icon: Copy, onSelect: () => duplicate(template), disabled: create.isPending },
                                                { label: 'Eliminar', icon: Trash2, danger: true, onSelect: () => confirmRemove(template.id), disabled: remove.isPending },
                                            ]}
                                        />
                                    </div>
                                </div>
                            </div>
                        );
                    })}

                    <button
                        type="button"
                        onClick={() => editTemplate(null)}
                        className="ir-flex ir-min-h-[14rem] ir-flex-col ir-items-center ir-justify-center ir-gap-2 ir-rounded-xl ir-border ir-border-dashed ir-bg-card/50 ir-p-6 ir-text-center ir-transition hover:ir-border-foreground/30 hover:ir-bg-card"
                    >
                        <span className="ir-flex ir-size-10 ir-items-center ir-justify-center ir-rounded-xl ir-bg-accent/10 ir-text-accent">
                            <Sparkles className="ir-size-5" />
                        </span>
                        <span className="ir-text-sm ir-font-semibold">Nueva plantilla</span>
                        <span className="ir-max-w-[15rem] ir-text-xs ir-text-muted-foreground">Desde cero, desde un modelo de la galería o generada con IA.</span>
                    </button>
                </div>
            )}
        </div>
    );
}
