import { type ReactElement, useMemo, useState } from 'react';

import type { DatasetFilter } from '@shared/blocks/types';

import { Input } from '../components/ui';
import type { CatalogEntry, PageFilters } from '../types';

const selectClass = 'ir-rounded-md ir-border ir-bg-background ir-px-2 ir-py-1.5 ir-text-xs';

/**
 * Page/dashboard filter editor (CLAUDE.md §10 dashboards — design-time filters). The agency
 * bakes dimension filters into a page ("solo Colombia", "tráfico de Facebook"); they cascade
 * to every dataset block on that scope, and a block's own filters still override per dimension.
 * Two scopes: the whole report (`all`) or just the current page (its index).
 */
export function PageFiltersPanel({
    catalog,
    currentPage,
    filters,
    onChange,
}: {
    catalog: CatalogEntry[];
    currentPage: number;
    filters: PageFilters;
    onChange: (next: PageFilters) => void;
}): ReactElement {
    const [scope, setScope] = useState<'all' | 'page'>('all');
    const scopeKey = scope === 'all' ? 'all' : String(currentPage);

    // All dataset dimensions across the connected sources, with a human label each.
    const { dimensions, labelOf } = useMemo(() => {
        const labels: Record<string, string> = {};
        const set = new Set<string>();
        for (const entry of catalog) {
            if (entry.type !== 'dataset') {
                continue;
            }
            for (const dim of entry.dimensions) {
                set.add(dim);
                labels[dim] = entry.dimension_labels?.[dim] ?? labels[dim] ?? dim;
            }
        }

        return { dimensions: [...set], labelOf: (key: string): string => labels[key] ?? key };
    }, [catalog]);

    const current = filters[scopeKey] ?? [];

    const write = (next: DatasetFilter[]): void => {
        const copy: PageFilters = { ...filters };
        if (next.length === 0) {
            delete copy[scopeKey];
        } else {
            copy[scopeKey] = next;
        }
        onChange(copy);
    };

    const add = (): void => write([...current, { dimension: dimensions[0] ?? '', op: 'is', value: '' }]);
    const update = (index: number, patch: Partial<DatasetFilter>): void =>
        write(current.map((filter, i) => (i === index ? { ...filter, ...patch } : filter)));
    const remove = (index: number): void => write(current.filter((_, i) => i !== index));

    const allCount = (filters.all ?? []).length;
    const pageCount = (filters[String(currentPage)] ?? []).length;

    if (dimensions.length === 0) {
        return (
            <p className="ir-text-[11px] ir-text-muted-foreground">
                Conecta una fuente con datasets (GA4, Search Console, WooCommerce) y sincroniza para poder filtrar por dimensiones.
            </p>
        );
    }

    return (
        <div className="ir-flex ir-flex-col ir-gap-3">
            <div className="ir-flex ir-gap-1 ir-rounded-md ir-bg-muted ir-p-0.5 ir-text-xs">
                <button
                    type="button"
                    onClick={() => setScope('all')}
                    className={`ir-flex-1 ir-rounded ir-px-2 ir-py-1 ir-transition ${scope === 'all' ? 'ir-bg-background ir-font-medium ir-shadow-sm' : 'ir-text-muted-foreground'}`}
                >
                    Todo el informe{allCount > 0 ? ` (${allCount})` : ''}
                </button>
                <button
                    type="button"
                    onClick={() => setScope('page')}
                    className={`ir-flex-1 ir-rounded ir-px-2 ir-py-1 ir-transition ${scope === 'page' ? 'ir-bg-background ir-font-medium ir-shadow-sm' : 'ir-text-muted-foreground'}`}
                >
                    Esta página{pageCount > 0 ? ` (${pageCount})` : ''}
                </button>
            </div>

            {current.map((filter, index) => (
                <div key={index} className="ir-flex ir-items-center ir-gap-1">
                    <select className={`${selectClass} ir-min-w-0 ir-flex-1`} value={filter.dimension} onChange={(event) => update(index, { dimension: event.target.value })}>
                        {dimensions.map((dimension) => (
                            <option key={dimension} value={dimension}>
                                {labelOf(dimension)}
                            </option>
                        ))}
                    </select>
                    <select className={`${selectClass} ir-shrink-0`} value={filter.op} onChange={(event) => update(index, { op: event.target.value })}>
                        <option value="is">es</option>
                        <option value="is_not">no es</option>
                        <option value="contains">contiene</option>
                        <option value="not_contains">no contiene</option>
                    </select>
                    <Input className="ir-h-8 ir-min-w-0 ir-flex-1 ir-text-xs" value={filter.value} placeholder="valor" onChange={(event) => update(index, { value: event.target.value })} />
                    <button
                        type="button"
                        title="Quitar filtro"
                        onClick={() => remove(index)}
                        className="ir-shrink-0 ir-rounded-md ir-p-1 ir-text-muted-foreground hover:ir-bg-danger/10 hover:ir-text-danger"
                    >
                        ✕
                    </button>
                </div>
            ))}

            <button type="button" onClick={add} className="ir-self-start ir-rounded-md ir-border ir-border-dashed ir-px-2 ir-py-1 ir-text-xs ir-text-muted-foreground hover:ir-border-primary/60 hover:ir-text-foreground">
                + Filtro
            </button>

            <p className="ir-text-[11px] ir-text-muted-foreground">
                {scope === 'all' ? 'Se aplica a todas las páginas.' : 'Solo afecta a esta página.'} Los filtros del bloque mandan sobre estos. El cliente solo cambia la fecha.
            </p>
        </div>
    );
}
