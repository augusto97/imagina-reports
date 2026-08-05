import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    AreaChart,
    BarChart3,
    BarChartHorizontal,
    ChevronRight,
    Database,
    LineChart,
    type LucideIcon,
    PieChart,
} from 'lucide-react';
import { type ReactElement, useEffect, useRef, useState } from 'react';

import type { Block, BlockBinding, DatasetFilter } from '@shared/blocks/types';
import { cn } from '@shared/lib/utils';

import { useUploadImage } from '../api';
import { Button, Field, Input, Select } from '../components/ui';
import type { CatalogEntry } from '../types';
import { BLOCK_META } from './BlockPalette';
import { DATA_BLOCKS } from './blockFactory';
import { ColorSwatch, Section, SegmentedControl, Toggle } from './controls';
import { FiltersPanel } from './FiltersPanel';
import { MetricPicker, sourceLabel } from './MetricPicker';
import { NarrativeEditor } from './NarrativeEditor';

/** A URL field with an inline image upload + preview — for logos and the image block. */
function ImageField({ value, onChange, placeholder }: { value: string; onChange: (url: string) => void; placeholder?: string }): ReactElement {
    const upload = useUploadImage();
    const inputRef = useRef<HTMLInputElement>(null);

    const pick = (file: File | undefined): void => {
        if (file !== undefined) {
            upload.mutate(file, {
                onSuccess: (url) => {
                    if (typeof url === 'string' && url !== '') {
                        onChange(url);
                    }
                },
            });
        }
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-1.5">
            <div className="ir-flex ir-items-center ir-gap-2">
                <Input value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder ?? 'https://…'} />
                <input ref={inputRef} type="file" accept="image/png,image/jpeg,image/webp" className="ir-hidden" onChange={(event) => pick(event.target.files?.[0])} />
                <Button type="button" variant="ghost" size="sm" onClick={() => inputRef.current?.click()} disabled={upload.isPending}>
                    {upload.isPending ? 'Subiendo…' : 'Subir'}
                </Button>
            </div>
            {value !== '' && <img src={value} alt="" className="ir-h-10 ir-w-auto ir-self-start ir-rounded ir-border ir-bg-card ir-object-contain ir-p-1" />}
            {upload.isError && <span className="ir-text-xs ir-text-red-500">No se pudo subir la imagen.</span>}
        </div>
    );
}

function str(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

/**
 * Background editor for the cover / back-cover: a solid colour, a two-colour gradient, or a
 * full-bleed image — plus the text colour. `bg_type` selects which is applied (see styleCss).
 */
function BackgroundControl({ style, setStyle }: { style: Record<string, unknown> | undefined; setStyle: (key: string, value: unknown) => void }): ReactElement {
    const type = str(style?.bg_type) !== ''
        ? str(style?.bg_type)
        : str(style?.bg_image) !== ''
          ? 'image'
          : str(style?.bg2) !== ''
            ? 'gradient'
            : 'solid';

    return (
        <div className="ir-flex ir-flex-col ir-gap-3">
            <Field label="Tipo de fondo">
                <SegmentedControl
                    value={type}
                    onChange={(value) => setStyle('bg_type', value)}
                    options={[
                        { value: 'solid', label: 'Color' },
                        { value: 'gradient', label: 'Degradado' },
                        { value: 'image', label: 'Imagen' },
                    ]}
                />
            </Field>

            {type === 'solid' && (
                <Field label="Color">
                    <ColorSwatch value={str(style?.bg)} onChange={(value) => setStyle('bg', value)} />
                </Field>
            )}

            {type === 'gradient' && (
                <>
                    <Field label="Desde">
                        <ColorSwatch value={str(style?.bg)} onChange={(value) => setStyle('bg', value)} />
                    </Field>
                    <Field label="Hasta">
                        <ColorSwatch value={str(style?.bg2)} onChange={(value) => setStyle('bg2', value)} />
                    </Field>
                    <Field label="Dirección">
                        <SegmentedControl
                            value={String(typeof style?.bg_angle === 'number' ? style.bg_angle : 135)}
                            onChange={(value) => setStyle('bg_angle', Number(value))}
                            options={[
                                { value: '180', label: 'Vertical' },
                                { value: '135', label: 'Diagonal' },
                                { value: '90', label: 'Horizontal' },
                            ]}
                        />
                    </Field>
                </>
            )}

            {type === 'image' && (
                <>
                    <Field label="Imagen de fondo" hint="Se recorta para cubrir toda la página.">
                        <ImageField value={str(style?.bg_image)} onChange={(url) => setStyle('bg_image', url === '' ? undefined : url)} />
                    </Field>
                    <Field label="Velo (para legibilidad)">
                        <SegmentedControl
                            value={String(typeof style?.bg_overlay === 'number' ? style.bg_overlay : 45)}
                            onChange={(value) => setStyle('bg_overlay', Number(value))}
                            options={[
                                { value: '0', label: 'Ninguno' },
                                { value: '25', label: 'Suave' },
                                { value: '45', label: 'Medio' },
                                { value: '65', label: 'Fuerte' },
                            ]}
                        />
                    </Field>
                    <Field label="Tinte del velo">
                        <SegmentedControl
                            value={str(style?.bg_overlay_color) || 'dark'}
                            onChange={(value) => setStyle('bg_overlay_color', value)}
                            options={[
                                { value: 'dark', label: 'Oscuro' },
                                { value: 'light', label: 'Claro' },
                            ]}
                        />
                    </Field>
                </>
            )}

            <Field label="Color del texto">
                <ColorSwatch value={str(style?.color)} onChange={(value) => setStyle('color', value)} />
            </Field>
        </div>
    );
}

/** Visual chart-type gallery (CLAUDE.md §11.3) — every option renders for single-series data. */
const CHART_TYPES: { type: string; label: string; Icon: LucideIcon }[] = [
    { type: 'line', label: 'Línea', Icon: LineChart },
    { type: 'bar', label: 'Barras', Icon: BarChart3 },
    { type: 'hbar', label: 'Horizontal', Icon: BarChartHorizontal },
    { type: 'area', label: 'Área', Icon: AreaChart },
    { type: 'donut', label: 'Dona', Icon: PieChart },
    { type: 'pie', label: 'Pastel', Icon: PieChart },
];

const TYPE_LABELS: Record<string, string> = {
    header: 'Cabecera',
    healthscore: 'Health score',
    kpi: 'KPI',
    chart: 'Gráfico',
    table: 'Tabla',
    narrative: 'Texto',
    security_shield: 'Seguridad',
    worklog_timeline: 'Trabajo del mes',
    sales_summary: 'Ventas',
    goal: 'Meta',
    image: 'Imagen',
    cta: 'Llamada a la acción',
    comments: 'Comentarios',
    control: 'Filtro',
    divider: 'Separador',
    pagebreak: 'Salto de página',
    custom: 'Personalizado',
    cover: 'Portada',
    back_cover: 'Contraportada',
    advisory: 'Diagnóstico IA',
};

/** Block types that manage their own content fields (no generic title field). */
const NO_TITLE = new Set(['divider', 'narrative', 'cta', 'image', 'pagebreak', 'control', 'back_cover']);

/** Text-bearing blocks where {{merge-fields}} are useful. */
const TEXT_BLOCKS = new Set(['header', 'narrative', 'cta', 'custom', 'cover', 'back_cover']);

/**
 * Which style controls each block actually honors — so the inspector only shows settings that
 * DO something. Blocks rendered via the shared `Section` wrapper support the full box
 * (border / pad / radius / align / hide-title); the custom-layout blocks (header, cover,
 * back-cover, advisory, cta, image, divider) only honor what their renderer reads.
 */
const SECTION_TYPES = ['kpi', 'sales_summary', 'goal', 'healthscore', 'security_shield', 'worklog_timeline', 'comments', 'control', 'custom', 'narrative', 'chart', 'table', 'funnel', 'geo_map'];
const supportsColor = (type: string): boolean => SECTION_TYPES.includes(type) || ['header', 'cover', 'back_cover', 'advisory', 'cta'].includes(type);
const supportsAlign = (type: string): boolean => SECTION_TYPES.includes(type) || ['cta', 'cover'].includes(type);
const supportsBox = (type: string): boolean => SECTION_TYPES.includes(type) || type === 'cta'; // border + pad + radius
const supportsHideTitle = (type: string): boolean => SECTION_TYPES.includes(type);

/**
 * The right-hand inspector: edits the currently-selected block. Two tabs — **Datos**
 * (binding, labels, drill-down, comparison) and **Estilo** (accordion sections with
 * premium controls: segmented buttons, swatches, toggles). The metric binding picker
 * reads from the connected sources' MetricCatalog — the "free metrics" UX (§11.3).
 */
export function Inspector({
    block,
    catalog,
    siteId,
    inheritedFilters = [],
    onChange,
}: {
    block: Block | null;
    catalog: CatalogEntry[];
    /** Preview site — lets the filter rows list the values the data actually holds. */
    siteId?: number | null;
    /** Report/page filters that reach this block, shown so their effect isn't invisible. */
    inheritedFilters?: DatasetFilter[];
    onChange: (block: Block) => void;
}): ReactElement {
    const [tab, setTab] = useState<'config' | 'style'>('config');
    const [pickerOpen, setPickerOpen] = useState(false);

    // Close the data picker when moving to another block, so it never reopens on a block
    // the user didn't ask about.
    useEffect(() => {
        setPickerOpen(false);
    }, [block?.id]);

    if (block === null) {
        return (
            <p className="ir-text-sm ir-text-muted-foreground">
                Selecciona un bloque del lienzo para editarlo, o añade uno desde la paleta.
            </p>
        );
    }

    const isData = DATA_BLOCKS.includes(block.type);
    const canCompare = block.type === 'kpi' || block.type === 'sales_summary';
    const titleKey = block.type === 'kpi' || block.type === 'goal' ? 'label' : 'title';
    const meta = BLOCK_META[block.type];
    const BlockIcon = meta.icon;

    // Dimensions available for the bound metric → the drill-down picker (CLAUDE.md §11.3).
    const boundEntry = block.binding != null ? catalog.find((entry) => entry.source === block.binding?.source && entry.metric === block.binding?.metric) : undefined;

    const dimensions = boundEntry?.dimensions ?? [];

    // Selectable fields for a `control` (filter) block, so the user picks visually instead
    // of typing a raw row-key. Built from every dimension the connected sources expose
    // (§11.3) plus the universal row keys tables/worklogs carry, and always the block's
    // current field so a legacy/custom value stays selected.
    const filterFields = Array.from(
        new Set<string>([
            'name',
            'category',
            ...catalog.flatMap((entry) => entry.dimensions ?? []),
            ...(typeof block.props?.field === 'string' && block.props.field !== '' ? [block.props.field] : []),
        ]),
    );

    const setProp = (key: string, value: string): void => onChange({ ...block, props: { ...block.props, [key]: value } });
    const setPropRaw = (key: string, value: unknown): void => onChange({ ...block, props: { ...block.props, [key]: value } });
    const setCompare = (on: boolean): void => {
        if (block.binding !== undefined && block.binding !== null) {
            onChange({ ...block, binding: { ...block.binding, compare: on ? 'prev_period' : undefined } });
        }
    };
    const setDimension = (value: string): void => {
        if (block.binding === undefined || block.binding === null) {
            return;
        }
        const binding = { ...block.binding };
        if (value === '') {
            delete binding.dimension;
        } else {
            binding.dimension = value;
        }
        onChange({ ...block, binding });
    };

    // ---- Dataset modeling (measure / breakdown / filters) — Looker-style block config ----
    const isDataset = boundEntry?.type === 'dataset';
    const measures = boundEntry?.measures ?? [];
    const dimLabel = (key: string): string => boundEntry?.dimension_labels?.[key] ?? key;
    const filters: DatasetFilter[] = block.binding?.filters ?? [];

    // When a single-value metric is bound and the SAME source also offers a modelable
    // dataset, point at it — that's the actionable version of "this can't be filtered",
    // offered where the choice is made instead of as a notice on every block.
    const datasetAlternative =
        !isDataset && block.binding != null
            ? catalog.find((entry) => entry.source === block.binding?.source && entry.type === 'dataset')
            : undefined;

    // Columns a table can be ordered by (the predefined order). Datasets expose their
    // dimensions + measures; simpler tables fall back to label/value.
    const tableSortColumns: string[] = isDataset
        ? [...dimensions, ...measures.map((measure) => measure.key)]
        : dimensions.length > 0
          ? [...dimensions, 'value']
          : ['label', 'value'];
    const sortLabel = (key: string): string => {
        const measure = measures.find((entry) => entry.key === key);
        if (measure !== undefined) return measure.label;
        if (key === 'value') return 'Valor';
        if (key === 'label') return 'Etiqueta';

        return dimLabel(key);
    };

    const patchBinding = (patch: Partial<BlockBinding>): void => {
        if (block.binding == null) {
            return;
        }
        const binding: BlockBinding = { ...block.binding, ...patch };
        // Drop emptied optional keys so the saved binding stays clean.
        if (binding.measure === undefined || binding.measure === '') delete binding.measure;
        if (binding.breakdown === undefined || binding.breakdown === '') delete binding.breakdown;
        if (binding.filters !== undefined && binding.filters.length === 0) delete binding.filters;
        onChange({ ...block, binding });
    };
    const setFilters = (next: DatasetFilter[]): void => patchBinding({ filters: next });
    const setStyle = (key: string, value: unknown): void => {
        const nextStyle: Record<string, unknown> = { ...block.style, [key]: value };
        if (value === undefined || value === '') {
            delete nextStyle[key];
        }
        onChange({ ...block, style: nextStyle });
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-3">
            {pickerOpen && (
                <MetricPicker
                    catalog={catalog}
                    value={block.binding != null ? { source: block.binding.source, metric: block.binding.metric } : null}
                    onPick={(entry) =>
                        onChange({
                            ...block,
                            // A different metric invalidates the old measure/breakdown/filters,
                            // which referenced dimensions that may not exist here.
                            binding: { source: entry.source, metric: entry.metric, compare: block.binding?.compare },
                        })
                    }
                    onClose={() => setPickerOpen(false)}
                />
            )}

            {/* Block header: type icon + name */}
            <div className="ir-flex ir-items-center ir-gap-2">
                <span className="ir-flex ir-size-7 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ir-bg-muted ir-text-muted-foreground">
                    <BlockIcon className="ir-size-4" />
                </span>
                <span className="ir-text-sm ir-font-semibold ir-text-foreground">{TYPE_LABELS[block.type] ?? block.type}</span>
            </div>

            {/* Datos / Estilo tabs */}
            <SegmentedControl
                value={tab}
                onChange={setTab}
                options={[
                    { value: 'config', label: 'Datos' },
                    { value: 'style', label: 'Estilo' },
                ]}
            />

            {tab === 'config' && (
                <div className="ir-flex ir-flex-col ir-gap-4 ir-pt-1">
                    {TEXT_BLOCKS.has(block.type) && (
                        <p className="ir-rounded-md ir-bg-muted ir-px-2 ir-py-1.5 ir-text-xs ir-text-muted-foreground">
                            Campos dinámicos: <code>{'{{client}}'}</code> <code>{'{{site}}'}</code> <code>{'{{period}}'}</code>{' '}
                            <code>{'{{score}}'}</code> <code>{'{{agency}}'}</code>
                        </p>
                    )}

                    {!NO_TITLE.has(block.type) && (
                        <Field label={titleKey === 'label' ? 'Etiqueta' : 'Título'}>
                            <Input value={str(block.props?.[titleKey])} onChange={(event) => setProp(titleKey, event.target.value)} />
                        </Field>
                    )}

                    {block.type === 'image' && (
                        <>
                            <Field label="Imagen">
                                <ImageField value={str(block.props?.url)} onChange={(url) => setProp('url', url)} />
                            </Field>
                            <Field label="Texto alternativo">
                                <Input value={str(block.props?.alt)} onChange={(event) => setProp('alt', event.target.value)} />
                            </Field>
                        </>
                    )}

                    {block.type === 'cover' && (
                        <>
                            <Field label="Subtítulo">
                                <Input value={str(block.props?.subtitle)} onChange={(event) => setProp('subtitle', event.target.value)} placeholder="Resumen del trabajo de este periodo…" />
                            </Field>
                            <Field label="Logo (tu empresa o el del cliente)" hint="Vacío = usa el logo de tu agencia. Sube uno o pega una URL.">
                                <ImageField value={str(block.props?.logoUrl)} onChange={(url) => setProp('logoUrl', url)} />
                            </Field>
                            <Toggle checked={block.props?.showScore !== false} onChange={(checked) => setPropRaw('showScore', checked)} label="Mostrar health score" />
                        </>
                    )}

                    {block.type === 'back_cover' && (
                        <>
                            <Field label="Titular">
                                <Input value={str(block.props?.headline)} onChange={(event) => setProp('headline', event.target.value)} placeholder="Tu plan de soporte está activo…" />
                            </Field>
                            <Field label="Texto (opcional)">
                                <Input value={str(block.props?.text)} onChange={(event) => setProp('text', event.target.value)} />
                            </Field>
                            <Field label="Contacto (opcional)">
                                <Input value={str(block.props?.contact)} onChange={(event) => setProp('contact', event.target.value)} placeholder="soporte@tuagencia.com · +34 …" />
                            </Field>
                            <Field label="Logo" hint="Vacío = usa el logo de tu agencia.">
                                <ImageField value={str(block.props?.logoUrl)} onChange={(url) => setProp('logoUrl', url)} />
                            </Field>
                        </>
                    )}

                    {block.type === 'cta' && (
                        <>
                            <Field label="Titular">
                                <Input value={str(block.props?.headline)} onChange={(event) => setProp('headline', event.target.value)} />
                            </Field>
                            <Field label="Texto (opcional)">
                                <Input value={str(block.props?.text)} onChange={(event) => setProp('text', event.target.value)} />
                            </Field>
                            <div className="ir-grid ir-grid-cols-2 ir-gap-3">
                                <Field label="Botón">
                                    <Input value={str(block.props?.buttonLabel)} onChange={(event) => setProp('buttonLabel', event.target.value)} placeholder="Contactar" />
                                </Field>
                                <Field label="Enlace del botón">
                                    <Input value={str(block.props?.buttonUrl)} onChange={(event) => setProp('buttonUrl', event.target.value)} placeholder="https://…" />
                                </Field>
                            </div>
                        </>
                    )}

                    {isData && (
                        <Field label="Dato" hint={boundEntry?.type === 'dataset' ? 'Modelable: se puede filtrar y desglosar.' : undefined}>
                            <button
                                type="button"
                                onClick={() => setPickerOpen(true)}
                                className="ir-flex ir-w-full ir-items-center ir-gap-2.5 ir-rounded-md ir-border ir-bg-background ir-px-2.5 ir-py-2 ir-text-left ir-transition hover:ir-border-primary/50 hover:ir-bg-muted/50"
                            >
                                <span className="ir-flex ir-size-7 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ir-bg-muted ir-text-muted-foreground">
                                    <Database className="ir-size-3.5" />
                                </span>
                                <span className="ir-min-w-0 ir-flex-1">
                                    {block.binding != null ? (
                                        <>
                                            <span className="ir-block ir-truncate ir-text-sm ir-font-medium">
                                                {boundEntry?.label ?? block.binding.metric}
                                            </span>
                                            <span className="ir-block ir-truncate ir-text-[11px] ir-text-muted-foreground">
                                                {sourceLabel(block.binding.source)}
                                            </span>
                                        </>
                                    ) : (
                                        <span className="ir-block ir-text-sm ir-text-muted-foreground">Elegir un dato…</span>
                                    )}
                                </span>
                                <ChevronRight className="ir-size-4 ir-shrink-0 ir-text-muted-foreground" />
                            </button>
                            {datasetAlternative !== undefined && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        onChange({
                                            ...block,
                                            binding: {
                                                source: datasetAlternative.source,
                                                metric: datasetAlternative.metric,
                                                compare: block.binding?.compare,
                                            },
                                        })
                                    }
                                    className="ir-mt-1.5 ir-rounded-md ir-bg-primary/5 ir-px-2 ir-py-1.5 ir-text-left ir-text-[11px] ir-text-primary ir-transition hover:ir-bg-primary/10"
                                >
                                    ¿Filtrar por campaña, país…? Cambia a «{datasetAlternative.label}»
                                </button>
                            )}
                            {block.binding != null && (
                                <button
                                    type="button"
                                    onClick={() => onChange({ ...block, binding: null })}
                                    className="ir-mt-1 ir-self-start ir-text-[11px] ir-text-muted-foreground ir-underline hover:ir-text-foreground"
                                >
                                    Quitar el dato
                                </button>
                            )}
                        </Field>
                    )}

                    {isData && !isDataset && dimensions.length > 0 && (
                        <Field label="Desglose (dimensión)">
                            <Select value={str(block.binding?.dimension)} onChange={(event) => setDimension(event.target.value)}>
                                <option value="">Sin desglose</option>
                                {dimensions.map((dimension) => (
                                    <option key={dimension} value={dimension}>
                                        {dimLabel(dimension)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    )}

                    {isDataset && block.binding != null && (
                        <div className="ir-flex ir-flex-col ir-gap-3 ir-rounded-lg ir-border ir-bg-muted/30 ir-p-3">
                            <Field label="Medida">
                                <Select value={str(block.binding?.measure)} onChange={(event) => patchBinding({ measure: event.target.value })}>
                                    <option value="">Elige una medida…</option>
                                    {measures.map((measure) => (
                                        <option key={measure.key} value={measure.key}>
                                            {measure.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>

                            <Field label="Desglosar por">
                                <Select value={str(block.binding?.breakdown)} onChange={(event) => patchBinding({ breakdown: event.target.value })}>
                                    <option value="">Sin desglose (total)</option>
                                    {dimensions.map((dimension) => (
                                        <option key={dimension} value={dimension}>
                                            {dimLabel(dimension)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                    )}

                    {isDataset && block.binding != null && (
                        <FiltersPanel
                            filters={filters}
                            inherited={inheritedFilters}
                            dimensions={dimensions}
                            labelOf={dimLabel}
                            siteId={siteId ?? null}
                            source={block.binding.source}
                            metric={block.binding.metric}
                            onChange={setFilters}
                        />
                    )}

                    {canCompare && block.binding != null && (
                        <Field label="Comparación">
                            <SegmentedControl
                                value={block.binding.compare === 'prev_period' ? 'prev' : 'none'}
                                onChange={(value) => setCompare(value === 'prev')}
                                options={[
                                    { value: 'none', label: 'Solo valor' },
                                    { value: 'prev', label: 'vs. anterior' },
                                ]}
                            />
                        </Field>
                    )}

                    {block.type === 'chart' && (
                        <Field label="Tipo de gráfico">
                            <div className="ir-grid ir-grid-cols-3 ir-gap-2">
                                {CHART_TYPES.map(({ type, label, Icon }) => {
                                    const active = (str(block.props?.chartType) || 'line') === type;

                                    return (
                                        <button
                                            key={type}
                                            type="button"
                                            title={label}
                                            onClick={() => setProp('chartType', type)}
                                            className={cn(
                                                'ir-flex ir-flex-col ir-items-center ir-gap-1 ir-rounded-md ir-border ir-p-2 ir-text-[11px] ir-transition',
                                                active ? 'ir-border-primary ir-bg-primary/5 ir-text-foreground' : 'ir-text-muted-foreground hover:ir-border-primary/60',
                                            )}
                                        >
                                            <Icon className="ir-size-5" />
                                            {label}
                                        </button>
                                    );
                                })}
                            </div>
                        </Field>
                    )}

                    {block.type === 'geo_map' && (
                        <Field label="Visualización">
                            <Select value={str(block.props?.display) || 'auto'} onChange={(event) => setProp('display', event.target.value)}>
                                <option value="auto">Automática (mapa si son países + lista)</option>
                                <option value="map">Solo mapa</option>
                                <option value="list">Solo lista (ranking)</option>
                                <option value="both">Mapa y lista</option>
                            </Select>
                            <p className="ir-mt-1 ir-text-xs ir-text-muted-foreground">
                                El mapa (coroplético) solo aplica a datos por país; para ciudades o regiones usa la lista.
                            </p>
                        </Field>
                    )}

                    {block.type === 'control' && (
                        <>
                            <Field label="Etiqueta del filtro">
                                <Input value={str(block.props?.label)} onChange={(event) => setProp('label', event.target.value)} placeholder="Filtrar por…" />
                            </Field>
                            <Field label="Campo a filtrar">
                                <Select value={str(block.props?.field) || 'name'} onChange={(event) => setProp('field', event.target.value)}>
                                    {filterFields.map((field) => (
                                        <option key={field} value={field}>
                                            {field}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <p className="ir-text-xs ir-text-muted-foreground">
                                El filtro acota las filas de las tablas, gráficos y la línea de trabajo de esta página por el campo elegido.
                            </p>
                        </>
                    )}

                    {block.type === 'goal' && (
                        <>
                            <Field label="Meta / presupuesto (objetivo)">
                                <Input
                                    type="number"
                                    value={block.props?.target === undefined ? '' : String(block.props.target)}
                                    onChange={(event) => setProp('target', event.target.value)}
                                />
                            </Field>
                            <Field label="Modo">
                                <Select
                                    value={str(block.style?.goal_direction) === 'under' ? 'under' : 'over'}
                                    onChange={(event) => setStyle('goal_direction', event.target.value === 'under' ? 'under' : undefined)}
                                >
                                    <option value="over">Meta — más es mejor (verde al superar)</option>
                                    <option value="under">Presupuesto — menos es mejor (rojo al pasarse)</option>
                                </Select>
                            </Field>
                        </>
                    )}

                    {block.type === 'narrative' && (
                        <Field label="Texto">
                            <NarrativeEditor value={str(block.props?.text)} onChange={(html) => setProp('text', html)} />
                        </Field>
                    )}
                </div>
            )}

            {tab === 'style' && (
                <div className="-ir-mx-3 ir-border-t ir-border-border/60">
                    {(supportsBox(block.type) || supportsHideTitle(block.type) || block.type === 'chart' || block.type === 'table') && (
                        <Section title="Apariencia">
                            <div className="ir-flex ir-flex-col ir-gap-3">
                                {supportsBox(block.type) && (
                                    <Toggle checked={block.style?.border !== false} onChange={(on) => setStyle('border', on ? undefined : false)} label="Mostrar borde" />
                                )}
                                {supportsHideTitle(block.type) && (
                                    <Toggle checked={block.style?.hideTitle === true} onChange={(on) => setStyle('hideTitle', on ? true : undefined)} label="Ocultar título" />
                                )}
                                {block.type === 'chart' && (
                                    <Toggle checked={block.style?.legend === true} onChange={(on) => setStyle('legend', on ? true : undefined)} label="Mostrar leyenda" />
                                )}
                                {block.type === 'table' && (
                                    <Toggle checked={block.style?.bars === true} onChange={(on) => setStyle('bars', on ? true : undefined)} label="Barras en la columna de valor" />
                                )}
                            </div>
                        </Section>
                    )}

                    {block.type === 'table' && (
                        <Section title="Tabla">
                            <div className="ir-flex ir-flex-col ir-gap-3">
                                <Field label="Filas por página (antes de paginar)">
                                    <Input
                                        type="number"
                                        min={1}
                                        value={block.style?.rows_per_page === undefined ? '' : String(block.style.rows_per_page)}
                                        placeholder="10"
                                        onChange={(event) => {
                                            const n = Number(event.target.value);
                                            setStyle('rows_per_page', event.target.value === '' || Number.isNaN(n) || n <= 0 ? undefined : n);
                                        }}
                                    />
                                    <p className="ir-mt-1 ir-text-[11px] ir-text-muted-foreground">
                                        El buscador y la paginación aparecen solo si la tabla supera este número (def. 10).
                                    </p>
                                </Field>
                                <Field label="Orden predefinido">
                                    <Select
                                        value={str(block.style?.sort_col)}
                                        onChange={(event) => setStyle('sort_col', event.target.value === '' ? undefined : event.target.value)}
                                    >
                                        <option value="">Por defecto (sin ordenar)</option>
                                        {tableSortColumns.map((col) => (
                                            <option key={col} value={col}>
                                                {sortLabel(col)}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                {str(block.style?.sort_col) !== '' && (
                                    <SegmentedControl
                                        value={block.style?.sort_dir === 'asc' ? 'asc' : 'desc'}
                                        onChange={(value) => setStyle('sort_dir', value)}
                                        options={[
                                            { value: 'desc', label: 'Mayor → menor' },
                                            { value: 'asc', label: 'Menor → mayor' },
                                        ]}
                                    />
                                )}
                            </div>
                        </Section>
                    )}

                    {supportsColor(block.type) && (
                        block.type === 'cover' || block.type === 'back_cover' ? (
                            <Section title="Fondo">
                                <BackgroundControl style={block.style} setStyle={setStyle} />
                            </Section>
                        ) : (
                            <Section title="Color">
                                <div className="ir-flex ir-flex-col ir-gap-3">
                                    <Field label="Fondo">
                                        <ColorSwatch value={str(block.style?.bg)} onChange={(value) => setStyle('bg', value)} />
                                    </Field>
                                    <Field label="Texto">
                                        <ColorSwatch value={str(block.style?.color)} onChange={(value) => setStyle('color', value)} />
                                    </Field>
                                </div>
                            </Section>
                        )
                    )}

                    {(supportsAlign(block.type) || supportsBox(block.type)) && (
                        <Section title="Disposición">
                            <div className="ir-flex ir-flex-col ir-gap-3">
                                {supportsAlign(block.type) && (
                                    <Field label="Alineación">
                                        <SegmentedControl
                                            value={str(block.style?.align) || 'left'}
                                            onChange={(value) => setStyle('align', value)}
                                            options={[
                                                { value: 'left', icon: <AlignLeft className="ir-size-4" />, title: 'Izquierda' },
                                                { value: 'center', icon: <AlignCenter className="ir-size-4" />, title: 'Centro' },
                                                { value: 'right', icon: <AlignRight className="ir-size-4" />, title: 'Derecha' },
                                            ]}
                                        />
                                    </Field>
                                )}
                                {supportsBox(block.type) && (
                                    <>
                                        <Field label="Relleno">
                                            <SegmentedControl
                                                value={str(block.style?.pad) || 'md'}
                                                onChange={(value) => setStyle('pad', value)}
                                                options={[
                                                    { value: 'sm', label: 'Compacto' },
                                                    { value: 'md', label: 'Normal' },
                                                    { value: 'lg', label: 'Amplio' },
                                                ]}
                                            />
                                        </Field>
                                        <Field label="Esquinas">
                                            <SegmentedControl
                                                value={str(block.style?.radius) || 'md'}
                                                onChange={(value) => setStyle('radius', value)}
                                                options={[
                                                    { value: 'none', label: 'Rectas' },
                                                    { value: 'sm', label: 'Suaves' },
                                                    { value: 'md', label: 'Media' },
                                                    { value: 'lg', label: 'Máx' },
                                                ]}
                                            />
                                        </Field>
                                    </>
                                )}
                            </div>
                        </Section>
                    )}

                    {!supportsColor(block.type) && !supportsAlign(block.type) && !supportsBox(block.type) && !supportsHideTitle(block.type) && !isData && (
                        <div className="ir-px-3 ir-py-4">
                            <p className="ir-text-xs ir-text-muted-foreground">Este bloque no tiene ajustes de estilo.</p>
                        </div>
                    )}

                    {isData && (
                        <Section title="Formato de número">
                            <SegmentedControl
                                value={str(block.style?.format) || 'number'}
                                onChange={(value) => setStyle('format', value)}
                                options={[
                                    { value: 'number', label: '1,234' },
                                    { value: 'compact', label: '1.2K' },
                                    { value: 'percent', label: '95%' },
                                    { value: 'currency', label: '$' },
                                    { value: 'duration', label: '1 h 30 min' },
                                ]}
                            />
                        </Section>
                    )}
                </div>
            )}
        </div>
    );
}
