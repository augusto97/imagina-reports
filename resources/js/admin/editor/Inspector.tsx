import { type ReactElement } from 'react';

import type { Block } from '@shared/blocks/types';

import { Field, Input } from '../components/ui';
import type { CatalogEntry } from '../types';
import { DATA_BLOCKS } from './blockFactory';
import { NarrativeEditor } from './NarrativeEditor';

function str(value: unknown): string {
    return typeof value === 'string' ? value : '';
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
                <Field label="Tipo de gráfico">
                    <select className={selectClass} value={str(block.props?.chartType) || 'line'} onChange={(event) => setProp('chartType', event.target.value)}>
                        <option value="line">Línea</option>
                        <option value="bar">Barras</option>
                        <option value="area">Área</option>
                    </select>
                </Field>
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
        </div>
    );
}
