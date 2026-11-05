import { Pencil, Plus, Target, Trash2, Trophy } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';

import { apiErrorMessage } from '@shared/lib/api';

import { type SiteGoalInput, useDeleteSiteGoal, useMetricCatalog, useSaveSiteGoal, useSiteGoals } from '../api';
import { MetricPicker, sourceLabel } from '../editor/MetricPicker';
import type { CatalogEntry, SiteGoalDto } from '../types';
import { ActionMenu, Button, Field, Input, Modal, Segmented, Select } from './ui';

const FORMATS: { value: NonNullable<SiteGoalDto['format']>; label: string }[] = [
    { value: 'number', label: 'Número' },
    { value: 'percent', label: 'Porcentaje' },
    { value: 'currency', label: 'Dinero' },
    { value: 'duration', label: 'Tiempo (segundos)' },
];

/** The format a metric most likely reads in, from its catalog unit. */
function formatFor(entry: CatalogEntry): SiteGoalDto['format'] {
    const unit = (entry.unit ?? '').toLowerCase();
    if (unit.includes('%') || unit.includes('percent')) return 'percent';
    if (['usd', 'eur', 'cop', 'mxn', 'currency', 'money'].some((code) => unit.includes(code))) return 'currency';
    if (unit === 's' || unit.includes('second')) return 'duration';

    return 'number';
}

function targetText(goal: SiteGoalDto): string {
    const number = goal.target.toLocaleString('es', { maximumFractionDigits: 2 });
    const value = goal.format === 'percent' ? `${number} %` : goal.format === 'duration' ? `${number} s` : number;

    return `${goal.direction === 'at_most' ? 'Máximo' : 'Mínimo'} ${value}`;
}

function GoalForm({ siteId, goal, onClose }: { siteId: number; goal: SiteGoalDto | null; onClose: () => void }): ReactElement {
    const { data: catalog = [] } = useMetricCatalog(siteId);
    const save = useSaveSiteGoal(siteId);
    const [picking, setPicking] = useState(false);
    const [form, setForm] = useState<SiteGoalInput>({
        label: goal?.label ?? '',
        source: goal?.source ?? '',
        metric: goal?.metric ?? '',
        target: goal?.target ?? 0,
        direction: goal?.direction ?? 'at_least',
        format: goal?.format ?? 'number',
    });
    const [target, setTarget] = useState(goal !== null ? String(goal.target) : '');
    const metricLabel = catalog.find((entry) => entry.source === form.source && entry.metric === form.metric)?.label ?? (form.metric !== '' ? form.metric : null);

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        save.mutate({ ...form, target: Number(target.replace(',', '.')), id: goal?.id }, { onSuccess: onClose });
    };

    return (
        <Modal onClose={onClose} className="ir-max-w-lg">
            <form onSubmit={submit} className="ir-rounded-2xl ir-border ir-bg-card ir-p-6 ir-shadow-ir-lg">
                <h2 className="ir-text-lg ir-font-semibold ir-tracking-tight">{goal === null ? 'Nueva meta' : 'Editar meta'}</h2>
                <p className="ir-mt-0.5 ir-text-[13px] ir-text-muted-foreground">Se mide cada periodo con los datos del sitio y aparece en el reporte con su barra de progreso.</p>

                <div className="ir-mt-5 ir-flex ir-flex-col ir-gap-4">
                    <Field label="Qué se mide">
                        <button
                            type="button"
                            onClick={() => setPicking(true)}
                            className="ir-flex ir-h-10 ir-items-center ir-justify-between ir-gap-2 ir-rounded-lg ir-border ir-bg-background ir-px-3 ir-text-left ir-text-sm hover:ir-border-foreground/30"
                        >
                            {metricLabel !== null ? (
                                <span className="ir-min-w-0 ir-truncate">
                                    <span className="ir-font-medium">{metricLabel}</span>
                                    <span className="ir-text-muted-foreground"> · {sourceLabel(form.source)}</span>
                                </span>
                            ) : (
                                <span className="ir-text-muted-foreground">Elige una métrica…</span>
                            )}
                            <Target className="ir-size-4 ir-shrink-0 ir-text-muted-foreground" />
                        </button>
                    </Field>
                    <Field label="Nombre que verá el cliente" hint="Ej.: «Visitas del mes», «Pedidos», «Disponibilidad».">
                        <Input value={form.label} maxLength={80} onChange={(event) => setForm({ ...form, label: event.target.value })} required />
                    </Field>
                    <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2">
                        <Field label="Objetivo">
                            <Input inputMode="decimal" value={target} onChange={(event) => setTarget(event.target.value)} placeholder="5000" required />
                        </Field>
                        <Field label="Se muestra como">
                            <Select value={form.format ?? 'number'} onChange={(event) => setForm({ ...form, format: event.target.value as SiteGoalDto['format'] })}>
                                {FORMATS.map((format) => (
                                    <option key={format.value} value={format.value}>
                                        {format.label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                    <div className="ir-flex ir-flex-col ir-gap-1.5">
                        <span className="ir-text-xs ir-font-medium ir-text-foreground/80">Se cumple cuando</span>
                        <Segmented
                            fill
                            value={form.direction}
                            onChange={(direction) => setForm({ ...form, direction })}
                            items={[
                                { value: 'at_least', label: 'Llega al objetivo' },
                                { value: 'at_most', label: 'No pasa del objetivo' },
                            ]}
                        />
                        <span className="ir-text-xs ir-text-muted-foreground">
                            {form.direction === 'at_least' ? 'Para visitas, ventas, clics… (más es mejor).' : 'Para tiempo de carga, rebote, gasto… (menos es mejor).'}
                        </span>
                    </div>
                </div>

                {save.isError && <p className="ir-mt-4 ir-text-xs ir-text-danger">{apiErrorMessage(save.error, 'No se pudo guardar la meta.')}</p>}

                <div className="ir-mt-6 ir-flex ir-justify-end ir-gap-2">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" disabled={save.isPending || form.metric === '' || target.trim() === ''}>
                        {save.isPending ? 'Guardando…' : 'Guardar meta'}
                    </Button>
                </div>
            </form>

            {picking && (
                <MetricPicker
                    catalog={catalog}
                    blockType="goals"
                    value={form.metric !== '' ? { source: form.source, metric: form.metric } : null}
                    onClose={() => setPicking(false)}
                    onPick={(entry) => {
                        setForm({ ...form, source: entry.source, metric: entry.metric, label: form.label !== '' ? form.label : entry.label, format: formatFor(entry) });
                        setPicking(false);
                    }}
                />
            )}
        </Modal>
    );
}

/**
 * The client's goals for this site (Etapa 4): what the agency agreed to reach with them. The
 * «Metas» block shows them in every report, with a progress bar, whatever template it uses.
 */
export function SiteGoalsCard({ siteId }: { siteId: number }): ReactElement {
    const { data: goals = [], isLoading } = useSiteGoals(siteId);
    const { data: catalog = [] } = useMetricCatalog(siteId);
    const remove = useDeleteSiteGoal(siteId);
    const [editing, setEditing] = useState<SiteGoalDto | null | 'new'>(null);
    const metricName = (goal: SiteGoalDto): string => catalog.find((entry) => entry.source === goal.source && entry.metric === goal.metric)?.label ?? goal.metric;

    return (
        <div>
            <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3">
                <div>
                    <h3 className="ir-flex ir-items-center ir-gap-2 ir-text-[15px] ir-font-semibold ir-tracking-tight">
                        Metas del cliente
                        {goals.length > 0 && <span className="ir-rounded-full ir-bg-muted ir-px-1.5 ir-py-0.5 ir-text-[11px] ir-font-medium ir-text-muted-foreground">{goals.length}</span>}
                    </h3>
                    <p className="ir-text-[13px] ir-text-muted-foreground">Lo que acordaste lograr con el cliente. Sus reportes muestran cuánto falta para cada meta.</p>
                </div>
                <Button size="sm" variant="ghost" onClick={() => setEditing('new')}>
                    <Plus className="ir-size-3.5" />
                    Añadir meta
                </Button>
            </div>

            {!isLoading && goals.length === 0 ? (
                <button
                    type="button"
                    onClick={() => setEditing('new')}
                    className="ir-mt-3 ir-flex ir-w-full ir-items-center ir-gap-3 ir-rounded-xl ir-border ir-border-dashed ir-p-4 ir-text-left ir-transition hover:ir-border-accent/50 hover:ir-bg-accent/[0.03]"
                >
                    <span className="ir-flex ir-size-9 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-lg ir-bg-accent/10 ir-text-accent">
                        <Trophy className="ir-size-4" />
                    </span>
                    <span>
                        <span className="ir-block ir-text-sm ir-font-medium">Aún no tiene metas</span>
                        <span className="ir-block ir-text-xs ir-text-muted-foreground">Ej.: 5.000 visitas al mes, 20 pedidos o 99,9 % de disponibilidad. Un cliente que ve su progreso entiende mejor tu trabajo.</span>
                    </span>
                </button>
            ) : (
                <ul className="ir-mt-3 ir-grid ir-gap-2 sm:ir-grid-cols-2 2xl:ir-grid-cols-3">
                    {goals.map((goal) => (
                        <li key={goal.id} className="ir-flex ir-items-center ir-gap-3 ir-rounded-lg ir-border ir-bg-card ir-p-2.5">
                            <span className="ir-flex ir-size-8 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ir-bg-muted ir-text-muted-foreground">
                                <Target className="ir-size-4" />
                            </span>
                            <div className="ir-min-w-0 ir-flex-1">
                                <p className="ir-truncate ir-text-sm ir-font-medium">{goal.label}</p>
                                <p className="ir-truncate ir-text-xs ir-text-muted-foreground">
                                    {targetText(goal)} · {metricName(goal)}
                                </p>
                            </div>
                            <ActionMenu
                                label={`Acciones de la meta ${goal.label}`}
                                items={[
                                    { label: 'Editar', icon: Pencil, onSelect: () => setEditing(goal) },
                                    {
                                        label: 'Quitar',
                                        icon: Trash2,
                                        danger: true,
                                        disabled: remove.isPending,
                                        onSelect: () => {
                                            if (window.confirm(`¿Quitar la meta «${goal.label}»? Dejará de aparecer en los próximos reportes.`)) {
                                                remove.mutate(goal.id);
                                            }
                                        },
                                    },
                                ]}
                            />
                        </li>
                    ))}
                </ul>
            )}

            {editing !== null && <GoalForm siteId={siteId} goal={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </div>
    );
}
