import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    AreaChart,
    BarChart3,
    BarChartHorizontal,
    LineChart,
    type LucideIcon,
    PieChart,
} from 'lucide-react';
import { type ReactElement, useState } from 'react';

import type { Block } from '@shared/blocks/types';
import { cn } from '@shared/lib/utils';

import { Field, Input } from '../components/ui';
import type { CatalogEntry } from '../types';
import { BLOCK_META } from './BlockPalette';
import { DATA_BLOCKS } from './blockFactory';
import { ColorSwatch, Section, SegmentedControl, Toggle } from './controls';
import { NarrativeEditor } from './NarrativeEditor';

function str(value: unknown): string {
    return typeof value === 'string' ? value : '';
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
};

/** Block types that manage their own content fields (no generic title field). */
const NO_TITLE = new Set(['divider', 'narrative', 'cta', 'image', 'pagebreak', 'control']);

/** Text-bearing blocks where {{merge-fields}} are useful. */
const TEXT_BLOCKS = new Set(['header', 'narrative', 'cta', 'custom']);

const selectClass = 'ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm';

/**
 * The right-hand inspector: edits the currently-selected block. Two tabs — **Datos**
 * (binding, labels, drill-down, comparison) and **Estilo** (accordion sections with
 * premium controls: segmented buttons, swatches, toggles). The metric binding picker
 * reads from the connected sources' MetricCatalog — the "free metrics" UX (§11.3).
 */
export function Inspector({
    block,
    catalog,
    onChange,
}: {
    block: Block | null;
    catalog: CatalogEntry[];
    onChange: (block: Block) => void;
}): ReactElement {
    const [tab, setTab] = useState<'config' | 'style'>('config');
    const [metricQuery, setMetricQuery] = useState('');

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

    // Filter the binding picker so large multi-source catalogs stay usable. The currently
    // bound metric is always kept in the list so it never vanishes while filtering.
    const metricFilter = metricQuery.trim().toLowerCase();
    const filteredCatalog =
        metricFilter === ''
            ? catalog
            : catalog.filter(
                  (entry) =>
                      (entry.source === block.binding?.source && entry.metric === block.binding?.metric) ||
                      `${entry.label} ${entry.source} ${entry.metric}`.toLowerCase().includes(metricFilter),
              );
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
    const setStyle = (key: string, value: unknown): void => {
        const nextStyle: Record<string, unknown> = { ...block.style, [key]: value };
        if (value === undefined || value === '') {
            delete nextStyle[key];
        }
        onChange({ ...block, style: nextStyle });
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-3">
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
                            <Field label="URL de la imagen">
                                <Input value={str(block.props?.url)} onChange={(event) => setProp('url', event.target.value)} placeholder="https://…" />
                            </Field>
                            <Field label="Texto alternativo">
                                <Input value={str(block.props?.alt)} onChange={(event) => setProp('alt', event.target.value)} />
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
                        <Field label="Métrica">
                            {block.binding != null && (
                                <span className="ir-mb-1.5 ir-inline-flex ir-items-center ir-gap-1 ir-rounded ir-bg-primary/10 ir-px-1.5 ir-py-0.5 ir-text-[11px] ir-font-medium ir-text-primary">
                                    {block.binding.source}
                                </span>
                            )}
                            {catalog.length > 6 && (
                                <Input
                                    className="ir-mb-2"
                                    value={metricQuery}
                                    onChange={(event) => setMetricQuery(event.target.value)}
                                    placeholder="Buscar métrica…"
                                />
                            )}
                            <select
                                className={selectClass}
                                value={block.binding ? `${block.binding.source}|${block.binding.metric}` : ''}
                                onChange={(event) => {
                                    const [source, metric] = event.target.value.split('|');
                                    onChange({
                                        ...block,
                                        binding:
                                            source !== undefined && metric !== undefined
                                                ? { source, metric, compare: block.binding?.compare, dimension: block.binding?.dimension }
                                                : null,
                                    });
                                }}
                            >
                                <option value="">Vincular métrica…</option>
                                {filteredCatalog.map((entry) => (
                                    <option key={entry.key} value={`${entry.source}|${entry.metric}`}>
                                        {entry.label} ({entry.source})
                                    </option>
                                ))}
                            </select>
                            {metricFilter !== '' && filteredCatalog.length === 0 && (
                                <p className="ir-mt-1 ir-text-xs ir-text-muted-foreground">Sin métricas que coincidan.</p>
                            )}
                        </Field>
                    )}

                    {isData && dimensions.length > 0 && (
                        <Field label="Desglose (dimensión)">
                            <select className={selectClass} value={str(block.binding?.dimension)} onChange={(event) => setDimension(event.target.value)}>
                                <option value="">Sin desglose</option>
                                {dimensions.map((dimension) => (
                                    <option key={dimension} value={dimension}>
                                        {dimension}
                                    </option>
                                ))}
                            </select>
                        </Field>
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

                    {block.type === 'control' && (
                        <>
                            <Field label="Etiqueta del filtro">
                                <Input value={str(block.props?.label)} onChange={(event) => setProp('label', event.target.value)} placeholder="Filtrar por…" />
                            </Field>
                            <Field label="Campo a filtrar">
                                <select className={selectClass} value={str(block.props?.field) || 'name'} onChange={(event) => setProp('field', event.target.value)}>
                                    {filterFields.map((field) => (
                                        <option key={field} value={field}>
                                            {field}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <p className="ir-text-xs ir-text-muted-foreground">
                                El filtro acota las filas de las tablas, gráficos y la línea de trabajo de esta página por el campo elegido.
                            </p>
                        </>
                    )}

                    {block.type === 'goal' && (
                        <Field label="Meta (objetivo)">
                            <Input
                                type="number"
                                value={block.props?.target === undefined ? '' : String(block.props.target)}
                                onChange={(event) => setProp('target', event.target.value)}
                            />
                        </Field>
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
                    <Section title="Apariencia">
                        <div className="ir-flex ir-flex-col ir-gap-3">
                            <Toggle checked={block.style?.border !== false} onChange={(on) => setStyle('border', on ? undefined : false)} label="Mostrar borde" />
                            {block.type !== 'divider' && block.type !== 'header' && (
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

                    <Section title="Disposición">
                        <div className="ir-flex ir-flex-col ir-gap-3">
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
                        </div>
                    </Section>

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
                                ]}
                            />
                        </Section>
                    )}
                </div>
            )}
        </div>
    );
}
