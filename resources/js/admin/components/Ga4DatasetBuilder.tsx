import { AlertTriangle, X } from 'lucide-react';
import { type ReactElement, useMemo, useState } from 'react';

import { type Ga4MetaField, type Ga4DatasetSpec, useDeleteGa4Dataset, useGa4Metadata, useSaveGa4Dataset, useTestGa4Dataset } from '../api';
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

/** Human label for a GA4 metric type, for the badge. */
function typeLabel(type: string): string {
    switch (type) {
        case 'TYPE_INTEGER':
            return 'número';
        case 'TYPE_FLOAT':
            return 'decimal';
        case 'TYPE_CURRENCY':
            return 'moneda';
        case 'TYPE_SECONDS':
        case 'TYPE_MILLISECONDS':
        case 'TYPE_MINUTES':
        case 'TYPE_HOURS':
            return 'tiempo';
        case 'TYPE_STANDARD':
            return 'estándar';
        default:
            return 'número';
    }
}

/**
 * Whether a metric is a rate/average — NOT safely summable when grouping (CLAUDE.md §3.3),
 * so the builder warns before it's added as a dataset measure.
 */
function isNonAdditive(api: string): boolean {
    return /rate|average|bounce|percent|per[A-Z]|ppm|cpc|cpm|cpt/i.test(api);
}

type FieldMeta = Ga4MetaField & { type?: string };

/** A searchable, category-filterable picker (metrics or dimensions) for the builder. */
function PickList({
    items,
    selected,
    onToggle,
    title,
    placeholder,
    showType,
}: {
    items: FieldMeta[];
    selected: Set<string>;
    onToggle: (api: string) => void;
    title: string;
    placeholder: string;
    showType: boolean;
}): ReactElement {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('');

    const categories = useMemo(
        () => [...new Set(items.map((item) => item.category).filter((value) => value !== ''))].sort((a, b) => a.localeCompare(b)),
        [items],
    );

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        const matches = items.filter(
            (item) =>
                (category === '' || item.category === category) &&
                (q === '' || `${item.label} ${item.api}`.toLowerCase().includes(q)),
        );
        // Keep selected items pinned at the top even when filtered.
        return [...matches].sort((a, b) => Number(selected.has(b.api)) - Number(selected.has(a.api)));
    }, [items, search, category, selected]);

    const byApi = useMemo(() => new Map(items.map((item) => [item.api, item])), [items]);
    const chosen = [...selected].map((api) => byApi.get(api)).filter((item): item is FieldMeta => item !== undefined);

    return (
        <div className="ir-flex ir-min-w-0 ir-flex-col ir-gap-2">
            <div className="ir-flex ir-items-center ir-justify-between">
                <p className="ir-text-xs ir-font-semibold ir-text-foreground/80">{title}</p>
                <span className="ir-rounded-full ir-bg-primary/10 ir-px-2 ir-text-[10px] ir-font-medium ir-text-primary">{selected.size} elegidas</span>
            </div>

            {/* Selected chips — always visible so you know exactly what you picked. */}
            {chosen.length > 0 && (
                <div className="ir-flex ir-flex-wrap ir-gap-1">
                    {chosen.map((item) => (
                        <button
                            key={item.api}
                            type="button"
                            onClick={() => onToggle(item.api)}
                            title={`Quitar ${item.api}`}
                            className="ir-flex ir-items-center ir-gap-1 ir-rounded-full ir-bg-primary/10 ir-py-0.5 ir-pl-2 ir-pr-1 ir-text-[11px] ir-text-primary hover:ir-bg-primary/20"
                        >
                            <span className="ir-max-w-[10rem] ir-truncate">{item.label}</span>
                            <X className="ir-size-3" />
                        </button>
                    ))}
                </div>
            )}

            <div className="ir-flex ir-gap-2">
                <Input value={search} placeholder={placeholder} onChange={(event) => setSearch(event.target.value)} className="ir-flex-1" />
                {categories.length > 1 && (
                    <select
                        value={category}
                        onChange={(event) => setCategory(event.target.value)}
                        className="ir-max-w-[8rem] ir-rounded-md ir-border ir-bg-background ir-px-2 ir-text-xs"
                    >
                        <option value="">Todas</option>
                        {categories.map((cat) => (
                            <option key={cat} value={cat}>
                                {cat}
                            </option>
                        ))}
                    </select>
                )}
            </div>

            <div className="ir-h-72 ir-overflow-y-auto ir-rounded-md ir-border ir-bg-background">
                {filtered.slice(0, 300).map((item) => {
                    const checked = selected.has(item.api);
                    const warn = showType && isNonAdditive(item.api);

                    return (
                        <label
                            key={item.api}
                            className={`ir-flex ir-cursor-pointer ir-items-start ir-gap-2.5 ir-border-b ir-px-3 ir-py-2 last:ir-border-b-0 hover:ir-bg-muted/50 ${checked ? 'ir-bg-primary/5' : ''}`}
                        >
                            <input type="checkbox" checked={checked} onChange={() => onToggle(item.api)} className="ir-mt-0.5 ir-size-4 ir-shrink-0" />
                            <span className="ir-min-w-0 ir-flex-1">
                                <span className="ir-flex ir-items-center ir-gap-1.5">
                                    <span className="ir-text-sm ir-font-medium ir-leading-tight">{item.label}</span>
                                    {item.custom && <span className="ir-rounded-full ir-bg-accent/10 ir-px-1.5 ir-text-[10px] ir-text-accent">propia</span>}
                                </span>
                                <span className="ir-mt-0.5 ir-flex ir-flex-wrap ir-items-center ir-gap-x-2 ir-gap-y-0.5 ir-text-[11px] ir-text-muted-foreground">
                                    <span className="ir-font-mono">{item.api}</span>
                                    {item.category !== '' && <span>· {item.category}</span>}
                                </span>
                            </span>
                            <span className="ir-mt-0.5 ir-flex ir-shrink-0 ir-flex-col ir-items-end ir-gap-1">
                                {showType && item.type !== undefined && (
                                    <span className="ir-rounded ir-bg-muted ir-px-1.5 ir-text-[10px] ir-text-muted-foreground">{typeLabel(item.type)}</span>
                                )}
                                {warn && (
                                    <span title="Tasa/promedio: no se suma bien al agrupar" className="ir-flex ir-items-center ir-gap-0.5 ir-text-[10px] ir-text-amber-600">
                                        <AlertTriangle className="ir-size-3" /> no sumable
                                    </span>
                                )}
                            </span>
                        </label>
                    );
                })}
                {filtered.length === 0 && <p className="ir-px-3 ir-py-4 ir-text-xs ir-text-muted-foreground">Sin resultados.</p>}
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
        <Modal onClose={onClose} className="ir-max-w-5xl">
            <Card
                title="Constructor de métricas de GA4"
                description="Compón un conjunto de datos con las métricas y dimensiones reales de tu propiedad. Pruébalo con datos reales y guárdalo: aparecerá en el editor como cualquier otra métrica."
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
                            <p className="ir-text-xs ir-font-semibold ir-text-foreground/80">Tus métricas guardadas</p>
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

                            <div className="ir-grid ir-gap-5 sm:ir-grid-cols-2">
                                <PickList
                                    title="Medidas (números)"
                                    items={meta.metrics}
                                    selected={measures}
                                    onToggle={(api) => toggle(measures, setMeasures, api)}
                                    placeholder="Buscar métrica…"
                                    showType
                                />
                                <PickList
                                    title="Dimensiones (desglosar / filtrar)"
                                    items={meta.dimensions}
                                    selected={dimensions}
                                    onToggle={(api) => toggle(dimensions, setDimensions, api)}
                                    placeholder="Buscar dimensión…"
                                    showType={false}
                                />
                            </div>

                            <p className="ir-text-[11px] ir-text-muted-foreground">
                                Elige <strong>medidas sumables</strong> (sesiones, usuarios, ingresos). Las marcadas «no sumable» (tasas, promedios, CTR) no se
                                pueden sumar al agrupar por una dimensión.
                            </p>

                            {/* Resulting columns preview */}
                            {spec !== null && (
                                <div className="ir-rounded-md ir-border ir-border-primary/30 ir-bg-primary/5 ir-p-3">
                                    <p className="ir-text-[11px] ir-font-medium ir-text-foreground/80">Columnas del conjunto</p>
                                    <p className="ir-mt-1 ir-text-xs ir-text-muted-foreground">
                                        {[...spec.dimensions.map((d) => d.label), ...spec.measures.map((m) => m.label)].join(' · ')}
                                    </p>
                                </div>
                            )}

                            <div className="ir-flex ir-flex-wrap ir-items-end ir-gap-3">
                                <Field label="Máx. filas (top-N)">
                                    <Input type="number" min="1" max="1000" value={limit} onChange={(event) => setLimit(Math.max(1, Math.min(1000, Number(event.target.value) || 250)))} className="ir-w-28" />
                                </Field>
                                <div className="ir-flex ir-flex-1 ir-items-center ir-justify-end ir-gap-2">
                                    {spec === null && (label !== '' || measures.size > 0 || dimensions.size > 0) && (
                                        <span className="ir-text-[11px] ir-text-amber-600">Falta: nombre + ≥1 medida + ≥1 dimensión.</span>
                                    )}
                                    <Button variant="outline" onClick={runTest} disabled={spec === null || testMut.isPending}>
                                        {testMut.isPending ? 'Probando…' : 'Probar'}
                                    </Button>
                                    <Button onClick={save} disabled={spec === null || saveMut.isPending}>
                                        {saveMut.isPending ? 'Guardando…' : 'Guardar métrica'}
                                    </Button>
                                </div>
                            </div>

                            {/* Sample preview */}
                            {sample !== null && (
                                <div className="ir-rounded-md ir-border ir-bg-muted/20 ir-p-3">
                                    {sample.error !== null && <p className="ir-text-xs ir-text-danger">{sample.error}</p>}
                                    {sample.rows.length === 0 && sample.error === null && (
                                        <p className="ir-text-xs ir-text-muted-foreground">Sin datos en los últimos 28 días (la combinación es válida; guárdala igual).</p>
                                    )}
                                    {sample.rows.length > 0 && (
                                        <>
                                            <p className="ir-mb-2 ir-text-[11px] ir-font-medium ir-text-emerald-600">✓ Datos reales (últimos 28 días) — muestra de {Math.min(8, sample.rows.length)} filas</p>
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
                                        </>
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
