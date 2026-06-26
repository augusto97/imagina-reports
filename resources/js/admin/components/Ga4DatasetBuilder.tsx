import { type ReactElement, useMemo, useState } from 'react';

import { type Ga4DatasetSpec, useDeleteGa4Dataset, useGa4Metadata, useSaveGa4Dataset, useTestGa4Dataset } from '../api';
import { Button, Card, Field, Input, Modal } from './ui';

/** Sanitize an arbitrary string into a stable [a-z0-9_] field/dataset key. */
function slug(value: string): string {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

function castForType(type: string): 'int' | 'float' {
    return type === 'TYPE_INTEGER' ? 'int' : 'float';
}

function unitForType(type: string): string | null {
    if (type === 'TYPE_CURRENCY') return 'currency';
    if (type === 'TYPE_SECONDS' || type === 'TYPE_MINUTES' || type === 'TYPE_HOURS') return 'seconds';
    return null;
}

/** A searchable checkbox list (metrics or dimensions) for the builder. */
function PickList({
    items,
    selected,
    onToggle,
    search,
    onSearch,
    placeholder,
}: {
    items: { api: string; label: string; custom: boolean }[];
    selected: Set<string>;
    onToggle: (api: string) => void;
    search: string;
    onSearch: (value: string) => void;
    placeholder: string;
}): ReactElement {
    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        const matches = q === '' ? items : items.filter((item) => `${item.label} ${item.api}`.toLowerCase().includes(q));
        // Keep selected items visible at the top even when filtered out.
        return [...matches].sort((a, b) => Number(selected.has(b.api)) - Number(selected.has(a.api)));
    }, [items, search, selected]);

    return (
        <div className="ir-flex ir-flex-col ir-gap-2">
            <Input value={search} placeholder={placeholder} onChange={(event) => onSearch(event.target.value)} />
            <div className="ir-max-h-44 ir-overflow-y-auto ir-rounded-md ir-border ir-bg-background">
                {filtered.slice(0, 200).map((item) => (
                    <label key={item.api} className="ir-flex ir-cursor-pointer ir-items-center ir-gap-2 ir-border-b ir-px-2.5 ir-py-1.5 ir-text-sm last:ir-border-b-0 hover:ir-bg-muted/50">
                        <input type="checkbox" checked={selected.has(item.api)} onChange={() => onToggle(item.api)} className="ir-size-3.5" />
                        <span className="ir-min-w-0 ir-flex-1 ir-truncate">{item.label}</span>
                        {item.custom && <span className="ir-rounded-full ir-bg-accent/10 ir-px-1.5 ir-text-[10px] ir-text-accent">custom</span>}
                        <span className="ir-shrink-0 ir-font-mono ir-text-[10px] ir-text-muted-foreground">{item.api}</span>
                    </label>
                ))}
                {filtered.length === 0 && <p className="ir-px-2.5 ir-py-3 ir-text-xs ir-text-muted-foreground">Sin resultados.</p>}
            </div>
        </div>
    );
}

/**
 * Self-serve GA4 metric/dataset builder (CLAUDE.md §10.6/A.3): compose a runReport from
 * the property's real metrics/dimensions, "Probar" it, and register it as a dataset — no
 * per-metric code. Stays aggregate-at-source/top-N (the backend caps it).
 */
export function Ga4DatasetBuilder({
    dataSourceId,
    initialDatasets,
    onClose,
}: {
    dataSourceId: number;
    initialDatasets: Ga4DatasetSpec[];
    onClose: () => void;
}): ReactElement {
    const metaQuery = useGa4Metadata(dataSourceId);
    const testMut = useTestGa4Dataset(dataSourceId);
    const saveMut = useSaveGa4Dataset(dataSourceId);
    const deleteMut = useDeleteGa4Dataset(dataSourceId);

    const [datasets, setDatasets] = useState<Ga4DatasetSpec[]>(initialDatasets);
    const [label, setLabel] = useState('');
    const [measures, setMeasures] = useState<Set<string>>(new Set());
    const [dimensions, setDimensions] = useState<Set<string>>(new Set());
    const [limit, setLimit] = useState(250);
    const [metricSearch, setMetricSearch] = useState('');
    const [dimSearch, setDimSearch] = useState('');
    const [sample, setSample] = useState<{ ok: boolean; rows: Record<string, unknown>[]; error: string | null } | null>(null);

    const meta = metaQuery.data;

    const toggle = (set: Set<string>, setter: (next: Set<string>) => void, api: string): void => {
        const next = new Set(set);
        if (next.has(api)) {
            next.delete(api);
        } else {
            next.add(api);
        }
        setter(next);
        setSample(null);
    };

    const buildSpec = (): Ga4DatasetSpec | null => {
        const key = slug(label);
        if (key === '' || meta === undefined || measures.size === 0 || dimensions.size === 0) {
            return null;
        }
        const dims = [...dimensions].map((api) => {
            const field = meta.dimensions.find((dimension) => dimension.api === api);
            return { key: slug(api), label: field?.label ?? api, api };
        });
        const meas = [...measures].map((api) => {
            const field = meta.metrics.find((metric) => metric.api === api);
            return { key: slug(api), label: field?.label ?? api, api, unit: unitForType(field?.type ?? ''), cast: castForType(field?.type ?? ''), scale: 1 };
        });

        return { key, label: label.trim(), dimensions: dims, measures: meas, limit };
    };

    const spec = buildSpec();

    const runTest = (): void => {
        if (spec === null) return;
        testMut.mutate(spec, { onSuccess: (result) => setSample(result) });
    };
    const save = (): void => {
        if (spec === null) return;
        saveMut.mutate(spec, {
            onSuccess: (result) => {
                setDatasets(result.custom_datasets);
                setLabel('');
                setMeasures(new Set());
                setDimensions(new Set());
                setSample(null);
            },
        });
    };
    const remove = (key: string): void => {
        deleteMut.mutate(key, { onSuccess: (result) => setDatasets(result.custom_datasets) });
    };

    const firstRow = sample?.rows[0];
    const sampleCols = firstRow !== undefined ? Object.keys(firstRow) : [];

    return (
        <Modal onClose={onClose} className="ir-max-w-3xl">
            <Card
                title="Métricas personalizadas de GA4"
                description="Crea un conjunto de datos a partir de las métricas y dimensiones reales de tu propiedad. Aparecerá en el editor como cualquier otra fuente."
                actions={
                    <Button variant="ghost" size="sm" onClick={onClose}>
                        Cerrar
                    </Button>
                }
            >
                <div className="ir-flex ir-flex-col ir-gap-5">
                    {/* Existing custom datasets */}
                    {datasets.length > 0 && (
                        <div className="ir-flex ir-flex-col ir-gap-1.5">
                            <p className="ir-text-xs ir-font-medium ir-text-foreground/80">Tus métricas personalizadas</p>
                            {datasets.map((dataset) => (
                                <div key={dataset.key} className="ir-flex ir-items-center ir-justify-between ir-gap-2 ir-rounded-md ir-border ir-px-3 ir-py-2">
                                    <div className="ir-min-w-0">
                                        <p className="ir-truncate ir-text-sm ir-font-medium">{dataset.label}</p>
                                        <p className="ir-truncate ir-text-xs ir-text-muted-foreground">
                                            {dataset.measures.map((measure) => measure.label).join(', ')} · por {dataset.dimensions.map((dimension) => dimension.label).join(' / ')}
                                        </p>
                                    </div>
                                    <Button variant="ghost" size="sm" onClick={() => remove(dataset.key)} disabled={deleteMut.isPending}>
                                        Eliminar
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}

                    {metaQuery.isLoading && <p className="ir-text-sm ir-text-muted-foreground">Leyendo el catálogo de tu propiedad GA4…</p>}
                    {metaQuery.isError && (
                        <p className="ir-rounded-md ir-border ir-border-danger/30 ir-bg-danger/5 ir-p-3 ir-text-sm ir-text-danger">
                            No se pudo leer el catálogo de GA4. Revisa las credenciales y el property_id de esta fuente.
                        </p>
                    )}

                    {meta !== undefined && (
                        <div className="ir-flex ir-flex-col ir-gap-4 ir-border-t ir-pt-4">
                            <Field label="Nombre del conjunto">
                                <Input value={label} placeholder="Ej. Campañas, Eventos de compra…" onChange={(event) => setLabel(event.target.value)} />
                            </Field>

                            <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2">
                                <div>
                                    <p className="ir-mb-1.5 ir-text-xs ir-font-medium ir-text-foreground/80">Medidas (números)</p>
                                    <PickList items={meta.metrics} selected={measures} onToggle={(api) => toggle(measures, setMeasures, api)} search={metricSearch} onSearch={setMetricSearch} placeholder="Buscar métrica…" />
                                    <p className="ir-mt-1 ir-text-[11px] ir-text-muted-foreground">Elige métricas sumables (sesiones, usuarios, ingresos). Evita tasas/promedios (rebote, CTR): no se pueden sumar al agrupar.</p>
                                </div>
                                <div>
                                    <p className="ir-mb-1.5 ir-text-xs ir-font-medium ir-text-foreground/80">Dimensiones (para desglosar/filtrar)</p>
                                    <PickList items={meta.dimensions} selected={dimensions} onToggle={(api) => toggle(dimensions, setDimensions, api)} search={dimSearch} onSearch={setDimSearch} placeholder="Buscar dimensión…" />
                                </div>
                            </div>

                            <div className="ir-flex ir-items-end ir-gap-3">
                                <Field label="Máx. filas (top-N)">
                                    <Input type="number" min="1" max="1000" value={limit} onChange={(event) => setLimit(Math.max(1, Math.min(1000, Number(event.target.value) || 250)))} className="ir-w-28" />
                                </Field>
                                <div className="ir-flex ir-flex-1 ir-justify-end ir-gap-2">
                                    <Button variant="ghost" onClick={runTest} disabled={spec === null || testMut.isPending}>
                                        {testMut.isPending ? 'Probando…' : 'Probar'}
                                    </Button>
                                    <Button onClick={save} disabled={spec === null || saveMut.isPending}>
                                        {saveMut.isPending ? 'Guardando…' : 'Guardar métrica'}
                                    </Button>
                                </div>
                            </div>

                            {spec === null && (label !== '' || measures.size > 0 || dimensions.size > 0) && (
                                <p className="ir-text-[11px] ir-text-muted-foreground">Necesitas un nombre, al menos una medida y una dimensión.</p>
                            )}

                            {/* Sample preview */}
                            {sample !== null && (
                                <div className="ir-rounded-md ir-border ir-bg-muted/20 ir-p-3">
                                    {sample.error !== null && <p className="ir-text-xs ir-text-danger">{sample.error}</p>}
                                    {sample.rows.length === 0 && sample.error === null && <p className="ir-text-xs ir-text-muted-foreground">Sin datos en los últimos 28 días (la combinación es válida).</p>}
                                    {sample.rows.length > 0 && (
                                        <div className="ir-overflow-x-auto">
                                            <table className="ir-w-full ir-text-left ir-text-xs">
                                                <thead>
                                                    <tr className="ir-border-b">
                                                        {sampleCols.map((col) => (
                                                            <th key={col} className="ir-px-2 ir-py-1 ir-font-medium">{col}</th>
                                                        ))}
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {sample.rows.slice(0, 8).map((row, index) => (
                                                        <tr key={index} className="ir-border-b last:ir-border-b-0">
                                                            {sampleCols.map((col) => (
                                                                <td key={col} className="ir-px-2 ir-py-1 ir-text-muted-foreground">{String(row[col] ?? '')}</td>
                                                            ))}
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </Card>
        </Modal>
    );
}
