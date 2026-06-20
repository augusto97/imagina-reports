import { type ColumnDef } from '@tanstack/react-table';
import { type FormEvent, type ReactElement, useState } from 'react';

import { Sparkles } from 'lucide-react';

import {
    useApproveReport,
    useCreateReportDefinition,
    useGenerateReport,
    useReportDefinitions,
    useReportInsights,
    useReportTemplates,
    useReports,
    useSendReport,
    useSites,
} from '../api';
import { DataTable } from '../components/DataTable';
import { Button, Card, Field, Input } from '../components/ui';
import type { ReportSummary } from '../types';

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
    const generate = useGenerateReport();
    const approve = useApproveReport();
    const send = useSendReport();
    const insights = useReportInsights();

    const [insightsFor, setInsightsFor] = useState<number | null>(null);
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

    const columns: ColumnDef<ReportSummary>[] = [
        {
            id: 'period',
            header: 'Periodo',
            accessorFn: (row) => `${row.period_start.slice(0, 10)} → ${row.period_end.slice(0, 10)}`,
        },
        { header: 'Salud', accessorKey: 'health_score' },
        { header: 'Estado', accessorKey: 'status' },
        {
            id: 'actions',
            header: '',
            cell: ({ row }) => {
                const report = row.original;

                return (
                    <div className="ir-flex ir-items-center ir-gap-3">
                        <a className="ir-font-medium ir-underline" href={`/reports/${report.public_token}`} target="_blank" rel="noreferrer">
                            Ver
                        </a>
                        <Button variant="ghost" onClick={() => showInsights(report.id)} disabled={insights.isPending && insightsFor === report.id}>
                            <Sparkles className="ir-size-4" />
                            Insights
                        </Button>
                        {report.status === 'draft' ? (
                            <Button variant="ghost" onClick={() => approve.mutate(report.id)} disabled={approve.isPending}>
                                Aprobar
                            </Button>
                        ) : (
                            <Button variant="ghost" onClick={() => send.mutate(report.id)} disabled={send.isPending}>
                                {report.status === 'sent' ? 'Reenviar' : 'Enviar'}
                            </Button>
                        )}
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
                                    {definition.name}
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
                <DataTable columns={columns} data={reports} />
            </Card>

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
