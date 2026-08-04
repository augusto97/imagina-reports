import { Filter, Plus, X } from 'lucide-react';
import { type ReactElement } from 'react';

import type { DatasetFilter } from '@shared/blocks/types';

import { useDimensionValues } from '../api';
import { Button, Input, Select } from '../components/ui';

const OPS: { value: string; label: string }[] = [
    { value: 'is', label: 'es' },
    { value: 'is_not', label: 'no es' },
    { value: 'contains', label: 'contiene' },
    { value: 'not_contains', label: 'no contiene' },
];

/**
 * One filter row. The value is a picker over the values the dimension ACTUALLY holds in the
 * latest snapshot — the old free-text box meant a single typo produced an empty block with
 * no explanation, which was the most common "I filtered and nothing showed" report. Free
 * text stays available for `contains`, and as a fallback when there is nothing to list yet.
 */
function FilterRow({
    filter,
    dimensions,
    labelOf,
    siteId,
    source,
    metric,
    onChange,
    onRemove,
}: {
    filter: DatasetFilter;
    dimensions: string[];
    labelOf: (key: string) => string;
    siteId: number | null;
    source: string;
    metric: string;
    onChange: (patch: Partial<DatasetFilter>) => void;
    onRemove: () => void;
}): ReactElement {
    const { data } = useDimensionValues(siteId, source, metric, filter.dimension);
    const values = data?.values ?? [];
    const freeText = filter.op === 'contains' || filter.op === 'not_contains';
    // An unlisted value (typed before, or from another period) must stay selectable rather
    // than silently resetting to the first option.
    const unknown = filter.value !== '' && !values.includes(filter.value);

    return (
        <div className="ir-flex ir-flex-col ir-gap-1.5 ir-rounded-md ir-border ir-bg-background ir-p-2">
            <div className="ir-flex ir-items-center ir-gap-1.5">
                <Select
                    className="ir-h-8 ir-min-w-0 ir-flex-1 ir-text-xs"
                    value={filter.dimension}
                    onChange={(event) => onChange({ dimension: event.target.value, value: '' })}
                >
                    {dimensions.map((dimension) => (
                        <option key={dimension} value={dimension}>
                            {labelOf(dimension)}
                        </option>
                    ))}
                </Select>
                <Select
                    className="ir-h-8 ir-w-28 ir-shrink-0 ir-text-xs"
                    value={filter.op}
                    onChange={(event) => onChange({ op: event.target.value })}
                >
                    {OPS.map((op) => (
                        <option key={op.value} value={op.value}>
                            {op.label}
                        </option>
                    ))}
                </Select>
                <button
                    type="button"
                    title="Quitar filtro"
                    onClick={onRemove}
                    className="ir-shrink-0 ir-rounded-md ir-p-1.5 ir-text-muted-foreground ir-transition hover:ir-bg-danger/10 hover:ir-text-danger"
                >
                    <X className="ir-size-3.5" />
                </button>
            </div>

            {freeText || values.length === 0 ? (
                <Input
                    className="ir-h-8 ir-text-xs"
                    value={filter.value}
                    placeholder={values.length === 0 ? 'Escribe el valor (aún sin datos para listar)' : 'Escribe parte del valor'}
                    onChange={(event) => onChange({ value: event.target.value })}
                />
            ) : (
                <Select className="ir-h-8 ir-text-xs" value={filter.value} onChange={(event) => onChange({ value: event.target.value })}>
                    <option value="">Elige un valor…</option>
                    {unknown && <option value={filter.value}>{filter.value} (no está en el último periodo)</option>}
                    {values.map((option) => (
                        <option key={option} value={option}>
                            {option}
                        </option>
                    ))}
                </Select>
            )}
        </div>
    );
}

/**
 * The block's filters, plus what it inherits from the page/report.
 *
 * Filtering used to live in two unrelated places — page filters in a collapsed accordion on
 * the LEFT, block filters inside a dashed "Modelado de datos" box on the RIGHT that only
 * existed for dataset metrics — so people could not find them, and when a block was being
 * cut by a page filter nothing on screen said so. Both now show here, together, with the
 * inherited ones visible and the override rule stated where it applies.
 */
export function FiltersPanel({
    filters,
    inherited,
    dimensions,
    labelOf,
    siteId,
    source,
    metric,
    supported,
    onChange,
}: {
    filters: DatasetFilter[];
    inherited: DatasetFilter[];
    dimensions: string[];
    labelOf: (key: string) => string;
    siteId: number | null;
    source: string;
    metric: string;
    /** False when the bound metric isn't a dataset — the reason filters can't apply. */
    supported: boolean;
    onChange: (next: DatasetFilter[]) => void;
}): ReactElement {
    const overridden = new Set(filters.map((filter) => filter.dimension));

    const add = (): void => onChange([...filters, { dimension: dimensions[0] ?? '', op: 'is', value: '' }]);
    const update = (index: number, patch: Partial<DatasetFilter>): void =>
        onChange(filters.map((filter, i) => (i === index ? { ...filter, ...patch } : filter)));
    const remove = (index: number): void => onChange(filters.filter((_, i) => i !== index));

    return (
        <div className="ir-flex ir-flex-col ir-gap-2">
            <div className="ir-flex ir-items-center ir-justify-between">
                <span className="ir-flex ir-items-center ir-gap-1.5 ir-text-xs ir-font-medium ir-text-foreground/80">
                    <Filter className="ir-size-3.5 ir-text-muted-foreground" />
                    Filtros
                </span>
                {supported && (
                    <Button variant="ghost" size="sm" onClick={add} disabled={dimensions.length === 0}>
                        <Plus className="ir-size-3.5" />
                        Añadir
                    </Button>
                )}
            </div>

            {/* Say WHY there are no filters instead of showing nothing at all. */}
            {!supported && (
                <p className="ir-rounded-md ir-bg-muted/60 ir-px-2.5 ir-py-2 ir-text-[11px] ir-text-muted-foreground">
                    Este dato es un valor único, así que no se puede filtrar. Para recortar por campaña, país, producto…
                    vincula un dato <strong>modelable</strong> (aparecen marcados así en el selector).
                </p>
            )}

            {inherited.length > 0 && (
                <div className="ir-flex ir-flex-col ir-gap-1 ir-rounded-md ir-bg-muted/50 ir-p-2">
                    <p className="ir-text-[10px] ir-font-semibold ir-uppercase ir-tracking-wider ir-text-muted-foreground/70">
                        Heredado del informe
                    </p>
                    {inherited.map((filter, index) => {
                        const isOverridden = overridden.has(filter.dimension);

                        return (
                            <p
                                key={index}
                                className={`ir-text-[11px] ${isOverridden ? 'ir-text-muted-foreground/50 ir-line-through' : 'ir-text-muted-foreground'}`}
                            >
                                {labelOf(filter.dimension)} {OPS.find((op) => op.value === filter.op)?.label ?? filter.op}{' '}
                                <strong>{filter.value}</strong>
                                {isOverridden && ' — anulado aquí'}
                            </p>
                        );
                    })}
                </div>
            )}

            {supported &&
                filters.map((filter, index) => (
                    <FilterRow
                        key={index}
                        filter={filter}
                        dimensions={dimensions}
                        labelOf={labelOf}
                        siteId={siteId}
                        source={source}
                        metric={metric}
                        onChange={(patch) => update(index, patch)}
                        onRemove={() => remove(index)}
                    />
                ))}

            {supported && filters.length === 0 && inherited.length === 0 && (
                <p className="ir-text-[11px] ir-text-muted-foreground">Sin filtros: el bloque muestra todo.</p>
            )}
        </div>
    );
}
