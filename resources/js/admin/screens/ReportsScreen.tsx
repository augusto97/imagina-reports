import { type ColumnDef } from '@tanstack/react-table';
import { type FormEvent, type ReactElement, useEffect, useState } from 'react';

import { FileDown, MessageSquare, PenLine, RefreshCw, RotateCw, Sparkles, Trash2 } from 'lucide-react';

import {
    useApproveReport,
    useCreateReportComment,
    useCreateReportDefinition,
    useDeleteComment,
    useGenerateReport,
    useRegenerateReportNarrative,
    useReportComments,
    useSyncSiteById,
    useReportDefinitions,
    useReportInsights,
    useReportTemplates,
    useDeleteReport,
    useDeleteReportDefinition,
    useDownloadReportPdf,
    useReports,
    useSendReport,
    useSites,
    useSnapshotPeriods,
    useUpdateReportNarrative,
    useUpdateReportDefinition,
} from '../api';
import { DataTable } from '../components/DataTable';
import { Button, Card, Field, Input } from '../components/ui';
import type { ReportDefinitionDto, ReportSummary } from '../types';

const inputClass = 'ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm';

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
        <Card title="Comentarios y notas">
            <form onSubmit={submit} className="ir-mb-4 ir-flex ir-max-w-xl ir-flex-col ir-gap-2">
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
                            <span
                                className={
                                    comment.visibility === 'client'
                                        ? 'ir-rounded ir-bg-primary ir-px-2 ir-py-0.5 ir-text-xs ir-text-primary-foreground'
                                        : 'ir-rounded ir-bg-muted ir-px-2 ir-py-0.5 ir-text-xs ir-text-muted-foreground'
                                }
                            >
                                {comment.visibility === 'client' ? 'Cliente' : 'Interna'}
                            </span>
                            <span className="ir-flex-1">{comment.body}</span>
                            <span className="ir-shrink-0 ir-text-xs ir-text-muted-foreground">{comment.created_at.slice(0, 10)}</span>
                            <button type="button" className="ir-text-muted-foreground hover:ir-text-red-500" title="Eliminar" onClick={() => remove.mutate(comment.id)}>
                                <Trash2 className="ir-size-4" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/** Edit or AI-regenerate a report's executive summary before sending (CLAUDE.md §10.6). */
function ReportNarrativePanel({ report }: { report: ReportSummary }): ReactElement {
    const update = useUpdateReportNarrative();
    const regenerate = useRegenerateReportNarrative();
    const [text, setText] = useState(report.executive_summary ?? '');

    const onRegenerate = (): void => {
        regenerate.mutate(report.id, { onSuccess: (next) => setText(next ?? '') });
    };

    return (
        <Card title="Resumen ejecutivo (IA)">
            <p className="ir-mb-2 ir-text-xs ir-text-muted-foreground">
                Es el texto que el cliente lee al inicio del reporte. Edítalo o regenéralo con IA antes de enviar.
            </p>
            <textarea
                className={inputClass}
                rows={4}
                value={text}
                onChange={(event) => setText(event.target.value)}
                placeholder="El resumen se escribe solo al generar el reporte con IA. También puedes redactarlo a mano aquí."
            />
            <div className="ir-mt-3 ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                <Button onClick={() => update.mutate({ reportId: report.id, text })} disabled={update.isPending}>
                    {update.isPending ? 'Guardando…' : 'Guardar'}
                </Button>
                <Button variant="ghost" onClick={onRegenerate} disabled={regenerate.isPending}>
                    <Sparkles className="ir-size-3.5" />
                    {regenerate.isPending ? 'Regenerando…' : 'Regenerar con IA'}
                </Button>
                {update.isSuccess && <span className="ir-text-xs ir-text-emerald-600">Guardado.</span>}
                {regenerate.isError && <span className="ir-text-xs ir-text-red-500">No se pudo regenerar (revisa la API key de la agencia).</span>}
            </div>
        </Card>
    );
}

/** Inline view/edit of a definition's email recipients (saved on blur). */
function DefinitionRecipientsEditor({ definition }: { definition: ReportDefinitionDto }): ReactElement {
    const update = useUpdateReportDefinition();
    const [value, setValue] = useState((definition.recipients ?? []).join(', '));

    const save = (): void => {
        const next = parseRecipients(value);
        if (next.join(',') !== (definition.recipients ?? []).join(',')) {
            update.mutate({ id: definition.id, recipients: next });
        }
    };

    return (
        <div className="ir-flex ir-flex-1 ir-items-center ir-gap-2">
            <input
                className={`${inputClass} ir-max-w-md`}
                value={value}
                onChange={(event) => setValue(event.target.value)}
                onBlur={save}
                placeholder="cliente@empresa.com, pm@agencia.com"
            />
            {update.isSuccess && <span className="ir-shrink-0 ir-text-xs ir-text-emerald-600">Guardado</span>}
        </div>
    );
}

/** Split a comma/semicolon/newline-separated string into a list of trimmed emails. */
function parseRecipients(raw: string): string[] {
    return raw
        .split(/[,\n;]+/)
        .map((value) => value.trim())
        .filter((value) => value !== '');
}

export function ReportsScreen(): ReactElement {
    const { data: sites = [] } = useSites();
    const { data: templates = [] } = useReportTemplates();
    const { data: definitions = [] } = useReportDefinitions();
    const { data: reports = [] } = useReports();
    const createDefinition = useCreateReportDefinition();
    const updateDefinition = useUpdateReportDefinition();
    const generate = useGenerateReport();
    const syncSite = useSyncSiteById();
    const downloadPdf = useDownloadReportPdf();

    // Map a report's definition → its site, so per-row sync/regenerate know where to act.
    const siteIdForDefinition = (definitionId: number): number | null =>
        definitions.find((definition) => definition.id === definitionId)?.site_id ?? null;
    const deleteReport = useDeleteReport();
    const deleteDefinition = useDeleteReportDefinition();

    // Surface which template each definition uses, so the association is verifiable.
    const templateLabel = (templateId: number | null): string =>
        templateId === null ? 'Plantilla por defecto' : (templates.find((template) => template.id === templateId)?.name ?? `Plantilla #${templateId}`);
    const siteLabel = (id: number): string => sites.find((site) => site.id === id)?.name ?? `Sitio #${id}`;
    const approve = useApproveReport();
    const send = useSendReport();
    const insights = useReportInsights();

    const [insightsFor, setInsightsFor] = useState<number | null>(null);
    const [commentsFor, setCommentsFor] = useState<number | null>(null);
    const [narrativeFor, setNarrativeFor] = useState<number | null>(null);
    const showInsights = (reportId: number): void => {
        setInsightsFor(reportId);
        insights.mutate(reportId);
    };

    const [defSite, setDefSite] = useState('');
    const [defName, setDefName] = useState('');
    const [defTemplate, setDefTemplate] = useState('');
    const [defRecipients, setDefRecipients] = useState('');
    const [genDefinition, setGenDefinition] = useState('');
    const [start, setStart] = useState('');
    const [end, setEnd] = useState('');

    // Data availability for the period being generated (so an empty report can't be
    // produced unknowingly): the selected definition's site → its synced snapshot periods.
    const selectedDefinition = definitions.find((definition) => String(definition.id) === genDefinition);
    const selectedSiteId = selectedDefinition?.site_id ?? null;
    const { data: snapshotPeriods = [] } = useSnapshotPeriods(selectedSiteId);

    // Default the generate dates to the most recent period that actually has data.
    useEffect(() => {
        const latest = snapshotPeriods[0];
        if (latest !== undefined && start === '' && end === '') {
            setStart(latest.period_start.slice(0, 10));
            setEnd(latest.period_end.slice(0, 10));
        }
    }, [snapshotPeriods, start, end]);

    // Does the chosen [start, end] overlap any synced snapshot period?
    const periodHasData =
        start !== '' &&
        end !== '' &&
        snapshotPeriods.some((period) => period.period_start.slice(0, 10) <= end && period.period_end.slice(0, 10) >= start);

    const columns: ColumnDef<ReportSummary>[] = [
        {
            id: 'period',
            header: 'Periodo',
            accessorFn: (row) => `${row.period_start.slice(0, 10)} → ${row.period_end.slice(0, 10)}`,
        },
        { header: 'Salud', accessorKey: 'health_score' },
        { header: 'Estado', accessorKey: 'status' },
        {
            id: 'data',
            header: 'Datos',
            cell: ({ row }) => {
                const report = row.original;
                const hidden = report.hidden_metrics ?? [];

                if (hidden.length === 0) {
                    return <span className="ir-text-xs ir-text-emerald-600">OK</span>;
                }

                const siteId = siteIdForDefinition(report.report_definition_id);

                return (
                    <div className="ir-flex ir-flex-col ir-items-start ir-gap-1">
                        <span
                            className="ir-cursor-help ir-text-xs ir-text-amber-600"
                            title={`Sin datos para el periodo (bloques ocultos): ${hidden.join(', ')}. Sincroniza el periodo y luego «Regenerar».`}
                        >
                            ⚠ {hidden.length} sin datos
                        </span>
                        {siteId !== null && (
                            <button
                                type="button"
                                className="ir-text-left ir-text-xs ir-text-primary hover:ir-underline"
                                onClick={() =>
                                    syncSite.mutate({
                                        siteId,
                                        period_start: report.period_start.slice(0, 10),
                                        period_end: report.period_end.slice(0, 10),
                                    })
                                }
                                disabled={syncSite.isPending}
                            >
                                <RefreshCw className="ir-mr-1 ir-inline ir-size-3" />
                                {syncSite.isPending && syncSite.variables?.siteId === siteId ? 'Sincronizando…' : 'Sincronizar periodo'}
                            </button>
                        )}
                    </div>
                );
            },
        },
        {
            id: 'actions',
            header: '',
            cell: ({ row }) => {
                const report = row.original;

                return (
                    <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                        <Button variant="outline" size="sm" onClick={() => window.open(`/reports/${report.public_token}`, '_blank', 'noopener')}>
                            Ver
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => downloadPdf.mutate(report.id)} disabled={downloadPdf.isPending}>
                            <FileDown className="ir-size-3.5" />
                            {downloadPdf.isPending && downloadPdf.variables === report.id ? 'Generando…' : 'PDF'}
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => showInsights(report.id)} disabled={insights.isPending && insightsFor === report.id}>
                            <Sparkles className="ir-size-3.5" />
                            Insights
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => setNarrativeFor((current) => (current === report.id ? null : report.id))}>
                            <PenLine className="ir-size-3.5" />
                            Resumen
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            title="Volver a generar este reporte para el mismo periodo (tras sincronizar o editar la plantilla)"
                            onClick={() =>
                                generate.mutate({
                                    report_definition_id: report.report_definition_id,
                                    period_start: report.period_start.slice(0, 10),
                                    period_end: report.period_end.slice(0, 10),
                                })
                            }
                            disabled={generate.isPending}
                        >
                            <RotateCw className="ir-size-3.5" />
                            Regenerar
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => setCommentsFor((current) => (current === report.id ? null : report.id))}>
                            <MessageSquare className="ir-size-3.5" />
                            Comentarios
                        </Button>
                        {report.status === 'draft' ? (
                            <Button variant="accent" size="sm" onClick={() => approve.mutate(report.id)} disabled={approve.isPending}>
                                Aprobar
                            </Button>
                        ) : (
                            <Button variant="accent" size="sm" onClick={() => send.mutate(report.id)} disabled={send.isPending}>
                                {report.status === 'sent' ? 'Reenviar' : 'Enviar'}
                            </Button>
                        )}
                        <Button
                            variant="ghost"
                            size="sm"
                            title="Eliminar reporte"
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
                );
            },
        },
    ];

    const submitDefinition = (event: FormEvent): void => {
        event.preventDefault();
        if (defSite === '' || defName === '') {
            return;
        }
        createDefinition.mutate(
            {
                site_id: Number(defSite),
                name: defName,
                template_id: defTemplate === '' ? undefined : Number(defTemplate),
                recipients: parseRecipients(defRecipients),
            },
            {
                onSuccess: () => {
                    setDefName('');
                    setDefRecipients('');
                },
            },
        );
    };

    const submitGenerate = (event: FormEvent): void => {
        event.preventDefault();
        if (genDefinition === '' || start === '' || end === '') {
            return;
        }
        generate.mutate({ report_definition_id: Number(genDefinition), period_start: start, period_end: end });
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <Card title="Nueva definición de reporte">
                <form onSubmit={submitDefinition} className="ir-flex ir-max-w-md ir-flex-col ir-gap-3">
                    <Field label="Sitio">
                        <select
                            className="ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm"
                            value={defSite}
                            onChange={(event) => setDefSite(event.target.value)}
                        >
                            <option value="">Selecciona…</option>
                            {sites.map((site) => (
                                <option key={site.id} value={site.id}>
                                    {site.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Nombre">
                        <Input value={defName} onChange={(event) => setDefName(event.target.value)} />
                    </Field>
                    <Field label="Plantilla (opcional)">
                        <select
                            className="ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm"
                            value={defTemplate}
                            onChange={(event) => setDefTemplate(event.target.value)}
                        >
                            <option value="">Plantilla por defecto</option>
                            {templates.map((template) => (
                                <option key={template.id} value={template.id}>
                                    {template.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Destinatarios (emails separados por coma)">
                        <Input
                            placeholder="cliente@empresa.com, pm@agencia.com"
                            value={defRecipients}
                            onChange={(event) => setDefRecipients(event.target.value)}
                        />
                    </Field>
                    <Button type="submit" disabled={createDefinition.isPending}>
                        Crear definición
                    </Button>
                </form>
            </Card>

            <Card title="Definiciones existentes">
                {definitions.length === 0 ? (
                    <p className="ir-text-sm ir-text-muted-foreground">Aún no hay definiciones. Crea una arriba.</p>
                ) : (
                    <div className="ir-flex ir-flex-col ir-gap-2">
                        <p className="ir-text-xs ir-text-muted-foreground">
                            La <strong>plantilla</strong> de cada definición es la que usa el reporte generado. Puedes cambiarla aquí.
                        </p>
                        {definitions.map((definition) => (
                            <div key={definition.id} className="ir-flex ir-flex-col ir-gap-2 ir-rounded-md ir-border ir-p-3">
                                <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3">
                                    <div className="ir-min-w-0">
                                        <p className="ir-text-sm ir-font-medium">{definition.name}</p>
                                        <p className="ir-text-xs ir-text-muted-foreground">{siteLabel(definition.site_id)}</p>
                                    </div>
                                    <div className="ir-flex ir-items-center ir-gap-2">
                                        <span className="ir-text-xs ir-text-muted-foreground">Plantilla:</span>
                                        <select
                                            className="ir-rounded-md ir-border ir-bg-background ir-px-2 ir-py-1 ir-text-sm"
                                            value={definition.template_id ?? ''}
                                            onChange={(event) =>
                                                updateDefinition.mutate({
                                                    id: definition.id,
                                                    template_id: event.target.value === '' ? null : Number(event.target.value),
                                                })
                                            }
                                        >
                                            <option value="">Plantilla por defecto</option>
                                            {templates.map((template) => (
                                                <option key={template.id} value={template.id}>
                                                    {template.name}
                                                </option>
                                            ))}
                                        </select>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            title="Eliminar definición"
                                            onClick={() => {
                                                if (window.confirm('¿Eliminar esta definición y todos sus reportes generados? No se puede deshacer.')) {
                                                    deleteDefinition.mutate(definition.id);
                                                }
                                            }}
                                            disabled={deleteDefinition.isPending}
                                        >
                                            <Trash2 className="ir-size-3.5 ir-text-danger" />
                                        </Button>
                                    </div>
                                </div>
                                <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                                    <span className="ir-shrink-0 ir-text-xs ir-text-muted-foreground">Destinatarios:</span>
                                    <DefinitionRecipientsEditor key={definition.recipients.join(',')} definition={definition} />
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </Card>

            <Card title="Generar reporte">
                <form onSubmit={submitGenerate} className="ir-flex ir-max-w-md ir-flex-col ir-gap-3">
                    <Field label="Definición">
                        <select
                            className="ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm"
                            value={genDefinition}
                            onChange={(event) => setGenDefinition(event.target.value)}
                        >
                            <option value="">Selecciona…</option>
                            {definitions.map((definition) => (
                                <option key={definition.id} value={definition.id}>
                                    {definition.name} · {templateLabel(definition.template_id)}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Desde">
                        <Input type="date" value={start} onChange={(event) => setStart(event.target.value)} />
                    </Field>
                    <Field label="Hasta">
                        <Input type="date" value={end} onChange={(event) => setEnd(event.target.value)} />
                    </Field>

                    {genDefinition !== '' && start !== '' && end !== '' && (
                        periodHasData ? (
                            <p className="ir-text-xs ir-text-emerald-600">✓ Hay datos sincronizados para este periodo.</p>
                        ) : (
                            <p className="ir-text-xs ir-text-amber-600">
                                ⚠ Este sitio no tiene datos sincronizados para este periodo
                                {snapshotPeriods.length > 0 && (
                                    <> (datos disponibles: {snapshotPeriods.map((period) => `${period.period_start.slice(0, 10)}→${period.period_end.slice(0, 10)}`).join(', ')})</>
                                )}
                                . El reporte saldría sin métricas. Sincroniza ese mes en el Editor antes de generar.
                            </p>
                        )
                    )}

                    <Button type="submit" disabled={generate.isPending}>
                        Generar
                    </Button>
                    {generate.isSuccess && (
                        <p className="ir-text-xs ir-text-muted-foreground">
                            Generación encolada. La lista se actualiza en unos segundos.
                        </p>
                    )}
                </form>
            </Card>

            <Card title="Reportes">
                {send.isSuccess && (
                    <p className="ir-mb-3 ir-text-xs ir-text-emerald-600">
                        Envío encolado: el reporte se enviará por email a los destinatarios.
                    </p>
                )}
                {syncSite.isSuccess && (
                    <p className="ir-mb-3 ir-text-xs ir-text-emerald-600">
                        Sincronización encolada. Espera unos segundos y pulsa «Regenerar» en ese reporte.
                    </p>
                )}
                <DataTable columns={columns} data={reports} />
            </Card>

            {narrativeFor !== null &&
                (() => {
                    const report = reports.find((item) => item.id === narrativeFor);

                    return report !== undefined ? <ReportNarrativePanel key={report.id} report={report} /> : null;
                })()}

            {commentsFor !== null && <ReportCommentsPanel key={commentsFor} reportId={commentsFor} />}

            {insightsFor !== null && (
                <Card title="Insights de IA">
                    {insights.isPending ? (
                        <p className="ir-text-sm ir-text-muted-foreground">Analizando los datos del reporte…</p>
                    ) : insights.isError ? (
                        <p className="ir-text-sm ir-text-red-500">No se pudieron generar los insights.</p>
                    ) : (insights.data ?? []).length === 0 ? (
                        <p className="ir-text-sm ir-text-muted-foreground">Sin datos suficientes para generar insights.</p>
                    ) : (
                        <ul className="ir-flex ir-flex-col ir-gap-2">
                            {(insights.data ?? []).map((insight, index) => (
                                <li key={index} className="ir-flex ir-gap-2 ir-text-sm">
                                    <Sparkles className="ir-mt-0.5 ir-size-4 ir-shrink-0 ir-text-primary" />
                                    <span>{insight}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}
        </div>
    );
}
