import { type ReactElement } from 'react';

import type { Block } from '@shared/blocks/types';

import { Field, Input } from '../components/ui';
import type { CatalogEntry } from '../types';
import { DATA_BLOCKS } from './blockFactory';
import { NarrativeEditor } from './NarrativeEditor';

function str(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

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
    image: 'Imagen',
    divider: 'Separador',
    custom: 'Personalizado',
};

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
    if (block === null) {
        return (
            <p className="ir-text-sm ir-text-muted-foreground">
                Selecciona un bloque del lienzo para editarlo, o añade uno desde la paleta.
            </p>
        );
    }

    const isData = DATA_BLOCKS.includes(block.type);
    const canCompare = block.type === 'kpi' || block.type === 'sales_summary';
    const titleKey = block.type === 'kpi' ? 'label' : 'title';
    const width = str(block.style?.width) || 'full';

    const setProp = (key: string, value: string): void => onChange({ ...block, props: { ...block.props, [key]: value } });
    const setWidth = (value: string): void => onChange({ ...block, style: { ...block.style, width: value } });
    const setCompare = (on: boolean): void => {
        if (block.binding !== undefined && block.binding !== null) {
            onChange({ ...block, binding: { ...block.binding, compare: on ? 'prev_period' : undefined } });
        }
    };
    const setStyle = (key: string, value: unknown): void => {
        const nextStyle: Record<string, unknown> = { ...block.style, [key]: value };
        if (value === undefined || value === '') {
            delete nextStyle[key];
        }
        onChange({ ...block, style: nextStyle });
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-4">
            <p className="ir-text-xs ir-font-medium ir-uppercase ir-tracking-wide ir-text-muted-foreground">
                {TYPE_LABELS[block.type] ?? block.type}
            </p>

            {block.type !== 'divider' && block.type !== 'narrative' && (
                <Field label={titleKey === 'label' ? 'Etiqueta' : 'Título'}>
                    <Input value={str(block.props?.[titleKey])} onChange={(event) => setProp(titleKey, event.target.value)} />
                </Field>
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
                                        ? { source, metric, compare: block.binding?.compare }
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

            {canCompare && block.binding !== undefined && block.binding !== null && (
                <label className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-text-muted-foreground">
                    <input
                        type="checkbox"
                        checked={block.binding.compare === 'prev_period'}
                        onChange={(event) => setCompare(event.target.checked)}
                    />
                    Comparar vs periodo anterior
                </label>
            )}

            {block.type === 'chart' && (
                <>
                    <Field label="Tipo de gráfico">
                        <select className={selectClass} value={str(block.props?.chartType) || 'line'} onChange={(event) => setProp('chartType', event.target.value)}>
                            <option value="line">Línea</option>
                            <option value="bar">Barras</option>
                            <option value="area">Área</option>
                            <option value="donut">Dona</option>
                            <option value="pie">Pastel</option>
                        </select>
                    </Field>
                    <label className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-text-muted-foreground">
                        <input type="checkbox" checked={block.style?.legend === true} onChange={(event) => setStyle('legend', event.target.checked ? true : undefined)} />
                        Mostrar leyenda
                    </label>
                </>
            )}

            {block.type === 'table' && (
                <label className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-text-muted-foreground">
                    <input type="checkbox" checked={block.style?.bars === true} onChange={(event) => setStyle('bars', event.target.checked ? true : undefined)} />
                    Barras en la columna de valor
                </label>
            )}

            {block.type === 'narrative' && (
                <Field label="Texto">
                    <NarrativeEditor value={str(block.props?.text)} onChange={(html) => setProp('text', html)} />
                </Field>
            )}

            {block.type !== 'divider' && (
                <Field label="Ancho">
                    <select className={selectClass} value={width} onChange={(event) => setWidth(event.target.value)}>
                        <option value="full">Completo</option>
                        <option value="half">Mitad</option>
                        <option value="third">Tercio</option>
                    </select>
                </Field>
            )}

            <div className="ir-mt-1 ir-border-t ir-pt-4">
                <p className="ir-mb-3 ir-text-xs ir-font-medium ir-uppercase ir-tracking-wide ir-text-muted-foreground">Estilo</p>
                <div className="ir-flex ir-flex-col ir-gap-3">
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
            </div>
        </div>
    );
}
