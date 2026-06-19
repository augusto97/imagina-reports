import { type ColumnDef } from '@tanstack/react-table';
import { type FormEvent, type ReactElement, useState } from 'react';

import {
    useCreateReportDefinition,
    useGenerateReport,
    useReportDefinitions,
    useReports,
    useSites,
} from '../api';
import { DataTable } from '../components/DataTable';
import { Button, Card, Field, Input } from '../components/ui';
import type { ReportSummary } from '../types';

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
        cell: ({ row }) => (
            <a
                className="ir-font-medium ir-underline"
                href={`/reports/${row.original.public_token}`}
                target="_blank"
                rel="noreferrer"
            >
                Ver reporte
            </a>
        ),
    },
];

export function ReportsScreen(): ReactElement {
    const { data: sites = [] } = useSites();
    const { data: definitions = [] } = useReportDefinitions();
    const { data: reports = [] } = useReports();
    const createDefinition = useCreateReportDefinition();
    const generate = useGenerateReport();

    const [defSite, setDefSite] = useState('');
    const [defName, setDefName] = useState('');
    const [genDefinition, setGenDefinition] = useState('');
    const [start, setStart] = useState('');
    const [end, setEnd] = useState('');

    const submitDefinition = (event: FormEvent): void => {
        event.preventDefault();
        if (defSite === '' || defName === '') {
            return;
        }
        createDefinition.mutate(
            { site_id: Number(defSite), name: defName },
            { onSuccess: () => setDefName('') },
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
                <DataTable columns={columns} data={reports} />
            </Card>
        </div>
    );
}
