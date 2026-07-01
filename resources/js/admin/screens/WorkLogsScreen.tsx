import {
    Camera,
    Clock,
    DatabaseBackup,
    FileText,
    Gauge,
    LifeBuoy,
    type LucideIcon,
    Palette,
    Pencil,
    Plus,
    Search,
    Shield,
    Sparkles,
    Trash2,
    Wrench,
    X,
} from 'lucide-react';
import { type FormEvent, type ReactElement, useMemo, useRef, useState } from 'react';

import { useCreateSiteWorkLog, useDeleteWorkLog, useSiteWorkLogs, useSites, useUpdateWorkLog } from '../api';
import { Badge, Button, Card, Field, Input, Select } from '../components/ui';
import type { WorkLog, WorkLogStatus } from '../types';

/** Predefined task categories — each with an icon + colour, so the log reads at a glance.
 * Free text is still allowed; an unknown category falls back to a neutral chip. */
interface CategoryMeta {
    label: string;
    Icon: LucideIcon;
    chip: string;
    dot: string;
}

const CATEGORIES: Record<string, CategoryMeta> = {
    Mantenimiento: { label: 'Mantenimiento', Icon: Wrench, chip: 'ir-bg-blue-100 ir-text-blue-700', dot: 'ir-bg-blue-500' },
    Seguridad: { label: 'Seguridad', Icon: Shield, chip: 'ir-bg-red-100 ir-text-red-700', dot: 'ir-bg-red-500' },
    Contenido: { label: 'Contenido', Icon: FileText, chip: 'ir-bg-violet-100 ir-text-violet-700', dot: 'ir-bg-violet-500' },
    SEO: { label: 'SEO', Icon: Search, chip: 'ir-bg-emerald-100 ir-text-emerald-700', dot: 'ir-bg-emerald-500' },
    Rendimiento: { label: 'Rendimiento', Icon: Gauge, chip: 'ir-bg-amber-100 ir-text-amber-700', dot: 'ir-bg-amber-500' },
    Soporte: { label: 'Soporte', Icon: LifeBuoy, chip: 'ir-bg-cyan-100 ir-text-cyan-700', dot: 'ir-bg-cyan-500' },
    'Copias de seguridad': { label: 'Copias de seguridad', Icon: DatabaseBackup, chip: 'ir-bg-slate-200 ir-text-slate-700', dot: 'ir-bg-slate-500' },
    Diseño: { label: 'Diseño', Icon: Palette, chip: 'ir-bg-pink-100 ir-text-pink-700', dot: 'ir-bg-pink-500' },
};
const CATEGORY_KEYS = Object.keys(CATEGORIES);

const categoryMeta = (name: string | null): CategoryMeta | null => (name != null && name in CATEGORIES ? CATEGORIES[name] ?? null : null);

/** One-click task templates — the fast way to log the work an agency does over and over. */
const TEMPLATES: { label: string; category: string; minutes?: number }[] = [
    { label: 'Actualicé plugins, tema y WordPress', category: 'Mantenimiento', minutes: 30 },
    { label: 'Copia de seguridad realizada y verificada', category: 'Copias de seguridad', minutes: 15 },
    { label: 'Análisis de seguridad y malware', category: 'Seguridad', minutes: 20 },
    { label: 'Optimización de rendimiento y caché', category: 'Rendimiento', minutes: 30 },
    { label: 'Limpieza de spam y base de datos', category: 'Mantenimiento', minutes: 20 },
    { label: 'Revisión y corrección de errores', category: 'Mantenimiento', minutes: 30 },
    { label: 'Publicación / edición de contenido', category: 'Contenido', minutes: 30 },
    { label: 'Mejoras de SEO', category: 'SEO', minutes: 30 },
    { label: 'Soporte y atención al cliente', category: 'Soporte', minutes: 20 },
];

interface StatusMeta {
    value: WorkLogStatus;
    label: string;
    tone: 'success' | 'warning' | 'neutral';
    dot: string;
}

const STATUSES: StatusMeta[] = [
    { value: 'done', label: 'Hecho', tone: 'success', dot: 'ir-bg-emerald-500' },
    { value: 'in_progress', label: 'En progreso', tone: 'warning', dot: 'ir-bg-amber-500' },
    { value: 'planned', label: 'Planificado', tone: 'neutral', dot: 'ir-bg-slate-400' },
];
const statusMeta = (value: string): StatusMeta => STATUSES.find((entry) => entry.value === value) ?? { value: 'done', label: 'Hecho', tone: 'success', dot: 'ir-bg-emerald-500' };

const selectClass = 'ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm';

function currentMonth(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

function monthRange(month: string): { from: string; to: string } {
    const [year, mon] = month.split('-').map(Number);
    const lastDay = new Date(year ?? 2026, mon ?? 1, 0).getDate();

    return { from: `${month}-01`, to: `${month}-${String(lastDay).padStart(2, '0')}` };
}

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

function formatHours(minutes: number): string {
    return (minutes / 60).toLocaleString(undefined, { maximumFractionDigits: 1 });
}

function formatDay(iso: string): string {
    const date = new Date(`${iso.slice(0, 10)}T00:00:00`);

    return Number.isNaN(date.getTime()) ? iso.slice(0, 10) : date.toLocaleDateString('es', { weekday: 'short', day: 'numeric', month: 'short' });
}

interface Draft {
    description: string;
    status: WorkLogStatus;
    category: string;
    minutes: string;
    date: string;
    screenshot: File | null;
}

const emptyDraft = (): Draft => ({ description: '', status: 'done', category: '', minutes: '', date: today(), screenshot: null });

/**
 * Premium work logging (CLAUDE.md §11.5). Pick a site, then log any task in seconds:
 * one-click templates, colour-coded categories, a status (done / in progress / planned),
 * optional time + proof screenshot. The header proves the hourly service paid off; the
 * timeline groups the month's work by day. Only "done" tasks reach the client report.
 */
export function WorkLogsScreen(): ReactElement {
    const { data: sites = [] } = useSites();
    const [siteId, setSiteId] = useState<number | null>(null);
    const [month, setMonth] = useState(currentMonth());

    const range = monthRange(month);
    const { data: logs = [] } = useSiteWorkLogs(siteId, range.from, range.to);
    const create = useCreateSiteWorkLog(siteId ?? 0);
    const update = useUpdateWorkLog();
    const remove = useDeleteWorkLog();

    const [draft, setDraft] = useState<Draft>(emptyDraft());
    const [editingId, setEditingId] = useState<number | null>(null);
    const [fileKey, setFileKey] = useState(0);
    const composerRef = useRef<HTMLDivElement>(null);
    const set = <K extends keyof Draft>(key: K, value: Draft[K]): void => setDraft((prev) => ({ ...prev, [key]: value }));

    const site = sites.find((candidate) => candidate.id === siteId);
    const planHours = site?.plan_hours != null ? Number(site.plan_hours) : null;

    // Stats: hours + plan reflect DONE work (what the client report shows); counts split by status.
    const doneLogs = logs.filter((log) => log.status === 'done');
    const doneMinutes = doneLogs.reduce((sum, log) => sum + (log.minutes ?? 0), 0);
    const openCount = logs.filter((log) => log.status !== 'done').length;
    const byCategory = useMemo(() => {
        const totals = new Map<string, number>();
        for (const log of doneLogs) {
            const key = log.category ?? 'Sin categoría';
            totals.set(key, (totals.get(key) ?? 0) + (log.minutes ?? 0));
        }

        return [...totals.entries()].filter(([, m]) => m > 0).sort((a, b) => b[1] - a[1]);
    }, [doneLogs]);
    const maxCategoryMinutes = byCategory[0]?.[1] ?? 0;

    // Group the month's tasks by day (newest first) for the timeline.
    const grouped = useMemo(() => {
        const map = new Map<string, WorkLog[]>();
        for (const log of logs) {
            const day = log.performed_at.slice(0, 10);
            map.set(day, [...(map.get(day) ?? []), log]);
        }

        return [...map.entries()].sort((a, b) => b[0].localeCompare(a[0]));
    }, [logs]);

    const applyTemplate = (template: (typeof TEMPLATES)[number]): void => {
        setDraft((prev) => ({
            ...prev,
            description: template.label,
            category: template.category,
            minutes: template.minutes != null ? String(template.minutes) : prev.minutes,
        }));
    };

    const startEdit = (log: WorkLog): void => {
        setEditingId(log.id);
        setDraft({
            description: log.description,
            status: log.status,
            category: log.category ?? '',
            minutes: log.minutes != null ? String(log.minutes) : '',
            date: log.performed_at.slice(0, 10),
            screenshot: null,
        });
        setFileKey((key) => key + 1);
        composerRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const resetComposer = (): void => {
        setDraft(emptyDraft());
        setEditingId(null);
        setFileKey((key) => key + 1);
    };

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        if (siteId === null || draft.description.trim() === '') {
            return;
        }
        const payload = {
            description: draft.description.trim(),
            status: draft.status,
            minutes: draft.minutes === '' ? null : Number(draft.minutes),
            category: draft.category === '' ? null : draft.category,
            performed_at: draft.date,
            screenshot: draft.screenshot,
        };

        if (editingId !== null) {
            update.mutate({ id: editingId, ...payload }, { onSuccess: resetComposer });
        } else {
            create.mutate(payload, { onSuccess: resetComposer });
        }
    };

    const busy = create.isPending || update.isPending;

    return (
        <div className="ir-flex ir-flex-col ir-gap-5">
            {/* Header + scope */}
            <div className="ir-flex ir-flex-wrap ir-items-end ir-justify-between ir-gap-3">
                <div>
                    <h1 className="ir-text-lg ir-font-semibold ir-tracking-tight">Registrar trabajo</h1>
                    <p className="ir-mt-1 ir-max-w-2xl ir-text-sm ir-text-muted-foreground">
                        Deja constancia de todo lo que haces en cada sitio. Es lo que justifica el plan de soporte ante el cliente — y solo lo marcado como «Hecho» aparece en su reporte.
                    </p>
                </div>
                <div className="ir-flex ir-items-end ir-gap-2">
                    <Field label="Sitio">
                        <select className={`${selectClass} ir-min-w-[12rem]`} value={siteId ?? ''} onChange={(event) => setSiteId(event.target.value === '' ? null : Number(event.target.value))}>
                            <option value="">Selecciona…</option>
                            {sites.map((candidate) => (
                                <option key={candidate.id} value={candidate.id}>
                                    {candidate.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Mes">
                        <Input type="month" value={month} onChange={(event) => setMonth(event.target.value)} className="ir-w-40" />
                    </Field>
                </div>
            </div>

            {siteId === null ? (
                <div className="ir-flex ir-flex-col ir-items-center ir-gap-2 ir-rounded-lg ir-border ir-border-dashed ir-bg-card ir-py-16 ir-text-center">
                    <span className="ir-flex ir-size-12 ir-items-center ir-justify-center ir-rounded-xl ir-bg-muted ir-text-muted-foreground">
                        <Clock className="ir-size-6" />
                    </span>
                    <p className="ir-text-sm ir-font-medium">Elige un sitio para empezar</p>
                    <p className="ir-max-w-sm ir-text-xs ir-text-muted-foreground">Registra tareas de mantenimiento, seguridad, contenido y más — en segundos.</p>
                </div>
            ) : (
                <>
                    {/* Composer */}
                    <Card
                        title={editingId !== null ? 'Editar tarea' : 'Añadir tarea'}
                        description={editingId !== null ? 'Actualiza los datos y guarda.' : 'Escribe qué hiciste o usa una plantilla. Enter para añadir.'}
                        actions={
                            editingId !== null ? (
                                <Button variant="ghost" size="sm" onClick={resetComposer}>
                                    <X className="ir-size-3.5" />
                                    Cancelar edición
                                </Button>
                            ) : undefined
                        }
                    >
                        <div ref={composerRef} className="ir-flex ir-flex-col ir-gap-4">
                            {/* Quick templates */}
                            {editingId === null && (
                                <div className="ir-flex ir-flex-col ir-gap-1.5">
                                    <span className="ir-flex ir-items-center ir-gap-1.5 ir-text-xs ir-font-medium ir-text-muted-foreground">
                                        <Sparkles className="ir-size-3.5" />
                                        Tareas frecuentes
                                    </span>
                                    <div className="ir-flex ir-flex-wrap ir-gap-1.5">
                                        {TEMPLATES.map((template) => {
                                            const meta = categoryMeta(template.category);

                                            return (
                                                <button
                                                    key={template.label}
                                                    type="button"
                                                    onClick={() => applyTemplate(template)}
                                                    className="ir-inline-flex ir-items-center ir-gap-1.5 ir-rounded-full ir-border ir-bg-background ir-px-2.5 ir-py-1 ir-text-xs ir-transition-colors hover:ir-border-primary hover:ir-bg-primary/5"
                                                >
                                                    {meta !== null && <meta.Icon className="ir-size-3.5 ir-text-muted-foreground" />}
                                                    {template.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            <form onSubmit={submit} className="ir-flex ir-flex-col ir-gap-3">
                                <div className="ir-flex ir-flex-wrap ir-items-end ir-gap-2">
                                    <div className="ir-min-w-[18rem] ir-flex-1">
                                        <Field label="¿Qué hiciste?">
                                            <Input autoFocus value={draft.description} onChange={(event) => set('description', event.target.value)} placeholder="Ej. Actualicé plugins y verifiqué la copia de seguridad" />
                                        </Field>
                                    </div>
                                    <Button type="submit" disabled={busy || draft.description.trim() === ''}>
                                        {editingId !== null ? 'Guardar cambios' : (
                                            <>
                                                <Plus className="ir-size-4" />
                                                Añadir
                                            </>
                                        )}
                                    </Button>
                                </div>

                                <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2 lg:ir-grid-cols-4">
                                    <Field label="Categoría">
                                        <Select value={draft.category} onChange={(event) => set('category', event.target.value)}>
                                            <option value="">Sin categoría</option>
                                            {CATEGORY_KEYS.map((key) => (
                                                <option key={key} value={key}>
                                                    {key}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Field label="Estado">
                                        <Select value={draft.status} onChange={(event) => set('status', event.target.value as WorkLogStatus)}>
                                            {STATUSES.map((entry) => (
                                                <option key={entry.value} value={entry.value}>
                                                    {entry.label}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Field label="Tiempo (minutos)">
                                        <Input type="number" min="0" value={draft.minutes} onChange={(event) => set('minutes', event.target.value)} placeholder="opcional" />
                                    </Field>
                                    <Field label="Fecha">
                                        <Input type="date" value={draft.date} onChange={(event) => set('date', event.target.value)} />
                                    </Field>
                                </div>

                                <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-3">
                                    <label className="ir-inline-flex ir-cursor-pointer ir-items-center ir-gap-2 ir-rounded-md ir-border ir-bg-background ir-px-2.5 ir-py-1.5 ir-text-xs ir-text-muted-foreground hover:ir-bg-muted">
                                        <Camera className="ir-size-4" />
                                        {draft.screenshot !== null ? draft.screenshot.name : 'Adjuntar captura (prueba)'}
                                        <input
                                            key={fileKey}
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp"
                                            onChange={(event) => set('screenshot', event.target.files?.[0] ?? null)}
                                            className="ir-hidden"
                                        />
                                    </label>
                                    {draft.screenshot !== null && (
                                        <button type="button" className="ir-text-xs ir-text-muted-foreground hover:ir-text-danger" onClick={() => { set('screenshot', null); setFileKey((k) => k + 1); }}>
                                            Quitar
                                        </button>
                                    )}
                                </div>
                            </form>
                        </div>
                    </Card>

                    {/* Stats */}
                    <div className="ir-grid ir-gap-4 lg:ir-grid-cols-3">
                        <Card className="lg:ir-col-span-2">
                            <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-6">
                                <div>
                                    <p className="ir-text-3xl ir-font-semibold">{formatHours(doneMinutes)} h</p>
                                    <p className="ir-text-xs ir-text-muted-foreground">Horas invertidas{planHours !== null ? ` · plan ${planHours} h` : ''}</p>
                                </div>
                                <div>
                                    <p className="ir-text-3xl ir-font-semibold">{doneLogs.length}</p>
                                    <p className="ir-text-xs ir-text-muted-foreground">Tareas hechas</p>
                                </div>
                                {openCount > 0 && (
                                    <div>
                                        <p className="ir-text-3xl ir-font-semibold ir-text-amber-600">{openCount}</p>
                                        <p className="ir-text-xs ir-text-muted-foreground">En progreso / planificadas</p>
                                    </div>
                                )}
                                {planHours !== null && planHours > 0 && (
                                    <div className="ir-min-w-[12rem] ir-flex-1">
                                        <div className="ir-mb-1 ir-flex ir-justify-between ir-text-xs ir-text-muted-foreground">
                                            <span>Horas usadas vs plan</span>
                                            <span>{Math.round((doneMinutes / 60 / planHours) * 100)}%</span>
                                        </div>
                                        <div className="ir-h-2 ir-overflow-hidden ir-rounded ir-bg-muted">
                                            <div className="ir-h-full ir-rounded ir-bg-primary" style={{ width: `${Math.min(100, (doneMinutes / 60 / planHours) * 100)}%` }} />
                                        </div>
                                    </div>
                                )}
                            </div>
                        </Card>
                        <Card title="Por categoría">
                            {byCategory.length === 0 ? (
                                <p className="ir-text-xs ir-text-muted-foreground">Sin tiempo registrado todavía.</p>
                            ) : (
                                <ul className="ir-flex ir-flex-col ir-gap-2">
                                    {byCategory.slice(0, 5).map(([name, minutes]) => {
                                        const meta = categoryMeta(name);

                                        return (
                                            <li key={name} className="ir-flex ir-items-center ir-gap-2 ir-text-xs">
                                                <span className={`ir-size-2 ir-shrink-0 ir-rounded-full ${meta?.dot ?? 'ir-bg-muted-foreground'}`} />
                                                <span className="ir-w-28 ir-shrink-0 ir-truncate">{name}</span>
                                                <span className="ir-h-1.5 ir-flex-1 ir-overflow-hidden ir-rounded ir-bg-muted">
                                                    <span className={`ir-block ir-h-full ir-rounded ${meta?.dot ?? 'ir-bg-primary'}`} style={{ width: `${maxCategoryMinutes > 0 ? (minutes / maxCategoryMinutes) * 100 : 0}%` }} />
                                                </span>
                                                <span className="ir-w-10 ir-shrink-0 ir-text-right ir-tabular-nums ir-text-muted-foreground">{formatHours(minutes)} h</span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </Card>
                    </div>

                    {/* Timeline */}
                    <Card title={`Trabajo de ${site?.name ?? ''}`} description={`${logs.length} ${logs.length === 1 ? 'tarea' : 'tareas'} este mes`}>
                        {logs.length === 0 ? (
                            <p className="ir-text-sm ir-text-muted-foreground">Aún no hay tareas este mes. Añade la primera arriba.</p>
                        ) : (
                            <div className="ir-flex ir-flex-col ir-gap-5">
                                {grouped.map(([day, dayLogs]) => (
                                    <div key={day} className="ir-flex ir-flex-col ir-gap-2">
                                        <p className="ir-text-xs ir-font-semibold ir-uppercase ir-tracking-wide ir-text-muted-foreground">{formatDay(day)}</p>
                                        <ul className="ir-flex ir-flex-col ir-gap-1.5">
                                            {dayLogs.map((log) => {
                                                const cat = categoryMeta(log.category);
                                                const status = statusMeta(log.status);

                                                return (
                                                    <li key={log.id} className="ir-group ir-flex ir-items-center ir-gap-3 ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm">
                                                        <span className={`ir-size-2 ir-shrink-0 ir-rounded-full ${status.dot}`} title={status.label} />
                                                        <span className="ir-flex-1 ir-truncate">{log.description}</span>
                                                        {log.status !== 'done' && <Badge tone={status.tone}>{status.label}</Badge>}
                                                        {cat !== null ? (
                                                            <span className={`ir-inline-flex ir-items-center ir-gap-1 ir-rounded-full ir-px-2 ir-py-0.5 ir-text-xs ir-font-medium ${cat.chip}`}>
                                                                <cat.Icon className="ir-size-3" />
                                                                {cat.label}
                                                            </span>
                                                        ) : log.category != null ? (
                                                            <span className="ir-rounded-full ir-bg-muted ir-px-2 ir-py-0.5 ir-text-xs ir-text-muted-foreground">{log.category}</span>
                                                        ) : null}
                                                        {log.minutes != null && log.minutes > 0 && (
                                                            <span className="ir-w-14 ir-shrink-0 ir-text-right ir-tabular-nums ir-text-muted-foreground">{formatHours(log.minutes)} h</span>
                                                        )}
                                                        {log.screenshot_url != null && (
                                                            <a href={log.screenshot_url} target="_blank" rel="noopener noreferrer" title="Ver captura" className="ir-shrink-0">
                                                                <img src={log.screenshot_url} alt="" className="ir-size-8 ir-rounded ir-border ir-object-cover" />
                                                            </a>
                                                        )}
                                                        <div className="ir-flex ir-shrink-0 ir-items-center ir-gap-1 ir-opacity-60 ir-transition-opacity group-hover:ir-opacity-100">
                                                            <button type="button" className="ir-rounded ir-p-1 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground" title="Editar" onClick={() => startEdit(log)}>
                                                                <Pencil className="ir-size-3.5" />
                                                            </button>
                                                            <button type="button" className="ir-rounded ir-p-1 ir-text-muted-foreground hover:ir-bg-danger/10 hover:ir-text-danger" title="Eliminar" onClick={() => remove.mutate(log.id)}>
                                                                <Trash2 className="ir-size-3.5" />
                                                            </button>
                                                        </div>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Card>
                </>
            )}
        </div>
    );
}
