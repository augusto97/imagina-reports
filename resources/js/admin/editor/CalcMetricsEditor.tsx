import { Check, Plus, Trash2, TriangleAlert } from 'lucide-react';
import { type ReactElement, useMemo } from 'react';

import type { CalcMetric } from '../api';
import { Input } from '../components/ui';
import type { CatalogEntry } from '../types';
import { validateFormula } from './calcFormula';

const OPERATORS = ['+', '-', '*', '/', '(', ')'];

/**
 * The "fx" editor for calculated metrics (CLAUDE.md §10.1/§11.3): mix metrics into new
 * ones (e.g. revenue / orders = AOV). Each row has key + label + a formula field with
 * operator buttons, a metric inserter (from the site catalog), and LIVE validation that
 * mirrors the server's safe evaluator — so a bad formula is caught before saving.
 */
export function CalcMetricsEditor({
    metrics,
    catalog,
    onAdd,
    onUpdate,
    onRemove,
    values,
}: {
    metrics: CalcMetric[];
    catalog: CatalogEntry[];
    onAdd: () => void;
    onUpdate: (index: number, patch: Partial<CalcMetric>) => void;
    onRemove: (index: number) => void;
    /** calc.<key> → computed value with real data, shown live next to each formula. */
    values?: Record<string, number>;
}): ReactElement {
    const knownKeys = useMemo(() => new Set(catalog.map((entry) => entry.key)), [catalog]);

    const append = (index: number, formula: string, token: string): void => {
        const last = formula.slice(-1);
        const sep = formula !== '' && last !== ' ' && last !== '(' ? ' ' : '';
        onUpdate(index, { formula: `${formula}${sep}${token}` });
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-3">
            {metrics.length === 0 && (
                <p className="ir-text-[11px] ir-text-muted-foreground">
                    Combina métricas en una nueva (p. ej. <code>woocommerce.revenue / woocommerce.orders</code> = ticket medio).
                </p>
            )}

            {metrics.map((metric, index) => {
                const trimmed = metric.formula.trim();
                // Skip validation until a site is chosen (no catalog → every metric would
                // look "unknown"); the server still validates on preview/generate.
                const check = trimmed === '' || knownKeys.size === 0 ? null : validateFormula(metric.formula, knownKeys);

                return (
                    <div key={index} className="ir-flex ir-flex-col ir-gap-2 ir-rounded-lg ir-border ir-bg-background ir-p-2.5">
                        <div className="ir-flex ir-items-center ir-gap-2">
                            <span className="ir-font-mono ir-text-xs ir-text-muted-foreground">calc.</span>
                            <Input className="ir-h-7 ir-flex-1 ir-font-mono" placeholder="clave" value={metric.key} onChange={(event) => onUpdate(index, { key: event.target.value })} />
                            <button type="button" className="ir-text-muted-foreground hover:ir-text-red-500" title="Eliminar" onClick={() => onRemove(index)}>
                                <Trash2 className="ir-size-4" />
                            </button>
                        </div>

                        <Input className="ir-h-7" placeholder="Etiqueta (p. ej. Ticket medio)" value={metric.label} onChange={(event) => onUpdate(index, { label: event.target.value })} />

                        <Input
                            className="ir-h-8 ir-font-mono ir-text-xs"
                            placeholder="woocommerce.revenue / woocommerce.orders"
                            value={metric.formula}
                            onChange={(event) => onUpdate(index, { formula: event.target.value })}
                        />

                        <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-1">
                            {OPERATORS.map((op) => (
                                <button
                                    key={op}
                                    type="button"
                                    onClick={() => append(index, metric.formula, op)}
                                    className="ir-flex ir-size-6 ir-items-center ir-justify-center ir-rounded ir-border ir-bg-card ir-font-mono ir-text-xs ir-text-muted-foreground ir-transition hover:ir-border-primary hover:ir-text-foreground"
                                >
                                    {op}
                                </button>
                            ))}
                            <select
                                value=""
                                onChange={(event) => {
                                    if (event.target.value !== '') {
                                        append(index, metric.formula, event.target.value);
                                    }
                                }}
                                className="ir-h-6 ir-rounded ir-border ir-bg-card ir-px-1 ir-text-xs ir-text-muted-foreground"
                                title="Insertar una métrica del catálogo"
                            >
                                <option value="">+ métrica…</option>
                                {catalog.map((entry) => (
                                    <option key={entry.key} value={entry.key}>
                                        {entry.label} ({entry.source})
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-2">
                            {check !== null ? (
                                check.ok ? (
                                    <p className="ir-flex ir-items-center ir-gap-1 ir-text-[11px] ir-text-emerald-600">
                                        <Check className="ir-size-3" /> Fórmula válida
                                    </p>
                                ) : (
                                    <p className="ir-flex ir-items-center ir-gap-1 ir-text-[11px] ir-text-amber-600">
                                        <TriangleAlert className="ir-size-3" /> {check.error}
                                    </p>
                                )
                            ) : (
                                <span />
                            )}
                            {values !== undefined && metric.key !== '' && values[`calc.${metric.key}`] !== undefined && (
                                <span className="ir-rounded ir-bg-primary/10 ir-px-2 ir-py-0.5 ir-text-[11px] ir-font-medium ir-tabular-nums ir-text-primary">
                                    = {Number(values[`calc.${metric.key}`]).toLocaleString('es', { maximumFractionDigits: 2 })}
                                </span>
                            )}
                        </div>
                    </div>
                );
            })}

            <button
                type="button"
                onClick={onAdd}
                className="ir-flex ir-items-center ir-justify-center ir-gap-1.5 ir-rounded-md ir-border ir-border-dashed ir-py-2 ir-text-xs ir-font-medium ir-text-muted-foreground ir-transition hover:ir-border-primary hover:ir-text-foreground"
            >
                <Plus className="ir-size-3.5" /> Añadir métrica calculada
            </button>
        </div>
    );
}
