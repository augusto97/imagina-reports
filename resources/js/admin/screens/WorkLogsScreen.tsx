import { Trash2 } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';

import { useCreateSiteWorkLog, useDeleteWorkLog, useSiteWorkLogs, useSites } from '../api';
import { Button, Card, Field, Input } from '../components/ui';

const selectClass = 'ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm';

/** Common task categories — suggestions for the breakdown, free text still allowed. */
const CATEGORIES = ['Mantenimiento', 'Seguridad', 'Contenido', 'SEO', 'Rendimiento', 'Soporte', 'Diseño', 'Otro'];

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

/**
 * Fast day-to-day work logging (CLAUDE.md §11.5). Pick a site, type what you did and
 * (optionally) the minutes + category, hit Enter. The header shows hours invested and
 * task count for the month so you can see at a glance whether the hourly service paid off.
 */
export function WorkLogsScreen(): ReactElement {
    const { data: sites = [] } = useSites();
    const [siteId, setSiteId] = useState<number | null>(null);
    const [month, setMonth] = useState(currentMonth());

    const range = monthRange(month);
    const { data: logs = [] } = useSiteWorkLogs(siteId, range.from, range.to);
    const create = useCreateSiteWorkLog(siteId ?? 0);
    const remove = useDeleteWorkLog();

    const [description, setDescription] = useState('');
    const [minutes, setMinutes] = useState('');
    const [category, setCategory] = useState('');
    const [date, setDate] = useState(today());
    const [screenshot, setScreenshot] = useState<File | null>(null);
    const [fileKey, setFileKey] = useState(0); // bumped to clear the file input after submit

    const site = sites.find((candidate) => candidate.id === siteId);
    const totalMinutes = logs.reduce((sum, log) => sum + (log.minutes ?? 0), 0);
    const planHours = site?.plan_hours != null ? Number(site.plan_hours) : null;

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        if (siteId === null || description.trim() === '') {
            return;
        }
        create.mutate(
            {
                description: description.trim(),
                minutes: minutes === '' ? null : Number(minutes),
                category: category === '' ? null : category,
                performed_at: date,
                screenshot,
            },
            {
                onSuccess: () => {
                    setDescription('');
                    setMinutes('');
                    setScreenshot(null);
                    setFileKey((key) => key + 1);
                },
            },
        );
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <Card title="Registrar trabajo">
                <div className="ir-mb-4 ir-grid ir-max-w-xl ir-grid-cols-1 ir-gap-3 sm:ir-grid-cols-2">
                    <Field label="Sitio">
                        <select
                            className={selectClass}
                            value={siteId ?? ''}
                            onChange={(event) => setSiteId(event.target.value === '' ? null : Number(event.target.value))}
                        >
                            <option value="">Selecciona…</option>
                            {sites.map((candidate) => (
                                <option key={candidate.id} value={candidate.id}>
                                    {candidate.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Mes">
                        <Input type="month" value={month} onChange={(event) => setMonth(event.target.value)} />
                    </Field>
                </div>

                {siteId === null ? (
                    <p className="ir-text-sm ir-text-muted-foreground">Elige un sitio para empezar a registrar.</p>
                ) : (
                    <form onSubmit={submit} className="ir-flex ir-flex-wrap ir-items-end ir-gap-2">
                        <div className="ir-min-w-[16rem] ir-flex-1">
                            <Field label="¿Qué hiciste?">
                                <Input autoFocus value={description} onChange={(event) => setDescription(event.target.value)} placeholder="Ej. Actualicé plugins y limpié spam" />
                            </Field>
                        </div>
                        <Field label="Minutos">
                            <Input type="number" min="0" value={minutes} onChange={(event) => setMinutes(event.target.value)} className="ir-w-24" placeholder="opc." />
                        </Field>
                        <Field label="Categoría">
                            <input className={`${selectClass} ir-w-40`} list="ir-categories" value={category} onChange={(event) => setCategory(event.target.value)} placeholder="opc." />
                            <datalist id="ir-categories">
                                {CATEGORIES.map((option) => (
                                    <option key={option} value={option} />
                                ))}
                            </datalist>
                        </Field>
                        <Field label="Fecha">
                            <Input type="date" value={date} onChange={(event) => setDate(event.target.value)} className="ir-w-40" />
                        </Field>
                        <Field label="Captura (opc.)">
                            <input
                                key={fileKey}
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(event) => setScreenshot(event.target.files?.[0] ?? null)}
                                className="ir-w-44 ir-text-xs ir-text-muted-foreground file:ir-mr-2 file:ir-rounded file:ir-border-0 file:ir-bg-muted file:ir-px-2 file:ir-py-1"
                            />
                        </Field>
                        <Button type="submit" disabled={create.isPending || description.trim() === ''}>
                            Añadir
                        </Button>
                    </form>
                )}
            </Card>

            {siteId !== null && (
                <Card title={`Trabajo de ${site?.name ?? ''} · ${month}`}>
                    <div className="ir-mb-4 ir-flex ir-flex-wrap ir-gap-6">
                        <div>
                            <p className="ir-text-3xl ir-font-semibold">{formatHours(totalMinutes)} h</p>
                            <p className="ir-text-xs ir-text-muted-foreground">
                                Horas invertidas{planHours !== null ? ` · plan ${planHours} h` : ''}
                            </p>
                        </div>
                        <div>
                            <p className="ir-text-3xl ir-font-semibold">{logs.length}</p>
                            <p className="ir-text-xs ir-text-muted-foreground">Tareas registradas</p>
                        </div>
                        {planHours !== null && planHours > 0 && (
                            <div className="ir-min-w-[12rem] ir-flex-1">
                                <div className="ir-mb-1 ir-flex ir-justify-between ir-text-xs ir-text-muted-foreground">
                                    <span>Horas usadas vs plan</span>
                                    <span>{Math.round((totalMinutes / 60 / planHours) * 100)}%</span>
                                </div>
                                <div className="ir-h-2 ir-overflow-hidden ir-rounded ir-bg-muted">
                                    <div
                                        className="ir-h-full ir-rounded ir-bg-primary"
                                        style={{ width: `${Math.min(100, (totalMinutes / 60 / planHours) * 100)}%` }}
                                    />
                                </div>
                            </div>
                        )}
                    </div>

                    {logs.length === 0 ? (
                        <p className="ir-text-sm ir-text-muted-foreground">Aún no hay tareas este mes.</p>
                    ) : (
                        <ul className="ir-flex ir-flex-col ir-divide-y">
                            {logs.map((log) => (
                                <li key={log.id} className="ir-flex ir-items-center ir-gap-3 ir-py-2 ir-text-sm">
                                    <span className="ir-w-24 ir-shrink-0 ir-text-muted-foreground">{log.performed_at.slice(0, 10)}</span>
                                    <span className="ir-flex-1">{log.description}</span>
                                    {log.category != null && (
                                        <span className="ir-rounded ir-bg-muted ir-px-2 ir-py-0.5 ir-text-xs ir-text-muted-foreground">{log.category}</span>
                                    )}
                                    <span className="ir-w-16 ir-text-right ir-tabular-nums">{log.minutes != null ? `${formatHours(log.minutes)} h` : '—'}</span>
                                    {log.screenshot_url != null && (
                                        <a href={log.screenshot_url} target="_blank" rel="noopener noreferrer" title="Ver captura" className="ir-shrink-0">
                                            <img src={log.screenshot_url} alt="Captura del trabajo" className="ir-size-8 ir-rounded ir-border ir-object-cover" />
                                        </a>
                                    )}
                                    <button
                                        type="button"
                                        className="ir-text-muted-foreground hover:ir-text-red-500"
                                        title="Eliminar"
                                        onClick={() => remove.mutate(log.id)}
                                    >
                                        <Trash2 className="ir-size-4" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}
        </div>
    );
}
