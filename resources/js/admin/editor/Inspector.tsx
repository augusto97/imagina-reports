import { AreaChart, BarChart3, BarChartHorizontal, LineChart, PieChart, type LucideIcon } from 'lucide-react';
import { type ReactElement, useState } from 'react';

import type { Block } from '@shared/blocks/types';
import { cn } from '@shared/lib/utils';

import { Field, Input } from '../components/ui';
import type { CatalogEntry } from '../types';
import { DATA_BLOCKS } from './blockFactory';
import { NarrativeEditor } from './NarrativeEditor';

function str(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

/** Visual chart-type gallery (CLAUDE.md §11.3) — every option renders for single-series data. */
const CHART_TYPES: { type: string; label: string; Icon: LucideIcon }[] = [
    { type: 'line', label: 'Línea', Icon: LineChart },
    { type: 'bar', label: 'Barras', Icon: BarChart3 },
    { type: 'hbar', label: 'Barras horizontales', Icon: BarChartHorizontal },
    { type: 'area', label: 'Área', Icon: AreaChart },
    { type: 'donut', label: 'Dona', Icon: PieChart },
    { type: 'pie', label: 'Pastel', Icon: PieChart },
];

/** A colour picker with a "clear" action so a block can inherit (no override). */
function ColorField({ label, value, onChange }: { label: string; value: string; onChange: (value: string | undefined) => void }): ReactElement {
    return (
        <Field label={label}>
            <div className="ir-flex ir-items-center ir-gap-2">
                <input
                    type="color"
                    value={value === '' ? '#ffffff' : value}
                    onChange={(event) => onChange(event.target.value)}
                    className="ir-h-8 ir-w-10 ir-rounded ir-border"
                />
                {value !== '' && (
                    <button type="button" className="ir-text-xs ir-text-muted-foreground hover:ir-text-foreground" onClick={() => onChange(undefined)}>
                        quitar
                    </button>
                )}
            </div>
        </Field>
    );
}

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
    divider: 'Separador',
    pagebreak: 'Salto de página',
    custom: 'Personalizado',
};

/** Block types that manage their own content fields (no generic title field). */
const NO_TITLE = new Set(['divider', 'narrative', 'cta', 'image', 'pagebreak']);

/** Text-bearing blocks where {{merge-fields}} are useful. */
const TEXT_BLOCKS = new Set(['header', 'narrative', 'cta', 'custom']);

const selectClass = 'ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm';

/**
 * The right-hand inspector: edits the currently-selected block (binding, labels,
 * comparison, width…). The metric binding picker reads from the connected sources'
 * MetricCatalog — the "free metrics" UX (CLAUDE.md §11.3).
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

    // Dimensions available for the bound metric → the drill-down picker (CLAUDE.md §11.3).
    const boundEntry = block.binding != null ? catalog.find((entry) => entry.source === block.binding?.source && entry.metric === block.binding?.metric) : undefined;
    const dimensions = boundEntry?.dimensions ?? [];

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

    const tabBtn = (id: 'config' | 'style', label: string): ReactElement => (
        <button
            type="button"
            onClick={() => setTab(id)}
            className={cn(
                'ir-flex-1 ir-rounded-md ir-px-3 ir-py-1.5 ir-text-sm ir-transition',
                tab === id ? 'ir-bg-card ir-font-medium ir-shadow-sm' : 'ir-text-muted-foreground hover:ir-text-foreground',
            )}
        >
            {label}
        </button>
    );

    return (
        <div className="ir-flex ir-flex-col ir-gap-4">
            <p className="ir-text-xs ir-font-medium ir-uppercase ir-tracking-wide ir-text-muted-foreground">
                {TYPE_LABELS[block.type] ?? block.type}
            </p>

            {/* Configuración / Estilo tabs (Looker-style) */}
            <div className="ir-flex ir-gap-1 ir-rounded-lg ir-bg-muted ir-p-1">
                {tabBtn('config', 'Configuración')}
                {tabBtn('style', 'Estilo')}
            </div>

            {tab === 'config' && (
                <div className="ir-flex ir-flex-col ir-gap-4">
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
                                {catalog.map((entry) => (
                                    <option key={entry.key} value={`${entry.source}|${entry.metric}`}>
                                        {entry.label} ({entry.source})
                                    </option>
                                ))}
                            </select>
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

                    {canCompare && block.binding !== undefined && block.binding !== null && (
                        <label className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-text-muted-foreground">
                            <input type="checkbox" checked={block.binding.compare === 'prev_period'} onChange={(event) => setCompare(event.target.checked)} />
                            Comparar vs periodo anterior
                        </label>
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
                <div className="ir-flex ir-flex-col ir-gap-3">
                    {block.type === 'chart' && (
                        <label className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-text-muted-foreground">
                            <input type="checkbox" checked={block.style?.legend === true} onChange={(event) => setStyle('legend', event.target.checked ? true : undefined)} />
                            Mostrar leyenda
                        </label>
                    )}
                    {block.type === 'table' && (
                        <label className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-text-muted-foreground">
                            <input type="checkbox" checked={block.style?.bars === true} onChange={(event) => setStyle('bars', event.target.checked ? true : undefined)} />
                            Barras en la columna de valor
                        </label>
                    )}
                    {isData && (
                        <Field label="Formato de número">
                            <select className={selectClass} value={str(block.style?.format) || 'number'} onChange={(event) => setStyle('format', event.target.value)}>
                                <option value="number">1,234</option>
                                <option value="compact">1.2 K</option>
                                <option value="percent">95 %</option>
                                <option value="currency">$ 1,234</option>
                            </select>
                        </Field>
                    )}
                    <div className="ir-grid ir-grid-cols-2 ir-gap-3">
                        <ColorField label="Fondo" value={str(block.style?.bg)} onChange={(value) => setStyle('bg', value)} />
                        <ColorField label="Texto" value={str(block.style?.color)} onChange={(value) => setStyle('color', value)} />
                    </div>
                    <div className="ir-grid ir-grid-cols-2 ir-gap-3">
                        <Field label="Relleno">
                            <select className={selectClass} value={str(block.style?.pad) || 'md'} onChange={(event) => setStyle('pad', event.target.value)}>
                                <option value="sm">Compacto</option>
                                <option value="md">Normal</option>
                                <option value="lg">Amplio</option>
                            </select>
                        </Field>
                        <Field label="Esquinas">
                            <select className={selectClass} value={str(block.style?.radius) || 'md'} onChange={(event) => setStyle('radius', event.target.value)}>
                                <option value="none">Rectas</option>
                                <option value="sm">Suaves</option>
                                <option value="md">Redondeadas</option>
                                <option value="lg">Muy redondeadas</option>
                            </select>
                        </Field>
                    </div>
                    <Field label="Alineación">
                        <select className={selectClass} value={str(block.style?.align) || 'left'} onChange={(event) => setStyle('align', event.target.value)}>
                            <option value="left">Izquierda</option>
                            <option value="center">Centro</option>
                            <option value="right">Derecha</option>
                        </select>
                    </Field>
                    <label className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-text-muted-foreground">
                        <input type="checkbox" checked={block.style?.border !== false} onChange={(event) => setStyle('border', event.target.checked ? undefined : false)} />
                        Mostrar borde
                    </label>
                    {block.type !== 'divider' && block.type !== 'header' && (
                        <label className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-text-muted-foreground">
                            <input type="checkbox" checked={block.style?.hideTitle === true} onChange={(event) => setStyle('hideTitle', event.target.checked ? true : undefined)} />
                            Ocultar título
                        </label>
                    )}
                </div>
            )}
        </div>
    );
}
