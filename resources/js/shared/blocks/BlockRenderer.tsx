import { ArrowDownRight, ArrowUpRight, ShieldCheck } from 'lucide-react';
import { type ReactElement } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import { cn } from '@shared/lib/utils';

import type { Block, BlockComponentProps, BlockType } from './types';

/* ------------------------------- prop helpers ------------------------------ */

function str(value: unknown, fallback = ''): string {
    return typeof value === 'string' ? value : fallback;
}

function prop(block: Block, key: string): unknown {
    return block.props?.[key];
}

interface ChartRow {
    name: string;
    value: number;
}

function asChartRows(data: unknown): ChartRow[] {
    if (!Array.isArray(data)) {
        return [];
    }

    return data.map((row): ChartRow => {
        if (row !== null && typeof row === 'object') {
            const record = row as Record<string, unknown>;
            const name = str(record.date, str(record.label));
            const value = typeof record.value === 'number' ? record.value : Number(record.value) || 0;

            return { name, value };
        }

        return { name: '', value: 0 };
    });
}

function asRecords(data: unknown): Record<string, unknown>[] {
    if (!Array.isArray(data)) {
        return [];
    }

    return data.filter((row): row is Record<string, unknown> => row !== null && typeof row === 'object');
}

/* -------------------------------- sections --------------------------------- */

function Section({ title, children }: { title?: string; children: React.ReactNode }): ReactElement {
    return (
        <section className="ir-rounded-lg ir-border ir-bg-card ir-p-6">
            {title !== undefined && title !== '' && (
                <h3 className="ir-mb-3 ir-text-sm ir-font-semibold ir-text-muted-foreground">{title}</h3>
            )}
            {children}
        </section>
    );
}

/* --------------------------------- blocks ---------------------------------- */

function HeaderBlock({ block, data }: BlockComponentProps): ReactElement {
    const title = str((data as Record<string, unknown> | undefined)?.title, str(prop(block, 'title'), 'Reporte'));

    return (
        <header className="ir-flex ir-items-center ir-justify-between ir-border-b ir-pb-6">
            <h1 className="ir-text-2xl ir-font-semibold">{title}</h1>
        </header>
    );
}

function HealthScoreBlock({ block, data }: BlockComponentProps): ReactElement {
    const score = typeof data === 'number' ? data : Number(data) || 0;
    const tone = score >= 80 ? 'ir-text-emerald-500' : score >= 50 ? 'ir-text-amber-500' : 'ir-text-red-500';

    return (
        <Section title={str(prop(block, 'title'), 'Estado general')}>
            <div className={cn('ir-text-5xl ir-font-bold', tone)}>{score}</div>
            <p className="ir-text-sm ir-text-muted-foreground">/ 100</p>
        </Section>
    );
}

interface Kpi {
    value: number;
    changePercent: number | null;
}

function asKpi(data: unknown): Kpi {
    if (data !== null && typeof data === 'object' && 'value' in data) {
        const record = data as Record<string, unknown>;
        const change = record.change_percent;

        return {
            value: typeof record.value === 'number' ? record.value : Number(record.value) || 0,
            changePercent: typeof change === 'number' ? change : null,
        };
    }

    return { value: typeof data === 'number' ? data : Number(data) || 0, changePercent: null };
}

/** A trend pill: green/red arrow + signed percent vs the previous period. */
function TrendBadge({ percent }: { percent: number }): ReactElement {
    const up = percent >= 0;
    const Arrow = up ? ArrowUpRight : ArrowDownRight;
    const tone = up ? 'ir-text-emerald-600' : 'ir-text-red-500';

    return (
        <span className={cn('ir-mt-1 ir-inline-flex ir-items-center ir-gap-1 ir-text-sm ir-font-medium', tone)}>
            <Arrow className="ir-size-4" />
            {up ? '+' : ''}
            {percent.toLocaleString(undefined, { maximumFractionDigits: 1 })}%
            <span className="ir-text-xs ir-font-normal ir-text-muted-foreground">vs. periodo anterior</span>
        </span>
    );
}

function KpiBlock({ block, data }: BlockComponentProps): ReactElement {
    const { value, changePercent } = asKpi(data);

    return (
        <Section>
            <p className="ir-text-sm ir-text-muted-foreground">{str(prop(block, 'label'))}</p>
            <p className="ir-text-3xl ir-font-semibold">{value.toLocaleString()}</p>
            {changePercent !== null && <TrendBadge percent={changePercent} />}
        </Section>
    );
}

function ChartBlock({ block, data }: BlockComponentProps): ReactElement {
    const rows = asChartRows(data);
    const chartType = str(prop(block, 'chartType'), 'line');

    return (
        <Section title={str(prop(block, 'title'))}>
            <div className="ir-h-64 ir-w-full">
                <ResponsiveContainer width="100%" height="100%">
                    {chartType === 'bar' ? (
                        <BarChart data={rows}>
                            <CartesianGrid strokeDasharray="3 3" />
                            <XAxis dataKey="name" />
                            <YAxis />
                            <Tooltip />
                            <Bar dataKey="value" fill="hsl(var(--ir-primary))" />
                        </BarChart>
                    ) : chartType === 'area' ? (
                        <AreaChart data={rows}>
                            <CartesianGrid strokeDasharray="3 3" />
                            <XAxis dataKey="name" />
                            <YAxis />
                            <Tooltip />
                            <Area dataKey="value" stroke="hsl(var(--ir-primary))" fill="hsl(var(--ir-muted))" />
                        </AreaChart>
                    ) : (
                        <LineChart data={rows}>
                            <CartesianGrid strokeDasharray="3 3" />
                            <XAxis dataKey="name" />
                            <YAxis />
                            <Tooltip />
                            <Line dataKey="value" stroke="hsl(var(--ir-primary))" dot={false} />
                        </LineChart>
                    )}
                </ResponsiveContainer>
            </div>
        </Section>
    );
}

function TableBlock({ block, data }: BlockComponentProps): ReactElement | null {
    const rows = asRecords(data);

    if (rows.length === 0) {
        return null;
    }

    const columns = Object.keys(rows[0] ?? {});

    return (
        <Section title={str(prop(block, 'title'))}>
            <table className="ir-w-full ir-text-left ir-text-sm">
                <thead>
                    <tr className="ir-text-muted-foreground">
                        {columns.map((col) => (
                            <th key={col} className="ir-py-1 ir-font-medium">
                                {col}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr key={index} className="ir-border-t">
                            {columns.map((col) => (
                                <td key={col} className="ir-py-1">
                                    {String(row[col] ?? '')}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </Section>
    );
}

function NarrativeBlock({ block, data }: BlockComponentProps): ReactElement {
    const text = str(data, str(prop(block, 'text')));

    return (
        <Section title={str(prop(block, 'title'))}>
            <p className="ir-whitespace-pre-line ir-text-sm ir-leading-relaxed">{text}</p>
        </Section>
    );
}

function SecurityShieldBlock({ block }: BlockComponentProps): ReactElement {
    return (
        <Section title={str(prop(block, 'title'), 'Seguridad')}>
            <div className="ir-flex ir-items-center ir-gap-3">
                <ShieldCheck className="ir-size-8 ir-text-emerald-500" />
                <p className="ir-text-sm ir-text-muted-foreground">
                    Tu sitio está protegido y monitorizado.
                </p>
            </div>
        </Section>
    );
}

function WorklogTimelineBlock({ block, data }: BlockComponentProps): ReactElement {
    const entries = asRecords(data);

    return (
        <Section title={str(prop(block, 'title'), 'Lo que hicimos este mes')}>
            <ul className="ir-space-y-2">
                {entries.map((entry, index) => (
                    <li key={index} className="ir-flex ir-gap-3 ir-text-sm">
                        <span className="ir-text-muted-foreground">{str(entry.performed_at)}</span>
                        <span>{str(entry.description)}</span>
                    </li>
                ))}
            </ul>
        </Section>
    );
}

function ImageBlock({ block }: BlockComponentProps): ReactElement | null {
    const url = str(prop(block, 'url'));

    return url === '' ? null : <img src={url} alt={str(prop(block, 'alt'))} className="ir-w-full ir-rounded-lg" />;
}

function DividerBlock(): ReactElement {
    return <hr className="ir-border-border" />;
}

function SalesSummaryBlock({ block, data }: BlockComponentProps): ReactElement {
    const { value, changePercent } = asKpi(data);

    return (
        <Section title={str(prop(block, 'title'), 'Ventas')}>
            <p className="ir-text-3xl ir-font-semibold">{value.toLocaleString()}</p>
            {changePercent !== null && <TrendBadge percent={changePercent} />}
        </Section>
    );
}

function CustomBlock({ block }: BlockComponentProps): ReactElement {
    return <Section title={str(prop(block, 'title'))}>{str(prop(block, 'text'))}</Section>;
}

/* ------------------------------- dispatcher -------------------------------- */

const registry: Record<BlockType, (props: BlockComponentProps) => ReactElement | null> = {
    header: HeaderBlock,
    kpi: KpiBlock,
    chart: ChartBlock,
    table: TableBlock,
    narrative: NarrativeBlock,
    healthscore: HealthScoreBlock,
    security_shield: SecurityShieldBlock,
    worklog_timeline: WorklogTimelineBlock,
    image: ImageBlock,
    divider: DividerBlock,
    sales_summary: SalesSummaryBlock,
    custom: CustomBlock,
};

export function BlockRenderer({ block, data }: BlockComponentProps): ReactElement | null {
    const Component = registry[block.type] ?? CustomBlock;

    return <Component block={block} data={data} />;
}

/** Column span (out of 6) for a block's configured width. Defaults to full width. */
function widthSpan(block: Block): string {
    const width = block.style?.width;

    if (width === 'third') {
        return 'ir-col-span-6 sm:ir-col-span-2';
    }
    if (width === 'half') {
        return 'ir-col-span-6 sm:ir-col-span-3';
    }

    return 'ir-col-span-6';
}

/**
 * Render an ordered list of blocks on a 6-column grid (single source of truth for
 * the editor preview, the portal and the PDF). Each block flows into the next column
 * according to its `style.width` (full / half / third), so a row of KPI cards sits
 * side by side like a real report (CLAUDE.md §11.4/§11.5).
 */
export function BlockList({
    blocks,
    data = {},
}: {
    blocks: Block[];
    data?: Record<string, unknown>;
}): ReactElement {
    return (
        <div className="ir-grid ir-grid-cols-6 ir-gap-6">
            {blocks.map((block) => (
                <div key={block.id} className={widthSpan(block)}>
                    <BlockRenderer block={block} data={data[block.id]} />
                </div>
            ))}
        </div>
    );
}
