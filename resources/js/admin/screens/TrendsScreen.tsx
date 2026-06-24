import { type ColumnDef } from '@tanstack/react-table';
import { type ReactElement } from 'react';
import {
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import { useTrends } from '../api';
import { DataTable } from '../components/DataTable';
import { Card } from '../components/ui';
import type { SiteTrend } from '../types';

const COLORS = ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#3b82f6', '#a855f7', '#14b8a6', '#f97316'];

function healthClass(score: number | null): string {
    if (score === null) return 'ir-text-muted-foreground';
    if (score >= 80) return 'ir-text-emerald-600';
    if (score >= 50) return 'ir-text-amber-600';
    return 'ir-text-red-600';
}

const columns: ColumnDef<SiteTrend>[] = [
    { header: 'Sitio', accessorKey: 'site_name' },
    {
        id: 'client',
        header: 'Cliente',
        accessorFn: (row) => row.client_name ?? '—',
    },
    {
        id: 'health',
        header: 'Salud actual',
        cell: ({ row }) => (
            <span className={`ir-font-semibold ${healthClass(row.original.latest_health_score)}`}>
                {row.original.latest_health_score ?? '—'}
            </span>
        ),
    },
    { header: 'Reportes', accessorKey: 'reports_count' },
];

interface ChartRow {
    period_end: string;
    [siteName: string]: string | number | null;
}

function buildChartData(sites: SiteTrend[]): ChartRow[] {
    const periods = Array.from(
        new Set(sites.flatMap((site) => site.health_series.map((point) => point.period_end))),
    ).sort();

    return periods.map((period) => {
        const row: ChartRow = { period_end: period };
        sites.forEach((site) => {
            row[site.site_name] = site.health_series.find((point) => point.period_end === period)?.health_score ?? null;
        });
        return row;
    });
}

export function TrendsScreen(): ReactElement {
    const { data: trends, isLoading } = useTrends();

    if (isLoading || trends === undefined) {
        return <p className="ir-text-sm ir-text-muted-foreground">Cargando tendencias…</p>;
    }

    if (trends.sites.length === 0) {
        return (
            <Card title="Tendencias">
                <p className="ir-text-sm ir-text-muted-foreground">
                    Aún no hay reportes generados. Genera reportes para ver la evolución de la salud por sitio.
                </p>
            </Card>
        );
    }

    const chartData = buildChartData(trends.sites);

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <div className="ir-grid ir-grid-cols-1 ir-gap-4 sm:ir-grid-cols-3">
                <Card title="Sitios">
                    <p className="ir-text-3xl ir-font-semibold">{trends.summary.sites_count}</p>
                </Card>
                <Card title="Salud promedio">
                    <p className={`ir-text-3xl ir-font-semibold ${healthClass(trends.summary.average_health_score)}`}>
                        {trends.summary.average_health_score ?? '—'}
                    </p>
                </Card>
                <Card title="Reportes">
                    <p className="ir-text-3xl ir-font-semibold">{trends.summary.reports_count}</p>
                </Card>
            </div>

            <Card title="Evolución de la salud por sitio">
                <div className="ir-h-72">
                    <ResponsiveContainer width="100%" height="100%">
                        <LineChart data={chartData}>
                            <CartesianGrid strokeDasharray="3 3" className="ir-stroke-muted" />
                            <XAxis dataKey="period_end" fontSize={12} tickFormatter={(value: string) => value.slice(0, 7)} />
                            <YAxis domain={[0, 100]} fontSize={12} width={32} />
                            <Tooltip />
                            <Legend />
                            {trends.sites.map((site, index) => (
                                <Line
                                    key={site.site_id}
                                    type="monotone"
                                    dataKey={site.site_name}
                                    stroke={COLORS[index % COLORS.length]}
                                    strokeWidth={2}
                                    dot={false}
                                    connectNulls
                                />
                            ))}
                        </LineChart>
                    </ResponsiveContainer>
                </div>
            </Card>

            <Card title="Comparativa de clientes">
                <DataTable columns={columns} data={trends.sites} />
            </Card>
        </div>
    );
}
