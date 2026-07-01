import { type FormEvent, type ReactElement, useEffect, useState } from 'react';

import {
    CalendarClock,
    FileBarChart,
    FileDown,
    Lightbulb,
    Lock,
    Mail,
    MessageSquare,
    Plus,
    Repeat,
    RotateCw,
    Send,
    Settings2,
    Share2,
    ShieldOff,
    Sparkles,
    Trash2,
} from 'lucide-react';

import {
    useApproveReport,
    useCreateReportComment,
    useCreateReportDefinition,
    useCreateSchedule,
    useDeleteComment,
    useDeleteSchedule,
    useReportDeliveries,
    useRetryDelivery,
    useRetryFailedDeliveries,
    useSchedules,
    useDeleteReport,
    useDeleteReportDefinition,
    useDownloadReportPdf,
    useGenerateReport,
    useRegenerateReportAdvisory,
    useRegenerateReportNarrative,
    useReportComments,
    useReportDefinitions,
    useReportInsights,
    useReports,
    useReportTemplates,
    useSendReport,
    useSites,
    useSnapshotPeriods,
    useSyncSiteById,
    useUpdateReportAdvisory,
    useUpdateReportDefinition,
    useUpdateReportNarrative,
} from '../api';
import { RANGE_PRESETS } from '@shared/lib/dateRanges';
import { PeriodSyncMenu } from '../components/PeriodSyncMenu';
import { ShareDialog } from '../components/ShareDialog';
import { Badge, Button, Card, Field, Input, Modal, Select } from '../components/ui';
import type { ReportDefinitionDto, ReportSummary, ScheduleCadence, ScheduleDto } from '../types';

const inputClass = 'ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm';

const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** Human, Spanish status labels + badge tones (the API stores the English enum value). */
const STATUS: Record<string, { label: string; tone: 'neutral' | 'info' | 'success' }> = {
    draft: { label: 'Borrador', tone: 'neutral' },
    approved: { label: 'Aprobado', tone: 'info' },
    sent: { label: 'Enviado', tone: 'success' },
};

const capitalize = (value: string): string => value.charAt(0).toUpperCase() + value.slice(1);

/**
 * Turn a raw [start, end] range into a label a non-technical user reads instantly:
 * a full calendar month → «Junio 2026», a quarter → «Q2 2026», a year → «Año 2026»,
 * otherwise a short «1 jun – 30 jun 2026».
 */
function friendlyPeriod(startIso: string, endIso: string): string {
    const start = new Date(`${startIso.slice(0, 10)}T00:00:00`);
    const end = new Date(`${endIso.slice(0, 10)}T00:00:00`);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
        return `${startIso.slice(0, 10)} → ${endIso.slice(0, 10)}`;
    }

    const sameYear = start.getFullYear() === end.getFullYear();
    const startsFirst = start.getDate() === 1;
    const lastDayOfEndMonth = new Date(end.getFullYear(), end.getMonth() + 1, 0).getDate();
    const endsLast = end.getDate() === lastDayOfEndMonth;

    if (sameYear && startsFirst && endsLast) {
        // Whole calendar month.
        if (start.getMonth() === end.getMonth()) {
            return `${capitalize(MONTHS[start.getMonth()] ?? '')} ${start.getFullYear()}`;
        }
        // Whole quarter.
        if (start.getMonth() % 3 === 0 && end.getMonth() - start.getMonth() === 2) {
            return `Q${Math.floor(start.getMonth() / 3) + 1} ${start.getFullYear()}`;
        }
        // Whole year.
        if (start.getMonth() === 0 && end.getMonth() === 11) {
            return `Año ${start.getFullYear()}`;
        }
    }

    const fmt = (date: Date): string => date.toLocaleDateString('es', { day: 'numeric', month: 'short' });

    return `${fmt(start)} – ${fmt(end)} ${end.getFullYear()}`;
}

/** Format the generation timestamp as a readable date + time (or «—» when absent). */
function formatGeneratedAt(value: string | null): string {
    if (value === null || value === '') {
        return '—';
    }
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' });
}

/** Short date for the next scheduled run (or «—»). */
function formatDate(value: string): string {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('es', { day: 'numeric', month: 'long', year: 'numeric' });
}

const CADENCE_LABEL: Record<ScheduleCadence, string> = { monthly: 'Mensual', weekly: 'Semanal' };

/** Split a comma/semicolon/newline-separated string into a list of trimmed emails. */
function parseRecipients(raw: string): string[] {
    return raw
        .split(/[,\n;]+/)
        .map((value) => value.trim())
        .filter((value) => value !== '');
}

/** A small inline pill showing a definition's sharing visibility (Etapa D). */
function VisibilityTag({ definition }: { definition: ReportDefinitionDto }): ReactElement | null {
    if (definition.visibility === 'public') {
        return null;
    }
    const isPrivate = definition.visibility === 'private';
    const Icon = isPrivate ? ShieldOff : Lock;

    return (
        <Badge tone={isPrivate ? 'danger' : 'warning'}>
            <Icon className="ir-size-3" />
            {isPrivate ? 'Privado' : 'Contraseña'}
        </Badge>
    );
}

/* ------------------------------------------------------------------ */
/* Per-report tools (opened in a focused modal, one tab at a time).    */
/* ------------------------------------------------------------------ */

/** Internal notes + client-visible comments for a report (CLAUDE.md §11). */
function ReportCommentsPanel({ reportId }: { reportId: number }): ReactElement {
    const { data: comments = [] } = useReportComments(reportId);
    const create = useCreateReportComment(reportId);
    const remove = useDeleteComment();
    const [body, setBody] = useState('');
    const [visibility, setVisibility] = useState<'internal' | 'client'>('internal');

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        if (body.trim() === '') {
            return;
        }
        create.mutate({ body: body.trim(), visibility }, { onSuccess: () => setBody('') });
    };

    return (
        <div>
            <form onSubmit={submit} className="ir-mb-4 ir-flex ir-flex-col ir-gap-2">
                <textarea
                    className={inputClass}
                    rows={2}
                    value={body}
                    onChange={(event) => setBody(event.target.value)}
                    placeholder="Escribe una nota interna o un comentario para el cliente…"
                />
                <div className="ir-flex ir-items-center ir-gap-2">
                    <select className={`${inputClass} ir-w-56`} value={visibility} onChange={(event) => setVisibility(event.target.value as 'internal' | 'client')}>
                        <option value="internal">Nota interna (solo equipo)</option>
                        <option value="client">Visible para el cliente</option>
                    </select>
                    <Button type="submit" disabled={create.isPending || body.trim() === ''}>
                        Añadir
                    </Button>
                </div>
            </form>

            {comments.length === 0 ? (
                <p className="ir-text-sm ir-text-muted-foreground">Sin comentarios todavía.</p>
            ) : (
                <ul className="ir-flex ir-flex-col ir-divide-y">
                    {comments.map((comment) => (
                        <li key={comment.id} className="ir-flex ir-items-start ir-gap-3 ir-py-2 ir-text-sm">
                            <Badge tone={comment.visibility === 'client' ? 'accent' : 'neutral'}>{comment.visibility === 'client' ? 'Cliente' : 'Interna'}</Badge>
                            <span className="ir-flex-1">{comment.body}</span>
                            <span className="ir-shrink-0 ir-text-xs ir-text-muted-foreground">{comment.created_at.slice(0, 10)}</span>
                            <button type="button" className="ir-text-muted-foreground hover:ir-text-red-500" title="Eliminar" onClick={() => remove.mutate(comment.id)}>
                                <Trash2 className="ir-size-4" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** Edit or AI-regenerate a report's executive summary before sending (CLAUDE.md §10.6). */
function ReportNarrativePanel({ report }: { report: ReportSummary }): ReactElement {
    const update = useUpdateReportNarrative();
    const regenerate = useRegenerateReportNarrative();
    const [text, setText] = useState(report.executive_summary ?? '');

    return (
        <div>
            <p className="ir-mb-2 ir-text-xs ir-text-muted-foreground">
                Es el texto que el cliente lee al inicio del reporte. Edítalo o regenéralo con IA antes de enviar.
            </p>
            <textarea
                className={inputClass}
                rows={5}
                value={text}
                onChange={(event) => setText(event.target.value)}
                placeholder="El resumen se escribe solo al generar el reporte con IA. También puedes redactarlo a mano aquí."
            />
            <div className="ir-mt-3 ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                <Button onClick={() => update.mutate({ reportId: report.id, text })} disabled={update.isPending}>
                    {update.isPending ? 'Guardando…' : 'Guardar'}
                </Button>
                <Button variant="ghost" onClick={() => regenerate.mutate(report.id, { onSuccess: (next) => setText(next ?? '') })} disabled={regenerate.isPending}>
                    <Sparkles className="ir-size-3.5" />
                    {regenerate.isPending ? 'Regenerando…' : 'Regenerar con IA'}
                </Button>
                {update.isSuccess && <span className="ir-text-xs ir-text-emerald-600">Guardado.</span>}
                {regenerate.isError && <span className="ir-text-xs ir-text-red-500">No se pudo regenerar (revisa la API key de la agencia).</span>}
            </div>
        </div>
    );
}

/** Edit or AI-regenerate a report's advisory ("Diagnóstico y recomendaciones"). */
function ReportAdvisoryPanel({ report }: { report: ReportSummary }): ReactElement {
    const update = useUpdateReportAdvisory();
    const regenerate = useRegenerateReportAdvisory();
    const [text, setText] = useState(report.advisory ?? '');

    return (
        <div>
            <p className="ir-mb-2 ir-text-xs ir-text-muted-foreground">
                Lectura consultiva de la condición del sitio (usa el histórico, el mantenimiento y las caídas). Recomienda solo cuando los datos lo justifican. Edítalo o regenéralo antes de enviar.
            </p>
            <textarea
                className={inputClass}
                rows={5}
                value={text}
                onChange={(event) => setText(event.target.value)}
                placeholder="El diagnóstico se escribe solo al generar el reporte con IA. También puedes redactarlo a mano aquí."
            />
            <div className="ir-mt-3 ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                <Button onClick={() => update.mutate({ reportId: report.id, text })} disabled={update.isPending}>
                    {update.isPending ? 'Guardando…' : 'Guardar'}
                </Button>
                <Button variant="ghost" onClick={() => regenerate.mutate(report.id, { onSuccess: (next) => setText(next ?? '') })} disabled={regenerate.isPending}>
                    <Lightbulb className="ir-size-3.5" />
                    {regenerate.isPending ? 'Regenerando…' : 'Regenerar con IA'}
                </Button>
                {update.isSuccess && <span className="ir-text-xs ir-text-emerald-600">Guardado.</span>}
                {regenerate.isError && <span className="ir-text-xs ir-text-red-500">No se pudo regenerar (revisa la API key de la agencia).</span>}
            </div>
        </div>
    );
}

/** AI observations for the period (fetched fresh when the tab opens). */
function ReportInsightsPanel({ reportId }: { reportId: number }): ReactElement {
    const insights = useReportInsights();

    useEffect(() => {
        insights.mutate(reportId);
        // Run once per report; `insights` is a stable mutation object.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [reportId]);

    if (insights.isPending || insights.isIdle) {
        return <p className="ir-text-sm ir-text-muted-foreground">Analizando los datos del reporte…</p>;
    }
    if (insights.isError) {
        return <p className="ir-text-sm ir-text-red-500">No se pudieron generar los insights.</p>;
    }
    if ((insights.data ?? []).length === 0) {
        return <p className="ir-text-sm ir-text-muted-foreground">Sin datos suficientes para generar insights.</p>;
    }

    return (
        <ul className="ir-flex ir-flex-col ir-gap-2">
            {(insights.data ?? []).map((insight, index) => (
                <li key={index} className="ir-flex ir-gap-2 ir-text-sm">
                    <Sparkles className="ir-mt-0.5 ir-size-4 ir-shrink-0 ir-text-primary" />
                    <span>{insight}</span>
                </li>
            ))}
        </ul>
    );
}

const DELIVERY_STATUS: Record<string, { label: string; tone: 'success' | 'danger' | 'neutral' }> = {
    sent: { label: 'Enviado', tone: 'success' },
    failed: { label: 'Falló', tone: 'danger' },
    pending: { label: 'Pendiente', tone: 'neutral' },
};

/** The email delivery log of a report: who received it, when, failures + retry. */
function ReportDeliveriesPanel({ reportId }: { reportId: number }): ReactElement {
    const { data: deliveries = [], isLoading } = useReportDeliveries(reportId);
    const retry = useRetryDelivery();
    const retryFailed = useRetryFailedDeliveries(reportId);
    const failedCount = deliveries.filter((delivery) => delivery.status === 'failed').length;

    if (isLoading) {
        return <p className="ir-text-sm ir-text-muted-foreground">Cargando envíos…</p>;
    }
    if (deliveries.length === 0) {
        return <p className="ir-text-sm ir-text-muted-foreground">Este reporte aún no se ha enviado. Usa «Enviar» en la lista para mandarlo a los destinatarios.</p>;
    }

    return (
        <div className="ir-flex ir-flex-col ir-gap-3">
            {failedCount > 0 && (
                <div className="ir-flex ir-items-center ir-justify-between ir-gap-3 ir-rounded-md ir-bg-danger/5 ir-px-3 ir-py-2">
                    <span className="ir-text-xs ir-text-danger">{failedCount} envío(s) fallaron.</span>
                    <Button variant="outline" size="sm" onClick={() => retryFailed.mutate()} disabled={retryFailed.isPending}>
                        {retryFailed.isPending ? 'Reenviando…' : 'Reenviar los fallidos'}
                    </Button>
                </div>
            )}
            <ul className="ir-flex ir-flex-col ir-divide-y">
                {deliveries.map((delivery) => {
                    const status = DELIVERY_STATUS[delivery.status] ?? { label: delivery.status, tone: 'neutral' as const };

                    return (
                        <li key={delivery.id} className="ir-flex ir-items-center ir-gap-3 ir-py-2 ir-text-sm">
                            <span className="ir-min-w-0 ir-flex-1 ir-truncate">
                                {delivery.recipient}
                                {delivery.error != null && <span className="ir-ml-2 ir-text-xs ir-text-danger" title={delivery.error}>({delivery.error.slice(0, 40)})</span>}
                            </span>
                            <span className="ir-shrink-0 ir-text-xs ir-text-muted-foreground">{formatGeneratedAt(delivery.sent_at ?? delivery.created_at)}</span>
                            <Badge tone={status.tone}>{status.label}</Badge>
                            <Button variant="ghost" size="sm" title="Reenviar a este destinatario" onClick={() => retry.mutate(delivery.id)} disabled={retry.isPending}>
                                <RotateCw className="ir-size-3.5" />
                            </Button>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

type ToolTab = 'summary' | 'advisory' | 'comments' | 'insights' | 'deliveries';

/** One focused modal for a report's editing tools, one tab at a time. */
function ReportToolsModal({ report, initialTab, onClose }: { report: ReportSummary; initialTab: ToolTab; onClose: () => void }): ReactElement {
    const hasAdvisory = report.has_advisory === true;
    const tabs: { key: ToolTab; label: string; icon: typeof Sparkles }[] = [
        { key: 'summary', label: 'Resumen', icon: Sparkles },
        ...(hasAdvisory ? [{ key: 'advisory' as const, label: 'Diagnóstico', icon: Lightbulb }] : []),
        { key: 'comments', label: 'Comentarios', icon: MessageSquare },
        { key: 'insights', label: 'Insights', icon: Sparkles },
        { key: 'deliveries', label: 'Envíos', icon: Mail },
    ];
    const [tab, setTab] = useState<ToolTab>(initialTab === 'advisory' && !hasAdvisory ? 'summary' : initialTab);

    return (
        <Modal onClose={onClose} className="ir-max-w-2xl">
            <Card
                title="Herramientas del reporte"
                description={`${friendlyPeriod(report.period_start, report.period_end)}`}
                actions={
                    <Button variant="ghost" size="sm" onClick={onClose}>
                        Cerrar
                    </Button>
                }
            >
                <div className="ir-mb-4 ir-flex ir-flex-wrap ir-gap-1 ir-rounded-lg ir-bg-muted ir-p-1">
                    {tabs.map((entry) => (
                        <button
                            key={entry.key}
                            type="button"
                            onClick={() => setTab(entry.key)}
                            className={`ir-inline-flex ir-items-center ir-gap-1.5 ir-rounded-md ir-px-3 ir-py-1.5 ir-text-xs ir-font-medium ir-transition-colors ${
                                tab === entry.key ? 'ir-bg-card ir-text-foreground ir-shadow-ir-xs' : 'ir-text-muted-foreground hover:ir-text-foreground'
                            }`}
                        >
                            <entry.icon className="ir-size-3.5" />
                            {entry.label}
                        </button>
                    ))}
                </div>

                {tab === 'summary' && <ReportNarrativePanel report={report} />}
                {tab === 'advisory' && hasAdvisory && <ReportAdvisoryPanel report={report} />}
                {tab === 'comments' && <ReportCommentsPanel reportId={report.id} />}
                {tab === 'insights' && <ReportInsightsPanel reportId={report.id} />}
                {tab === 'deliveries' && <ReportDeliveriesPanel reportId={report.id} />}
            </Card>
        </Modal>
    );
}

/* ------------------------------------------------------------------ */
/* Create + generate flows (modals).                                   */
/* ------------------------------------------------------------------ */

/** Step 1: configure a new report for a site (name, template, recipients). */
function CreateReportModal({ onClose }: { onClose: () => void }): ReactElement {
    const { data: sites = [] } = useSites();
    const { data: templates = [] } = useReportTemplates();
    const create = useCreateReportDefinition();
    const [site, setSite] = useState('');
    const [name, setName] = useState('');
    const [template, setTemplate] = useState('');
    const [recipients, setRecipients] = useState('');

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        if (site === '' || name.trim() === '') {
            return;
        }
        create.mutate(
            {
                site_id: Number(site),
                name: name.trim(),
                template_id: template === '' ? undefined : Number(template),
                recipients: parseRecipients(recipients),
            },
            { onSuccess: onClose },
        );
    };

    return (
        <Modal onClose={onClose}>
            <Card
                title="Nuevo reporte"
                description="Configúralo una vez por cliente. Luego lo generas cada periodo con un clic."
                actions={
                    <Button variant="ghost" size="sm" onClick={onClose}>
                        Cerrar
                    </Button>
                }
            >
                <form onSubmit={submit} className="ir-flex ir-flex-col ir-gap-3">
                    <Field label="Cliente / sitio">
                        <Select value={site} onChange={(event) => setSite(event.target.value)}>
                            <option value="">Selecciona un sitio…</option>
                            {sites.map((entry) => (
                                <option key={entry.id} value={entry.id}>
                                    {entry.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Nombre del reporte" hint="Ej. «Informe mensual», «Reporte de SEO».">
                        <Input value={name} onChange={(event) => setName(event.target.value)} placeholder="Informe mensual" />
                    </Field>
                    <Field label="Plantilla" hint="Define qué bloques y métricas se muestran. Puedes cambiarla luego.">
                        <Select value={template} onChange={(event) => setTemplate(event.target.value)}>
                            <option value="">Plantilla por defecto</option>
                            {templates.map((entry) => (
                                <option key={entry.id} value={entry.id}>
                                    {entry.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Destinatarios" hint="Emails separados por coma. Opcional; puedes añadirlos después.">
                        <Input placeholder="cliente@empresa.com, pm@agencia.com" value={recipients} onChange={(event) => setRecipients(event.target.value)} />
                    </Field>
                    {create.isError && <p className="ir-text-xs ir-text-danger">No se pudo crear. Revisa los datos e inténtalo de nuevo.</p>}
                    <div className="ir-mt-1 ir-flex ir-justify-end ir-gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={create.isPending || site === '' || name.trim() === ''}>
                            {create.isPending ? 'Creando…' : 'Crear reporte'}
                        </Button>
                    </div>
                </form>
            </Card>
        </Modal>
    );
}

/** Step 2: generate an edition of a configured report for a chosen period. */
function GenerateModal({ definition, onClose }: { definition: ReportDefinitionDto; onClose: () => void }): ReactElement {
    const generate = useGenerateReport();
    const syncRange = useSyncSiteById();
    const { data: snapshotPeriods = [] } = useSnapshotPeriods(definition.site_id);
    const [start, setStart] = useState('');
    const [end, setEnd] = useState('');

    // Default to the most recent period that actually has synced data.
    useEffect(() => {
        const latest = snapshotPeriods[0];
        if (latest !== undefined && start === '' && end === '') {
            setStart(latest.period_start.slice(0, 10));
            setEnd(latest.period_end.slice(0, 10));
        }
    }, [snapshotPeriods, start, end]);

    const periodHasData =
        start !== '' && end !== '' && snapshotPeriods.some((period) => period.period_start.slice(0, 10) <= end && period.period_end.slice(0, 10) >= start);

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        if (start === '' || end === '') {
            return;
        }
        generate.mutate({ report_definition_id: definition.id, period_start: start, period_end: end });
    };

    return (
        <Modal onClose={onClose}>
            <Card
                title={`Generar · ${definition.name}`}
                description="Elige el periodo. Se creará el PDF y el portal del cliente."
                actions={
                    <Button variant="ghost" size="sm" onClick={onClose}>
                        Cerrar
                    </Button>
                }
            >
                <form onSubmit={submit} className="ir-flex ir-flex-col ir-gap-3">
                    <Field label="Periodo rápido">
                        <Select
                            value=""
                            onChange={(event) => {
                                const preset = RANGE_PRESETS.find((entry) => entry.key === event.target.value);
                                if (preset !== undefined) {
                                    const range = preset.range();
                                    setStart(range.start);
                                    setEnd(range.end);
                                }
                            }}
                        >
                            <option value="">Elige un rango… (o usa las fechas)</option>
                            {RANGE_PRESETS.map((preset) => (
                                <option key={preset.key} value={preset.key}>
                                    {preset.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <div className="ir-flex ir-gap-3">
                        <Field label="Desde">
                            <Input type="date" value={start} onChange={(event) => setStart(event.target.value)} />
                        </Field>
                        <Field label="Hasta">
                            <Input type="date" value={end} onChange={(event) => setEnd(event.target.value)} />
                        </Field>
                    </div>

                    {start !== '' && end !== '' && (
                        periodHasData ? (
                            <p className="ir-text-xs ir-text-emerald-600">✓ Hay datos sincronizados que cubren «{friendlyPeriod(start, end)}».</p>
                        ) : (
                            <div className="ir-flex ir-flex-col ir-gap-2 ir-rounded-md ir-bg-amber-50 ir-p-3">
                                <p className="ir-text-xs ir-text-amber-700">
                                    ⚠ No hay datos sincronizados para este rango exacto. Las fuentes agregan los totales del rango en origen: sincroniza este
                                    rango y luego genera.
                                    {snapshotPeriods.length > 0 && (
                                        <> Disponibles: {snapshotPeriods.slice(0, 6).map((period) => friendlyPeriod(period.period_start, period.period_end)).join(', ')}.</>
                                    )}
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="ir-self-start"
                                    onClick={() => syncRange.mutate({ siteId: definition.site_id, period_start: start, period_end: end })}
                                    disabled={syncRange.isPending}
                                >
                                    {syncRange.isPending ? 'Sincronizando…' : 'Sincronizar este rango'}
                                </Button>
                                {syncRange.isSuccess && <p className="ir-text-xs ir-text-emerald-700">Sincronización encolada. Espera unos segundos y vuelve a comprobar.</p>}
                            </div>
                        )
                    )}

                    {generate.isSuccess ? (
                        <div className="ir-flex ir-items-center ir-justify-between ir-gap-3 ir-rounded-md ir-bg-emerald-50 ir-p-3">
                            <p className="ir-text-xs ir-text-emerald-700">Generación encolada. Aparecerá en el historial en unos segundos.</p>
                            <Button type="button" size="sm" onClick={onClose}>
                                Listo
                            </Button>
                        </div>
                    ) : (
                        <div className="ir-mt-1 ir-flex ir-justify-end ir-gap-2">
                            <Button type="button" variant="ghost" onClick={onClose}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={generate.isPending || start === '' || end === ''}>
                                <CalendarClock className="ir-size-3.5" />
                                {generate.isPending ? 'Generando…' : 'Generar reporte'}
                            </Button>
                        </div>
                    )}
                </form>
            </Card>
        </Modal>
    );
}

/* ------------------------------------------------------------------ */
/* One configured report + its generation history.                     */
/* ------------------------------------------------------------------ */

function ReportConfigCard({
    definition,
    reports,
    schedule,
    siteName,
    templateName,
    templates,
    onGenerate,
    onShare,
    onTools,
}: {
    definition: ReportDefinitionDto;
    reports: ReportSummary[];
    schedule: ScheduleDto | null;
    siteName: string;
    templateName: string;
    templates: { id: number; name: string }[];
    onGenerate: (definition: ReportDefinitionDto) => void;
    onShare: (definition: ReportDefinitionDto) => void;
    onTools: (report: ReportSummary, tab: ToolTab) => void;
}): ReactElement {
    const updateDefinition = useUpdateReportDefinition();
    const deleteDefinition = useDeleteReportDefinition();
    const generate = useGenerateReport();
    const approve = useApproveReport();
    const send = useSendReport();
    const downloadPdf = useDownloadReportPdf();
    const deleteReport = useDeleteReport();
    const createSchedule = useCreateSchedule();
    const deleteSchedule = useDeleteSchedule();
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [recipients, setRecipients] = useState((definition.recipients ?? []).join(', '));

    const saveRecipients = (): void => {
        const next = parseRecipients(recipients);
        if (next.join(',') !== (definition.recipients ?? []).join(',')) {
            updateDefinition.mutate({ id: definition.id, recipients: next });
        }
    };

    const changeCadence = (value: string): void => {
        if (value === '') {
            if (schedule !== null) {
                deleteSchedule.mutate(schedule.id);
            }
        } else {
            createSchedule.mutate({ report_definition_id: definition.id, cadence: value as ScheduleCadence });
        }
    };
    const noRecipients = (definition.recipients ?? []).length === 0;

    return (
        <section className="ir-rounded-lg ir-border ir-bg-card ir-shadow-ir-sm">
            {/* Header: what this report is + primary actions. */}
            <header className="ir-flex ir-flex-wrap ir-items-start ir-justify-between ir-gap-3 ir-border-b ir-px-5 ir-py-4">
                <div className="ir-min-w-0">
                    <h3 className="ir-flex ir-flex-wrap ir-items-center ir-gap-2 ir-text-sm ir-font-semibold ir-tracking-tight">
                        {definition.name}
                        <VisibilityTag definition={definition} />
                        {schedule !== null && (
                            <Badge tone="info" className="ir-font-medium">
                                <Repeat className="ir-size-3" />
                                Automático · {CADENCE_LABEL[schedule.cadence]}
                            </Badge>
                        )}
                    </h3>
                    <p className="ir-mt-0.5 ir-truncate ir-text-xs ir-text-muted-foreground">
                        {siteName} · Plantilla: {templateName}
                    </p>
                </div>
                <div className="ir-flex ir-shrink-0 ir-items-center ir-gap-2">
                    <Button variant="accent" size="sm" onClick={() => onGenerate(definition)}>
                        <Plus className="ir-size-3.5" />
                        Generar
                    </Button>
                    <Button variant="outline" size="sm" title="Compartir / privacidad" onClick={() => onShare(definition)}>
                        <Share2 className="ir-size-3.5" />
                        <span className="ir-ml-1 ir-hidden sm:ir-inline">Compartir</span>
                    </Button>
                    <Button variant="ghost" size="sm" title="Ajustes del reporte" onClick={() => setSettingsOpen((open) => !open)}>
                        <Settings2 className="ir-size-3.5" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        title="Eliminar este reporte y todo su historial"
                        onClick={() => {
                            if (window.confirm(`¿Eliminar «${definition.name}» y todos sus reportes generados? No se puede deshacer.`)) {
                                deleteDefinition.mutate(definition.id);
                            }
                        }}
                        disabled={deleteDefinition.isPending}
                    >
                        <Trash2 className="ir-size-3.5 ir-text-danger" />
                    </Button>
                </div>
            </header>

            {/* Collapsible settings: template + recipients. Hidden by default to keep it clean. */}
            {settingsOpen && (
                <div className="ir-grid ir-gap-3 ir-border-b ir-bg-muted/30 ir-px-5 ir-py-4 sm:ir-grid-cols-2">
                    <Field label="Plantilla">
                        <Select
                            value={definition.template_id ?? ''}
                            onChange={(event) => updateDefinition.mutate({ id: definition.id, template_id: event.target.value === '' ? null : Number(event.target.value) })}
                        >
                            <option value="">Plantilla por defecto</option>
                            {templates.map((template) => (
                                <option key={template.id} value={template.id}>
                                    {template.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Destinatarios (email)">
                        <Input value={recipients} onChange={(event) => setRecipients(event.target.value)} onBlur={saveRecipients} placeholder="cliente@empresa.com, pm@agencia.com" />
                    </Field>
                    <div className="sm:ir-col-span-2">
                        <Field
                            label="Automatización"
                            hint={
                                schedule !== null
                                    ? `Se genera y envía por email solo. Próximo envío: ${formatDate(schedule.next_run_at)}.`
                                    : 'Genera y envía el reporte por email automáticamente cada periodo, sin que hagas nada.'
                            }
                        >
                            <Select value={schedule?.cadence ?? ''} onChange={(event) => changeCadence(event.target.value)} disabled={createSchedule.isPending || deleteSchedule.isPending}>
                                <option value="">Manual (sin automatización)</option>
                                <option value="monthly">Cada mes (el mes que acaba de terminar)</option>
                                <option value="weekly">Cada semana (la semana que acaba de terminar)</option>
                            </Select>
                        </Field>
                        {schedule !== null && noRecipients && (
                            <p className="ir-mt-1.5 ir-text-xs ir-text-amber-600">⚠ No hay destinatarios. Añade al menos un email arriba para que el envío automático llegue a alguien.</p>
                        )}
                    </div>
                </div>
            )}

            {/* Generation history. */}
            <div className="ir-px-5 ir-py-4">
                {reports.length === 0 ? (
                    <div className="ir-flex ir-flex-col ir-items-center ir-gap-2 ir-rounded-md ir-border ir-border-dashed ir-py-8 ir-text-center">
                        <CalendarClock className="ir-size-5 ir-text-muted-foreground" />
                        <p className="ir-text-sm ir-text-muted-foreground">Aún no has generado este reporte.</p>
                        <Button variant="accent" size="sm" onClick={() => onGenerate(definition)}>
                            <Plus className="ir-size-3.5" />
                            Generar el primero
                        </Button>
                    </div>
                ) : (
                    <div className="ir-flex ir-flex-col ir-divide-y">
                        {reports.map((report) => {
                            const status = STATUS[report.status] ?? { label: report.status, tone: 'neutral' as const };
                            const hidden = report.hidden_metrics ?? [];

                            return (
                                <div key={report.id} className="ir-flex ir-flex-wrap ir-items-center ir-gap-x-4 ir-gap-y-2 ir-py-3">
                                    {/* Period + meta */}
                                    <div className="ir-min-w-[10rem] ir-flex-1">
                                        <p className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-font-medium">
                                            {friendlyPeriod(report.period_start, report.period_end)}
                                            <Badge tone={status.tone}>{status.label}</Badge>
                                        </p>
                                        <p className="ir-mt-0.5 ir-flex ir-flex-wrap ir-items-center ir-gap-x-2 ir-text-xs ir-text-muted-foreground">
                                            <span>Generado {formatGeneratedAt(report.created_at)}</span>
                                            {report.health_score !== null && <span>· Salud {report.health_score}</span>}
                                            {hidden.length > 0 && (
                                                <span className="ir-text-amber-600" title={`Sin datos: ${hidden.join(', ')}. Sincroniza el periodo y regenera.`}>
                                                    · ⚠ {hidden.length} sin datos
                                                </span>
                                            )}
                                        </p>
                                        {hidden.length > 0 && (
                                            <div className="ir-mt-1">
                                                <PeriodSyncMenu siteId={definition.site_id} periodStart={report.period_start.slice(0, 10)} periodEnd={report.period_end.slice(0, 10)} />
                                            </div>
                                        )}
                                    </div>

                                    {/* Actions */}
                                    <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-1.5">
                                        <Button variant="outline" size="sm" title="Abrir el portal interactivo del cliente" onClick={() => window.open(`/reports/${report.public_token}`, '_blank', 'noopener')}>
                                            Ver
                                        </Button>
                                        <Button variant="outline" size="sm" title="Descargar el PDF" onClick={() => downloadPdf.mutate(report.id)} disabled={downloadPdf.isPending && downloadPdf.variables === report.id}>
                                            <FileDown className="ir-size-3.5" />
                                            {downloadPdf.isPending && downloadPdf.variables === report.id ? '…' : 'PDF'}
                                        </Button>
                                        <Button variant="ghost" size="sm" title="Resumen, diagnóstico, comentarios e insights" onClick={() => onTools(report, 'summary')}>
                                            <Sparkles className="ir-size-3.5" />
                                            Herramientas
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            title="Volver a generar este periodo (tras sincronizar o editar la plantilla)"
                                            onClick={() => generate.mutate({ report_definition_id: report.report_definition_id, period_start: report.period_start.slice(0, 10), period_end: report.period_end.slice(0, 10) })}
                                            disabled={generate.isPending}
                                        >
                                            <RotateCw className="ir-size-3.5" />
                                        </Button>
                                        {report.status === 'draft' ? (
                                            <Button variant="accent" size="sm" title="Aprobar para poder enviarlo" onClick={() => approve.mutate(report.id)} disabled={approve.isPending}>
                                                Aprobar
                                            </Button>
                                        ) : (
                                            <Button variant="accent" size="sm" title="Enviar por email a los destinatarios" onClick={() => send.mutate(report.id)} disabled={send.isPending}>
                                                <Send className="ir-size-3.5" />
                                                {report.status === 'sent' ? 'Reenviar' : 'Enviar'}
                                            </Button>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            title="Eliminar este reporte generado"
                                            onClick={() => {
                                                if (window.confirm('¿Eliminar este reporte? No se puede deshacer.')) {
                                                    deleteReport.mutate(report.id);
                                                }
                                            }}
                                            disabled={deleteReport.isPending}
                                        >
                                            <Trash2 className="ir-size-3.5 ir-text-danger" />
                                        </Button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Screen.                                                             */
/* ------------------------------------------------------------------ */

export function ReportsScreen(): ReactElement {
    const { data: sites = [] } = useSites();
    const { data: templates = [] } = useReportTemplates();
    const { data: definitions = [] } = useReportDefinitions();
    const { data: reports = [] } = useReports();
    const { data: schedules = [] } = useSchedules();

    const [createOpen, setCreateOpen] = useState(false);
    const [generateFor, setGenerateFor] = useState<ReportDefinitionDto | null>(null);
    const [sharingDefinition, setSharingDefinition] = useState<ReportDefinitionDto | null>(null);
    const [tools, setTools] = useState<{ report: ReportSummary; tab: ToolTab } | null>(null);

    const siteName = (id: number): string => sites.find((site) => site.id === id)?.name ?? `Sitio #${id}`;
    const templateName = (id: number | null): string =>
        id === null ? 'Por defecto' : (templates.find((template) => template.id === id)?.name ?? `#${id}`);
    const latestTokenForDefinition = (definitionId: number): string | null =>
        reports.find((report) => report.report_definition_id === definitionId)?.public_token ?? null;

    // Group each definition's generated reports, newest first.
    const reportsFor = (definitionId: number): ReportSummary[] =>
        reports
            .filter((report) => report.report_definition_id === definitionId)
            .sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? ''));

    // Keep the tools modal's report in sync with fresh data (e.g. after regenerate).
    const toolsReport = tools !== null ? (reports.find((report) => report.id === tools.report.id) ?? tools.report) : null;

    return (
        <div className="ir-flex ir-flex-col ir-gap-5">
            {/* Header + primary CTA. */}
            <div className="ir-flex ir-flex-wrap ir-items-start ir-justify-between ir-gap-3">
                <div>
                    <h1 className="ir-text-lg ir-font-semibold ir-tracking-tight">Reportes</h1>
                    <p className="ir-mt-1 ir-max-w-2xl ir-text-sm ir-text-muted-foreground">
                        Configura un reporte por cliente una vez y genéralo cada periodo. Cada generación crea un PDF y un portal interactivo para el cliente.
                    </p>
                </div>
                <Button onClick={() => setCreateOpen(true)}>
                    <Plus className="ir-size-4" />
                    Nuevo reporte
                </Button>
            </div>

            {/* 3-step hint — makes the flow obvious at a glance. */}
            <div className="ir-grid ir-gap-3 sm:ir-grid-cols-3">
                {[
                    { n: 1, title: 'Configura', text: 'Crea un reporte para el cliente: nombre, plantilla y destinatarios.' },
                    { n: 2, title: 'Genera', text: 'Elige el periodo y genera. Se crea el PDF y el portal del cliente.' },
                    { n: 3, title: 'Revisa y envía', text: 'Ajusta el resumen IA, apruébalo y envíalo. O automatízalo para que salga solo cada mes.' },
                ].map((step) => (
                    <div key={step.n} className="ir-flex ir-items-start ir-gap-3 ir-rounded-lg ir-border ir-bg-card ir-p-3">
                        <span className="ir-flex ir-size-6 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-full ir-bg-accent/10 ir-text-xs ir-font-semibold ir-text-accent">{step.n}</span>
                        <div>
                            <p className="ir-text-sm ir-font-medium">{step.title}</p>
                            <p className="ir-mt-0.5 ir-text-xs ir-text-muted-foreground">{step.text}</p>
                        </div>
                    </div>
                ))}
            </div>

            {/* Configured reports + their history. */}
            {definitions.length === 0 ? (
                <div className="ir-flex ir-flex-col ir-items-center ir-gap-3 ir-rounded-lg ir-border ir-border-dashed ir-bg-card ir-py-16 ir-text-center">
                    <span className="ir-flex ir-size-12 ir-items-center ir-justify-center ir-rounded-xl ir-bg-muted ir-text-muted-foreground">
                        <FileBarChart className="ir-size-6" />
                    </span>
                    <div>
                        <p className="ir-text-sm ir-font-medium">Aún no tienes reportes configurados</p>
                        <p className="ir-mt-1 ir-max-w-sm ir-text-xs ir-text-muted-foreground">
                            Crea el primero para un cliente. Después podrás generarlo cada mes en segundos.
                        </p>
                    </div>
                    <Button onClick={() => setCreateOpen(true)}>
                        <Plus className="ir-size-4" />
                        Nuevo reporte
                    </Button>
                </div>
            ) : (
                <div className="ir-flex ir-flex-col ir-gap-4">
                    {definitions.map((definition) => (
                        <ReportConfigCard
                            key={definition.id}
                            definition={definition}
                            reports={reportsFor(definition.id)}
                            schedule={schedules.find((entry) => entry.report_definition_id === definition.id) ?? null}
                            siteName={siteName(definition.site_id)}
                            templateName={templateName(definition.template_id)}
                            templates={templates}
                            onGenerate={setGenerateFor}
                            onShare={setSharingDefinition}
                            onTools={(report, tab) => setTools({ report, tab })}
                        />
                    ))}
                </div>
            )}

            {/* Modals. */}
            {createOpen && <CreateReportModal onClose={() => setCreateOpen(false)} />}
            {generateFor !== null && <GenerateModal definition={generateFor} onClose={() => setGenerateFor(null)} />}
            {toolsReport !== null && tools !== null && <ReportToolsModal report={toolsReport} initialTab={tools.tab} onClose={() => setTools(null)} />}
            {sharingDefinition !== null && (
                <ShareDialog definition={sharingDefinition} publicToken={latestTokenForDefinition(sharingDefinition.id)} onClose={() => setSharingDefinition(null)} />
            )}
        </div>
    );
}
