import { type FormEvent, type ReactElement, type ReactNode, useEffect, useMemo, useState } from 'react';

import {
    CalendarClock,
    Check,
    ChevronDown,
    Eye,
    EyeOff,
    FileBarChart,
    FileDown,
    FileText,
    Lightbulb,
    Lock,
    Mail,
    MessageCircleQuestion,
    MessageSquare,
    Plus,
    Repeat,
    Search,
    RotateCw,
    Send,
    Settings2,
    Share2,
    ShieldOff,
    Sparkles,
    Trash2,
    X,
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
import { apiErrorMessage } from '@shared/lib/api';
import { RANGE_PRESETS } from '@shared/lib/dateRanges';
import { ReportConversation } from '../components/ReportConversation';
import { PeriodSyncMenu } from '../components/PeriodSyncMenu';
import { ShareDialog } from '../components/ShareDialog';
import { ActionMenu, Badge, Button, Card, EmptyState, Field, Input, Modal, PageHeader, Segmented, Select, Tabs } from '../components/ui';
import { useAdminUi } from '../store';
import type { ReportDefinitionDto, ReportSummary, ScheduleCadence, ScheduleDto } from '../types';

const inputClass = 'ir-w-full ir-rounded-lg ir-border ir-bg-card ir-px-3 ir-py-2.5 ir-text-sm ir-leading-relaxed ir-shadow-ir-xs ir-outline-none ir-transition placeholder:ir-text-muted-foreground/70 focus:ir-border-accent focus:ir-ring-2 focus:ir-ring-ring/40';

const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** Human, Spanish status labels + badge tones (the API stores the English enum value). */
const STATUS: Record<string, { label: string; tone: 'neutral' | 'info' | 'success' | 'warning' }> = {
    draft: { label: 'Por revisar', tone: 'warning' },
    approved: { label: 'Listo para enviar', tone: 'info' },
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

function shortDate(value: string): string {
    return new Date(value).toLocaleDateString('es', { day: 'numeric', month: 'short' }).replace('.', '');
}

/** Did the client open it? The retention signal: a sent report nobody read is a warning. */
function ViewedBadge({ report }: { report: ReportSummary }): ReactElement | null {
    const views = report.view_count ?? 0;
    if (views > 0 && report.last_viewed_at != null) {
        const first = report.first_viewed_at ?? report.last_viewed_at;

        return (
            <span className="ir-inline-flex ir-items-center ir-gap-1 ir-font-medium ir-text-success" title={`Primera visita: ${formatGeneratedAt(first)} · Última: ${formatGeneratedAt(report.last_viewed_at)}`}>
                <Eye className="ir-size-3" />
                {views === 1 ? `Visto el ${shortDate(report.last_viewed_at)}` : `Visto ${views} veces · último ${shortDate(report.last_viewed_at)}`}
                <span className="ir-font-normal ir-text-muted-foreground">·</span>
            </span>
        );
    }
    if (report.status === 'sent') {
        return (
            <span className="ir-inline-flex ir-items-center ir-gap-1 ir-text-warning" title="El cliente todavía no abrió el enlace del reporte.">
                <EyeOff className="ir-size-3" />
                Aún sin abrir
                <span className="ir-text-muted-foreground">·</span>
            </span>
        );
    }

    return null;
}

/**
 * Human label for a hidden metric key (`site_agent.updates_log` → "Agente: actualizaciones
 * aplicadas (detalle)"), so the "sin datos" hint reads plainly instead of showing raw keys.
 * Falls back to a de-prefixed, humanized form for anything not in the map.
 */
const METRIC_LABELS: Record<string, string> = {
    'site_agent.updates_log': 'Agente: historial de actualizaciones aplicadas',
    'site_agent.updates_applied': 'Agente: actualizaciones aplicadas (nº)',
    'site_agent.abandoned_plugins': 'Agente: plugins abandonados',
    'site_agent.leads': 'Agente: solicitudes/leads',
    'site_agent.security_audit': 'Agente: auditoría de seguridad',
    'site_agent.ssl_status': 'Agente: estado del certificado SSL',
    'mainwp.updates_applied': 'MainWP: actualizaciones aplicadas',
    'woocommerce.revenue': 'WooCommerce: ingresos',
};

const SOURCE_LABELS: Record<string, string> = {
    site_agent: 'Agente',
    mainwp: 'MainWP',
    ga4: 'Analytics',
    gsc: 'Search Console',
    woocommerce: 'WooCommerce',
    cloudflare: 'Cloudflare',
    crowdsec: 'CrowdSec',
    betteruptime: 'Uptime',
    pagespeed: 'Velocidad',
    google_business: 'Perfil de Empresa',
    calc: 'Calculada',
};

function humanizeMetric(key: string): string {
    if (METRIC_LABELS[key]) {
        return METRIC_LABELS[key];
    }
    const dot = key.indexOf('.');
    if (dot === -1) {
        return key;
    }
    const source = key.slice(0, dot);
    const metric = key.slice(dot + 1).replace(/_/g, ' ');

    return `${SOURCE_LABELS[source] ?? source}: ${metric}`;
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
                <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-2">
                    <Segmented
                        value={visibility}
                        onChange={setVisibility}
                        items={[
                            { value: 'internal', label: 'Nota interna (solo el equipo)' },
                            { value: 'client', label: 'Visible para el cliente' },
                        ]}
                    />
                    <Button type="submit" size="sm" disabled={create.isPending || body.trim() === ''}>
                        <Plus className="ir-size-3.5" />
                        Añadir
                    </Button>
                </div>
            </form>

            {comments.length === 0 ? (
                <p className="ir-rounded-lg ir-border ir-border-dashed ir-px-4 ir-py-6 ir-text-center ir-text-sm ir-text-muted-foreground">
                    Sin comentarios todavía. Las notas internas son para tu equipo; los comentarios visibles aparecen en el reporte del cliente.
                </p>
            ) : (
                <ul className="ir-flex ir-flex-col ir-divide-y">
                    {comments.map((comment) => (
                        <li key={comment.id} className="ir-flex ir-items-start ir-gap-3 ir-py-2 ir-text-sm">
                            <Badge tone={comment.visibility === 'client' ? 'accent' : 'neutral'}>{comment.visibility === 'client' ? 'Cliente' : 'Interna'}</Badge>
                            <span className="ir-flex-1">{comment.body}</span>
                            <span className="ir-shrink-0 ir-text-xs ir-text-muted-foreground">{formatDate(comment.created_at)}</span>
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

type ToolTab = 'summary' | 'advisory' | 'questions' | 'comments' | 'insights' | 'deliveries';

/**
 * The review window for one generated report: where it stands, the next step, and the
 * pieces the agency polishes before sending (summary, diagnosis, comments), one tab at a time.
 */
function ReportToolsModal({
    report,
    initialTab,
    siteName,
    recipients,
    onClose,
}: {
    report: ReportSummary;
    initialTab: ToolTab;
    siteName: string;
    recipients: string[];
    onClose: () => void;
}): ReactElement {
    const hasAdvisory = report.has_advisory === true;
    const approve = useApproveReport();
    const send = useSendReport();
    const downloadPdf = useDownloadReportPdf();
    const status = STATUS[report.status] ?? { label: report.status, tone: 'neutral' as const };
    const [tab, setTab] = useState<ToolTab>(initialTab === 'advisory' && !hasAdvisory ? 'summary' : initialTab);

    const nextStep =
        report.status === 'draft'
            ? { text: 'Revisa el resumen y lo que ve el cliente. Cuando esté bien, apruébalo.', action: <Button variant="accent" size="sm" onClick={() => approve.mutate(report.id)} disabled={approve.isPending}>{approve.isPending ? 'Aprobando…' : 'Aprobar'}</Button> }
            : report.status === 'approved'
              ? {
                    text: recipients.length > 0 ? `Listo. Se enviará a ${recipients.join(', ')}.` : 'Listo para enviar, pero el reporte no tiene destinatarios: añádelos en sus ajustes.',
                    action: (
                        <Button variant="accent" size="sm" onClick={() => send.mutate(report.id)} disabled={send.isPending || recipients.length === 0}>
                            <Send className="ir-size-3.5" />
                            {send.isPending ? 'Enviando…' : 'Enviar'}
                        </Button>
                    ),
                }
              : { text: 'Ya se envió. Puedes reenviarlo desde el menú «⋯» del reporte si hace falta.', action: null };

    return (
        <Modal onClose={onClose} className="ir-max-w-2xl">
            <section className="ir-overflow-hidden ir-rounded-2xl ir-border ir-bg-card ir-shadow-ir-lg">
                <header className="ir-flex ir-items-start ir-justify-between ir-gap-3 ir-px-5 ir-pb-4 ir-pt-5">
                    <div className="ir-min-w-0">
                        <p className="ir-truncate ir-text-xs ir-font-medium ir-text-muted-foreground">{siteName}</p>
                        <h2 className="ir-flex ir-flex-wrap ir-items-center ir-gap-2 ir-text-lg ir-font-semibold ir-tracking-tight">
                            {friendlyPeriod(report.period_start, report.period_end)}
                            <Badge tone={status.tone}>{status.label}</Badge>
                        </h2>
                    </div>
                    <div className="ir-flex ir-shrink-0 ir-items-center ir-gap-1">
                        <Button variant="ghost" size="sm" onClick={() => window.open(`/portal/${report.public_token}`, '_blank', 'noopener')} title="Abrir el reporte como lo ve el cliente">
                            <Eye className="ir-size-3.5" />
                            <span className="ir-hidden sm:ir-inline">Ver</span>
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => downloadPdf.mutate(report.id)} disabled={downloadPdf.isPending} title="Descargar el PDF">
                            <FileDown className="ir-size-3.5" />
                            <span className="ir-hidden sm:ir-inline">{downloadPdf.isPending ? 'Preparando…' : 'PDF'}</span>
                        </Button>
                        <button type="button" onClick={onClose} aria-label="Cerrar" className="ir-ml-1 ir-flex ir-size-8 ir-items-center ir-justify-center ir-rounded-lg ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground">
                            <X className="ir-size-4" />
                        </button>
                    </div>
                </header>

                <div className="ir-mx-5 ir-mb-4 ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3 ir-rounded-xl ir-bg-muted/60 ir-px-4 ir-py-3">
                    <p className="ir-min-w-0 ir-flex-1 ir-text-sm">
                        <span className="ir-font-medium">Siguiente paso: </span>
                        <span className="ir-text-muted-foreground">{nextStep.text}</span>
                    </p>
                    {nextStep.action}
                </div>
                {(approve.isError || send.isError) && (
                    <p className="ir-mx-5 ir-mb-3 ir-text-xs ir-text-danger">{apiErrorMessage(approve.error ?? send.error, 'No se pudo completar la acción.')}</p>
                )}

                <Tabs
                    className="ir-px-3"
                    value={tab}
                    onChange={setTab}
                    items={[
                        { value: 'summary', label: 'Resumen', icon: FileText },
                        ...(hasAdvisory ? [{ value: 'advisory' as const, label: 'Diagnóstico', icon: Lightbulb }] : []),
                        { value: 'questions', label: 'Preguntas', icon: MessageCircleQuestion, count: (report.pending_questions ?? 0) > 0 ? report.pending_questions : undefined },
                        { value: 'comments', label: 'Comentarios', icon: MessageSquare },
                        { value: 'insights', label: 'Ideas de la IA', icon: Sparkles },
                        { value: 'deliveries', label: 'Envíos', icon: Mail },
                    ]}
                />

                <div className="ir-max-h-[55vh] ir-overflow-y-auto ir-px-5 ir-py-5">
                    {tab === 'summary' && <ReportNarrativePanel report={report} />}
                    {tab === 'advisory' && hasAdvisory && <ReportAdvisoryPanel report={report} />}
                    {tab === 'questions' && <ReportConversation reportId={report.id} />}
                    {tab === 'comments' && <ReportCommentsPanel reportId={report.id} />}
                    {tab === 'insights' && <ReportInsightsPanel reportId={report.id} />}
                    {tab === 'deliveries' && <ReportDeliveriesPanel reportId={report.id} />}
                </div>
            </section>
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
function GenerateModal({ definition, reports, onClose }: { definition: ReportDefinitionDto; reports: ReportSummary[]; onClose: () => void }): ReactElement {
    const generate = useGenerateReport();
    const syncRange = useSyncSiteById();
    const { data: snapshotPeriods = [] } = useSnapshotPeriods(definition.site_id);
    const [custom, setCustom] = useState(false);
    const [start, setStart] = useState('');
    const [end, setEnd] = useState('');

    // The last six complete months, newest first — what agencies generate 95% of the time.
    const months = useMemo(() => {
        const now = new Date();

        return Array.from({ length: 6 }, (_, index) => {
            const first = new Date(now.getFullYear(), now.getMonth() - 1 - index, 1);
            const last = new Date(first.getFullYear(), first.getMonth() + 1, 0);
            const iso = (date: Date): string => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

            return { start: iso(first), end: iso(last), label: capitalize(`${MONTHS[first.getMonth()]} ${first.getFullYear()}`) };
        });
    }, []);

    const hasData = (from: string, to: string): boolean =>
        snapshotPeriods.some((period) => period.period_start.slice(0, 10) <= to && period.period_end.slice(0, 10) >= from);
    const generatedFor = (from: string, to: string): ReportSummary | undefined =>
        reports.find((report) => report.period_start.slice(0, 10) === from && report.period_end.slice(0, 10) === to);

    // Preselect the newest month that has data (else the last complete month).
    useEffect(() => {
        if (start !== '' || end !== '') {
            return;
        }
        const pick = months.find((month) => hasData(month.start, month.end)) ?? months[0];
        if (pick !== undefined) {
            setStart(pick.start);
            setEnd(pick.end);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [snapshotPeriods]);

    const periodHasData = start !== '' && end !== '' && hasData(start, end);
    const existing = start !== '' && end !== '' ? generatedFor(start, end) : undefined;

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        if (start === '' || end === '') {
            return;
        }
        generate.mutate({ report_definition_id: definition.id, period_start: start, period_end: end });
    };

    return (
        <Modal onClose={onClose} className="ir-max-w-xl">
            <form onSubmit={submit} className="ir-overflow-hidden ir-rounded-2xl ir-border ir-bg-card ir-shadow-ir-lg">
                <header className="ir-flex ir-items-start ir-justify-between ir-gap-3 ir-px-5 ir-pb-3 ir-pt-5">
                    <div className="ir-min-w-0">
                        <p className="ir-truncate ir-text-xs ir-font-medium ir-text-muted-foreground">{definition.name}</p>
                        <h2 className="ir-text-lg ir-font-semibold ir-tracking-tight">¿Qué periodo quieres generar?</h2>
                        <p className="ir-text-[13px] ir-text-muted-foreground">Se crea el PDF y el panel del cliente. Después lo revisas y lo envías.</p>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Cerrar" className="ir-flex ir-size-8 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-lg ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground">
                        <X className="ir-size-4" />
                    </button>
                </header>

                <div className="ir-flex ir-flex-col ir-gap-4 ir-px-5 ir-pb-5">
                    {!custom ? (
                        <div className="ir-grid ir-grid-cols-2 ir-gap-2 sm:ir-grid-cols-3">
                            {months.map((month) => {
                                const selected = month.start === start && month.end === end;
                                const data = hasData(month.start, month.end);
                                const done = generatedFor(month.start, month.end);

                                return (
                                    <button
                                        key={month.start}
                                        type="button"
                                        onClick={() => {
                                            setStart(month.start);
                                            setEnd(month.end);
                                        }}
                                        className={
                                            'ir-flex ir-flex-col ir-gap-1 ir-rounded-xl ir-border ir-p-3 ir-text-left ir-transition ' +
                                            (selected ? 'ir-border-accent ir-bg-accent/5 ir-ring-2 ir-ring-accent/20' : 'hover:ir-border-foreground/25')
                                        }
                                    >
                                        <span className="ir-text-sm ir-font-semibold">{month.label}</span>
                                        <span className={'ir-flex ir-items-center ir-gap-1 ir-text-[11px] ' + (done !== undefined ? 'ir-text-info' : data ? 'ir-text-success' : 'ir-text-muted-foreground')}>
                                            {done !== undefined ? <><RotateCw className="ir-size-3" />Ya generado</> : data ? <><Check className="ir-size-3" />Con datos</> : 'Sin datos aún'}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    ) : (
                        <div className="ir-flex ir-flex-col ir-gap-3">
                            <div className="ir-flex ir-flex-wrap ir-gap-1.5">
                                {RANGE_PRESETS.map((preset) => (
                                    <button
                                        key={preset.key}
                                        type="button"
                                        onClick={() => {
                                            const range = preset.range();
                                            setStart(range.start);
                                            setEnd(range.end);
                                        }}
                                        className="ir-rounded-full ir-border ir-px-2.5 ir-py-1 ir-text-xs ir-font-medium ir-text-muted-foreground hover:ir-border-foreground/25 hover:ir-text-foreground"
                                    >
                                        {preset.label}
                                    </button>
                                ))}
                            </div>
                            <div className="ir-grid ir-grid-cols-2 ir-gap-3">
                                <Field label="Desde">
                                    <Input type="date" value={start} onChange={(event) => setStart(event.target.value)} />
                                </Field>
                                <Field label="Hasta">
                                    <Input type="date" value={end} onChange={(event) => setEnd(event.target.value)} />
                                </Field>
                            </div>
                        </div>
                    )}

                    <button type="button" onClick={() => setCustom((value) => !value)} className="ir-self-start ir-text-xs ir-font-medium ir-text-muted-foreground ir-underline-offset-2 hover:ir-text-foreground hover:ir-underline">
                        {custom ? '← Elegir un mes' : 'Otro rango de fechas (semana, trimestre, personalizado)'}
                    </button>

                    {start !== '' && end !== '' && !periodHasData && (
                        <div className="ir-flex ir-flex-col ir-gap-2 ir-rounded-xl ir-bg-warning/10 ir-p-3">
                            <p className="ir-text-xs ir-text-foreground/80">
                                Todavía no hay datos sincronizados para {friendlyPeriod(start, end)}. Sincronízalo primero; tarda unos segundos.
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="ir-self-start"
                                onClick={() => syncRange.mutate({ siteId: definition.site_id, period_start: start, period_end: end })}
                                disabled={syncRange.isPending}
                            >
                                <RotateCw className={'ir-size-3.5 ' + (syncRange.isPending ? 'ir-animate-spin' : '')} />
                                {syncRange.isPending ? 'Sincronizando…' : 'Sincronizar este periodo'}
                            </Button>
                            {syncRange.isSuccess && <p className="ir-text-xs ir-text-success">Sincronización en marcha. En unos segundos podrás generarlo.</p>}
                        </div>
                    )}
                    {existing !== undefined && (
                        <p className="ir-rounded-xl ir-bg-info/10 ir-px-3 ir-py-2 ir-text-xs ir-text-foreground/80">
                            Este periodo ya está generado. Volver a generarlo lo actualiza con los datos y la plantilla actuales.
                        </p>
                    )}
                </div>

                <footer className="ir-flex ir-items-center ir-justify-end ir-gap-2 ir-border-t ir-bg-muted/30 ir-px-5 ir-py-3">
                    {generate.isSuccess ? (
                        <>
                            <p className="ir-mr-auto ir-text-xs ir-text-success">Generándose. Aparecerá en la lista en unos segundos.</p>
                            <Button type="button" size="sm" onClick={onClose}>
                                Listo
                            </Button>
                        </>
                    ) : (
                        <>
                            {generate.isError && <p className="ir-mr-auto ir-text-xs ir-text-danger">{apiErrorMessage(generate.error, 'No se pudo generar.')}</p>}
                            <Button type="button" variant="ghost" size="sm" onClick={onClose}>
                                Cancelar
                            </Button>
                            <Button type="submit" size="sm" disabled={generate.isPending || start === '' || end === ''}>
                                <CalendarClock className="ir-size-3.5" />
                                {generate.isPending ? 'Generando…' : existing !== undefined ? `Volver a generar ${friendlyPeriod(start, end)}` : start !== '' ? `Generar ${friendlyPeriod(start, end)}` : 'Generar'}
                            </Button>
                        </>
                    )}
                </footer>
            </form>
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
    const [historyOpen, setHistoryOpen] = useState(false);
    const [recipients, setRecipients] = useState((definition.recipients ?? []).join(', '));
    const noRecipients = (definition.recipients ?? []).length === 0;

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
            // Preserve the chosen day when re-selecting monthly; default to day 1.
            const send_day = value === 'monthly' ? (schedule?.send_day ?? 1) : undefined;
            createSchedule.mutate({ report_definition_id: definition.id, cadence: value as ScheduleCadence, send_day });
        }
    };
    const changeSendDay = (day: number): void => {
        createSchedule.mutate({ report_definition_id: definition.id, cadence: 'monthly', send_day: day });
    };
    return (
        <section className="ir-overflow-hidden ir-rounded-xl ir-border ir-bg-card ir-shadow-ir-xs">
            {/* Header: what this report is, its one main action, and the rest in a menu. */}
            <header className="ir-flex ir-items-start ir-justify-between ir-gap-3 ir-px-5 ir-pb-3 ir-pt-4">
                <div className="ir-min-w-0">
                    <p className="ir-truncate ir-text-xs ir-font-medium ir-text-muted-foreground">{siteName}</p>
                    <h3 className="ir-flex ir-flex-wrap ir-items-center ir-gap-2 ir-text-[15px] ir-font-semibold ir-tracking-tight">
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
                        Plantilla: {templateName} · {noRecipients ? <span className="ir-text-warning">sin destinatarios</span> : `${(definition.recipients ?? []).length} destinatario(s)`}
                    </p>
                </div>
                <div className="ir-flex ir-shrink-0 ir-items-center ir-gap-1">
                    <Button variant="ghost" size="sm" onClick={() => onGenerate(definition)} title="Generar un periodo nuevo">
                        <Plus className="ir-size-3.5" />
                        <span className="ir-hidden sm:ir-inline">Generar</span>
                    </Button>
                    <ActionMenu
                        label={`Opciones de ${definition.name}`}
                        items={[
                            { label: settingsOpen ? 'Ocultar ajustes' : 'Plantilla, destinatarios y envío automático', icon: Settings2, onSelect: () => setSettingsOpen((open) => !open) },
                            { label: 'Compartir y privacidad', icon: Share2, onSelect: () => onShare(definition) },
                            {
                                label: 'Eliminar reporte e historial',
                                icon: Trash2,
                                danger: true,
                                disabled: deleteDefinition.isPending,
                                onSelect: () => {
                                    if (window.confirm(`¿Eliminar «${definition.name}» y todos sus reportes generados? No se puede deshacer.`)) {
                                        deleteDefinition.mutate(definition.id);
                                    }
                                },
                            },
                        ]}
                    />
                </div>
            </header>

            {/* Collapsible settings: template + recipients. Hidden by default to keep it clean. */}
            {settingsOpen && (
                <div className="ir-grid ir-gap-3 ir-border-y ir-bg-muted/30 ir-px-5 ir-py-4 sm:ir-grid-cols-2">
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
                            <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                                <Select className="ir-flex-1" value={schedule?.cadence ?? ''} onChange={(event) => changeCadence(event.target.value)} disabled={createSchedule.isPending || deleteSchedule.isPending}>
                                    <option value="">Manual (sin automatización)</option>
                                    <option value="monthly">Cada mes (el mes que acaba de terminar)</option>
                                    <option value="weekly">Cada semana (la semana que acaba de terminar)</option>
                                </Select>
                                {schedule?.cadence === 'monthly' && (
                                    <label className="ir-flex ir-shrink-0 ir-items-center ir-gap-1.5 ir-text-xs ir-text-muted-foreground">
                                        el día
                                        <Select className="ir-w-16" value={schedule.send_day ?? 1} onChange={(event) => changeSendDay(Number(event.target.value))} disabled={createSchedule.isPending}>
                                            {Array.from({ length: 28 }, (_, index) => index + 1).map((day) => (
                                                <option key={day} value={day}>
                                                    {day}
                                                </option>
                                            ))}
                                        </Select>
                                    </label>
                                )}
                            </div>
                        </Field>
                        {schedule !== null && noRecipients && (
                            <p className="ir-mt-1.5 ir-text-xs ir-text-amber-600">⚠ No hay destinatarios. Añade al menos un email arriba para que el envío automático llegue a alguien.</p>
                        )}
                    </div>
                </div>
            )}

            {/* Generation history: the latest period up front, older ones folded away. */}
            <div className="ir-border-t ir-px-5 ir-py-2">
                {reports.length === 0 ? (
                    <div className="ir-flex ir-flex-col ir-items-center ir-gap-2 ir-py-6 ir-text-center">
                        <CalendarClock className="ir-size-5 ir-text-muted-foreground" />
                        <p className="ir-text-sm ir-text-muted-foreground">Aún no has generado este reporte.</p>
                        <Button variant="accent" size="sm" onClick={() => onGenerate(definition)}>
                            <Plus className="ir-size-3.5" />
                            Generar el primero
                        </Button>
                    </div>
                ) : (
                    <div className="ir-flex ir-flex-col ir-divide-y">
                        {(historyOpen ? reports : reports.slice(0, 1)).map((report, index) => {
                            const status = STATUS[report.status] ?? { label: report.status, tone: 'neutral' as const };
                            const hidden = report.hidden_metrics ?? [];
                            const busyPdf = downloadPdf.isPending && downloadPdf.variables === report.id;
                            const view = (): void => void window.open(`/portal/${report.public_token}`, '_blank', 'noopener');
                            let primary: ReactNode = null;
                            if (report.status === 'draft') {
                                primary = (
                                    <Button variant="accent" size="sm" title="Aprobar para poder enviarlo" onClick={() => approve.mutate(report.id)} disabled={approve.isPending}>
                                        Aprobar
                                    </Button>
                                );
                            } else if (report.status === 'approved') {
                                primary = (
                                    <Button variant="accent" size="sm" title="Enviar por email a los destinatarios" onClick={() => send.mutate(report.id)} disabled={send.isPending || noRecipients}>
                                        <Send className="ir-size-3.5" />
                                        Enviar
                                    </Button>
                                );
                            }

                            return (
                                <div key={report.id} className="ir-flex ir-flex-wrap ir-items-center ir-gap-x-4 ir-gap-y-2 ir-py-3">
                                    <div className="ir-min-w-[10rem] ir-flex-1">
                                        <p className="ir-flex ir-flex-wrap ir-items-center ir-gap-2 ir-text-sm ir-font-medium">
                                            {friendlyPeriod(report.period_start, report.period_end)}
                                            <Badge tone={status.tone}>{status.label}</Badge>
                                        </p>
                                        <p className="ir-mt-0.5 ir-flex ir-flex-wrap ir-items-center ir-gap-x-2 ir-text-xs ir-text-muted-foreground">
                                            <ViewedBadge report={report} />
                                            {(report.pending_questions ?? 0) > 0 && (
                                                <button
                                                    type="button"
                                                    onClick={() => onTools(report, 'questions')}
                                                    className="ir-inline-flex ir-items-center ir-gap-1 ir-rounded-full ir-bg-accent/10 ir-px-2 ir-py-0.5 ir-font-medium ir-text-accent hover:ir-bg-accent/15"
                                                >
                                                    <MessageCircleQuestion className="ir-size-3" />
                                                    {report.pending_questions === 1 ? '1 pregunta sin responder' : `${report.pending_questions} preguntas sin responder`}
                                                </button>
                                            )}
                                            <span>Generado {formatGeneratedAt(report.created_at)}</span>
                                            {report.health_score !== null && <span>· Salud {report.health_score}/100</span>}
                                            {hidden.length > 0 && (
                                                <span className="ir-text-warning" title={`Sin datos este periodo (no es un error): ${hidden.map(humanizeMetric).join(', ')}. El bloque se ocultó porque su métrica llegó vacía. Sincroniza el periodo y regenera; si es del Agente, revisa que el plugin del sitio esté actualizado.`}>
                                                    · {hidden.length} bloques sin datos
                                                </span>
                                            )}
                                        </p>
                                        {hidden.length > 0 && index === 0 && (
                                            <div className="ir-mt-1.5">
                                                <PeriodSyncMenu siteId={definition.site_id} periodStart={report.period_start.slice(0, 10)} periodEnd={report.period_end.slice(0, 10)} />
                                            </div>
                                        )}
                                    </div>

                                    <div className="ir-flex ir-items-center ir-gap-1">
                                        <Button variant="ghost" size="sm" title="Abrir el reporte como lo ve el cliente" onClick={view}>
                                            <Eye className="ir-size-3.5" />
                                            Ver
                                        </Button>
                                        {primary}
                                        <ActionMenu
                                            label="Más acciones del reporte"
                                            items={[
                                                { label: 'Resumen, diagnóstico y comentarios', icon: Sparkles, onSelect: () => onTools(report, 'summary') },
                                                { label: 'Preguntas del cliente', icon: MessageCircleQuestion, onSelect: () => onTools(report, 'questions') },
                                                { label: busyPdf ? 'Preparando PDF…' : 'Descargar PDF', icon: FileDown, onSelect: () => downloadPdf.mutate(report.id), disabled: busyPdf },
                                                report.status === 'sent' && { label: 'Reenviar por email', icon: Send, onSelect: () => send.mutate(report.id), disabled: send.isPending },
                                                { label: 'Historial de envíos', icon: Mail, onSelect: () => onTools(report, 'deliveries') },
                                                {
                                                    label: 'Volver a generar',
                                                    icon: RotateCw,
                                                    hint: 'Tras sincronizar o cambiar la plantilla',
                                                    disabled: generate.isPending,
                                                    onSelect: () => generate.mutate({ report_definition_id: report.report_definition_id, period_start: report.period_start.slice(0, 10), period_end: report.period_end.slice(0, 10) }),
                                                },
                                                {
                                                    label: 'Eliminar este periodo',
                                                    icon: Trash2,
                                                    danger: true,
                                                    disabled: deleteReport.isPending,
                                                    onSelect: () => {
                                                        if (window.confirm('¿Eliminar este reporte? No se puede deshacer.')) {
                                                            deleteReport.mutate(report.id);
                                                        }
                                                    },
                                                },
                                            ]}
                                        />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
                {reports.length > 1 && (
                    <button type="button" onClick={() => setHistoryOpen((open) => !open)} className="ir-flex ir-w-full ir-items-center ir-justify-center ir-gap-1 ir-border-t ir-py-2.5 ir-text-xs ir-font-medium ir-text-muted-foreground hover:ir-text-foreground">
                        <ChevronDown className={'ir-size-3.5 ir-transition-transform ' + (historyOpen ? 'ir-rotate-180' : '')} />
                        {historyOpen ? 'Ocultar periodos anteriores' : `Ver periodos anteriores (${reports.length - 1})`}
                    </button>
                )}
                {/* Surface a failed lifecycle action (approve/send/regenerate) instead of a
                    dead click — especially "Enviar", where silence means nobody notices. */}
                {(approve.isError || send.isError || generate.isError) && (
                    <p className="ir-mt-2 ir-text-xs ir-text-danger">
                        {apiErrorMessage(approve.error ?? send.error ?? generate.error, 'No se pudo completar la acción. Inténtalo de nuevo.')}
                    </p>
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
    const { data: definitions = [], isLoading: definitionsLoading, isError: definitionsError, refetch: refetchDefinitions } = useReportDefinitions();
    // The API caps the list (reports accumulate every month); this raises the cap on demand.
    const [reportLimit, setReportLimit] = useState(200);
    const { data: reports = [] } = useReports(reportLimit);
    // A full page means there are probably older reports behind it.
    const mayHaveMoreReports = reports.length >= reportLimit && reportLimit < 1000;
    const { data: schedules = [] } = useSchedules();

    const [createOpen, setCreateOpen] = useState(false);
    const [filter, setFilter] = useState<'all' | 'draft' | 'approved' | 'sent' | 'none'>('all');
    const [query, setQuery] = useState('');
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
            .sort((a, b) => b.period_start.localeCompare(a.period_start) || (b.created_at ?? '').localeCompare(a.created_at ?? ''));

    // A report to open straight away: from Inicio, or the «new question» email
    // (#/reports?report=12&tab=questions). Opens once its row has loaded.
    const reportFocus = useAdminUi((state) => state.reportFocus);
    const clearReportFocus = useAdminUi((state) => state.clearReportFocus);
    useEffect(() => {
        if (reportFocus === null) {
            return;
        }
        const target = reports.find((report) => report.id === reportFocus.report);
        if (target !== undefined) {
            const tab = (['summary', 'questions', 'comments', 'deliveries'] as const).find((value) => value === reportFocus.tab) ?? 'summary';
            setTools({ report: target, tab });
            clearReportFocus();
        }
    }, [reportFocus, reports, clearReportFocus]);

    // Keep the tools modal's report in sync with fresh data (e.g. after regenerate).
    const toolsReport = tools !== null ? (reports.find((report) => report.id === tools.report.id) ?? tools.report) : null;

    // Filter by where each report is in its cycle (its latest period's status) + search.
    const latestStatus = (definitionId: number): string | null => reportsFor(definitionId)[0]?.status ?? null;
    const counts = {
        all: definitions.length,
        draft: definitions.filter((definition) => latestStatus(definition.id) === 'draft').length,
        approved: definitions.filter((definition) => latestStatus(definition.id) === 'approved').length,
        sent: definitions.filter((definition) => latestStatus(definition.id) === 'sent').length,
        none: definitions.filter((definition) => latestStatus(definition.id) === null).length,
    };
    const q = query.trim().toLowerCase();
    const visible = definitions.filter((definition) => {
        if (filter !== 'all' && (latestStatus(definition.id) ?? 'none') !== filter) {
            return false;
        }

        return q === '' || definition.name.toLowerCase().includes(q) || siteName(definition.site_id).toLowerCase().includes(q);
    });

    return (
        <div className="ir-flex ir-flex-col">
            <PageHeader
                title="Reportes"
                description="Cada cliente tiene su reporte configurado una vez; cada mes lo generas, lo revisas y lo envías. Aquí ves en qué punto está cada uno."
                actions={
                    <Button onClick={() => setCreateOpen(true)}>
                        <Plus className="ir-size-4" />
                        Nuevo reporte
                    </Button>
                }
            />

            {/* Distinguish loading and error from the genuine empty state (FE-4) so a failed
                load doesn't read as "no reports". */}
            {definitionsError ? (
                <div className="ir-flex ir-flex-col ir-items-center ir-gap-3 ir-rounded-xl ir-border ir-border-dashed ir-bg-card ir-py-16 ir-text-center">
                    <p className="ir-text-sm ir-text-danger">No se pudieron cargar los reportes.</p>
                    <Button variant="outline" onClick={() => void refetchDefinitions()}>Reintentar</Button>
                </div>
            ) : definitionsLoading ? (
                <p className="ir-text-sm ir-text-muted-foreground">Cargando reportes…</p>
            ) : definitions.length === 0 ? (
                <div className="ir-flex ir-flex-col ir-gap-4">
                    <EmptyState
                        icon={FileBarChart}
                        title="Aún no tienes reportes configurados"
                        description="Crea el primero para un cliente. Después lo generas cada mes en segundos (o lo dejas automático)."
                        action={
                            <Button onClick={() => setCreateOpen(true)}>
                                <Plus className="ir-size-4" />
                                Nuevo reporte
                            </Button>
                        }
                    />
                    <div className="ir-grid ir-gap-3 sm:ir-grid-cols-3">
                        {[
                            { n: 1, title: 'Configura', text: 'Elige el sitio, la plantilla y quién lo recibe.' },
                            { n: 2, title: 'Genera', text: 'Elige el periodo. Se crea el PDF y el panel interactivo del cliente.' },
                            { n: 3, title: 'Revisa y envía', text: 'Ajusta el resumen IA, apruébalo y envíalo. O automatízalo.' },
                        ].map((step) => (
                            <div key={step.n} className="ir-flex ir-items-start ir-gap-3 ir-rounded-xl ir-border ir-bg-card ir-p-4">
                                <span className="ir-flex ir-size-6 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-full ir-bg-accent/10 ir-text-xs ir-font-semibold ir-text-accent">{step.n}</span>
                                <div>
                                    <p className="ir-text-sm ir-font-medium">{step.title}</p>
                                    <p className="ir-mt-0.5 ir-text-xs ir-text-muted-foreground">{step.text}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            ) : (
                <div className="ir-flex ir-flex-col ir-gap-4">
                    <div className="ir-flex ir-flex-col ir-gap-3 sm:ir-flex-row sm:ir-items-center sm:ir-justify-between">
                        <Segmented
                            value={filter}
                            onChange={setFilter}
                            items={[
                                { value: 'all', label: 'Todos', count: counts.all },
                                { value: 'draft', label: 'Por revisar', count: counts.draft },
                                { value: 'approved', label: 'Listos para enviar', count: counts.approved },
                                { value: 'sent', label: 'Enviados', count: counts.sent },
                                ...(counts.none > 0 ? [{ value: 'none' as const, label: 'Sin generar', count: counts.none }] : []),
                            ]}
                        />
                        <div className="ir-relative sm:ir-w-64">
                            <Search className="ir-pointer-events-none ir-absolute ir-left-2.5 ir-top-1/2 ir-size-4 -ir-translate-y-1/2 ir-text-muted-foreground" />
                            <Input className="ir-pl-8" placeholder="Buscar cliente o reporte…" value={query} onChange={(event) => setQuery(event.target.value)} />
                        </div>
                    </div>

                    {visible.length === 0 ? (
                        <EmptyState icon={Search} title="Nada por aquí" description="Ningún reporte coincide con este filtro o búsqueda." />
                    ) : (
                        visible.map((definition) => (
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
                        ))
                    )}
                </div>
            )}

            {/* The reports list is capped server-side (it grows monthly); load older ones on demand. */}
            {mayHaveMoreReports && (
                <div className="ir-flex ir-justify-center">
                    <Button variant="ghost" size="sm" onClick={() => setReportLimit((current) => Math.min(1000, current + 200))}>
                        Cargar reportes más antiguos
                    </Button>
                </div>
            )}

            {/* Modals. */}
            {createOpen && <CreateReportModal onClose={() => setCreateOpen(false)} />}
            {generateFor !== null && <GenerateModal definition={generateFor} reports={reportsFor(generateFor.id)} onClose={() => setGenerateFor(null)} />}
            {toolsReport !== null && tools !== null && (
                <ReportToolsModal
                    report={toolsReport}
                    initialTab={tools.tab}
                    siteName={(() => {
                        const definition = definitions.find((entry) => entry.id === toolsReport.report_definition_id);

                        return definition?.name ?? '';
                    })()}
                    recipients={definitions.find((entry) => entry.id === toolsReport.report_definition_id)?.recipients ?? []}
                    onClose={() => setTools(null)}
                />
            )}
            {sharingDefinition !== null && (
                <ShareDialog definition={sharingDefinition} publicToken={latestTokenForDefinition(sharingDefinition.id)} onClose={() => setSharingDefinition(null)} />
            )}
        </div>
    );
}
