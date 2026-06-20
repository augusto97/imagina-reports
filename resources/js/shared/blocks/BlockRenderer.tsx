import { ArrowDownRight, ArrowUpRight, ShieldCheck } from 'lucide-react';
import { type CSSProperties, type ReactElement } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Legend,
    Line,
    LineChart,
    Pie,
    PieChart,
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

/* -------------------------------- styling ---------------------------------- */

/** Slice/series palette — starts with the agency accent, then complementary hues. */
const CHART_COLORS = ['hsl(var(--ir-primary))', '#6366f1', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#a855f7', '#ec4899'];

const PAD: Record<string, string> = { sm: 'ir-p-3', md: 'ir-p-6', lg: 'ir-p-10' };
const RADIUS: Record<string, string> = { none: 'ir-rounded-none', sm: 'ir-rounded', md: 'ir-rounded-lg', lg: 'ir-rounded-2xl' };
const ALIGN: Record<string, string> = { left: 'ir-text-left', center: 'ir-text-center', right: 'ir-text-right' };

type Style = Record<string, unknown> | undefined;

/** Inline CSS (background/text colour) from a block's style overrides. */
function styleCss(s: Style): CSSProperties {
    const css: CSSProperties = {};
    if (typeof s?.bg === 'string' && s.bg !== '') {
        css.backgroundColor = s.bg;
    }
    if (typeof s?.color === 'string' && s.color !== '') {
        css.color = s.color;
    }

    return css;
}

/** Format a KPI/sales number per the block's `style.format`. */
function formatNumber(value: number, format: string): string {
    switch (format) {
        case 'compact':
            return new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(value);
        case 'percent':
            return `${value.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
        case 'currency':
            return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(value);
        default:
            return value.toLocaleString();
    }
}

/* -------------------------------- sections --------------------------------- */

function Section({ title, style: s, children }: { title?: string; style?: Style; children: React.ReactNode }): ReactElement {
    const pad = PAD[str(s?.pad)] ?? 'ir-p-6';
    const radius = RADIUS[str(s?.radius)] ?? 'ir-rounded-lg';
    const align = ALIGN[str(s?.align)] ?? '';
    const border = s?.border === false ? '' : 'ir-border';
    const showTitle = title !== undefined && title !== '' && s?.hideTitle !== true;

    return (
        <section className={cn('ir-bg-card', pad, radius, align, border)} style={styleCss(s)}>
            {showTitle && <h3 className="ir-mb-3 ir-text-sm ir-font-semibold ir-text-muted-foreground">{title}</h3>}
            {children}
        </section>
    );
}

/* --------------------------------- blocks ---------------------------------- */

function HeaderBlock({ block, data }: BlockComponentProps): ReactElement {
    const title = str((data as Record<string, unknown> | undefined)?.title, str(prop(block, 'title'), 'Reporte'));

    return (
        <header className="ir-flex ir-items-center ir-justify-between ir-border-b ir-pb-6" style={styleCss(block.style)}>
            <h1 className="ir-text-2xl ir-font-semibold">{title}</h1>
        </header>
    );
}

/** Semicircular SVG gauge (0–100) with a semantic color — prints cleanly to PDF. */
function Gauge({ score }: { score: number }): ReactElement {
    const clamped = Math.max(0, Math.min(100, score));
    const radius = 80;
    const arcLength = Math.PI * radius; // half circumference
    const stroke = clamped >= 80 ? '#10b981' : clamped >= 50 ? '#f59e0b' : '#ef4444';

    return (
        <div className="ir-relative ir-mx-auto ir-w-full ir-max-w-[220px]">
            <svg viewBox="0 0 200 120" className="ir-w-full">
                <path d="M 20 100 A 80 80 0 0 1 180 100" fill="none" stroke="hsl(var(--ir-muted))" strokeWidth="14" strokeLinecap="round" />
                <path
                    d="M 20 100 A 80 80 0 0 1 180 100"
                    fill="none"
                    stroke={stroke}
                    strokeWidth="14"
                    strokeLinecap="round"
                    strokeDasharray={`${(clamped / 100) * arcLength} ${arcLength}`}
                />
                <text x="100" y="92" textAnchor="middle" className="ir-fill-foreground" style={{ fontSize: '34px', fontWeight: 700 }}>
                    {clamped}
                </text>
                <text x="100" y="112" textAnchor="middle" className="ir-fill-muted-foreground" style={{ fontSize: '11px' }}>
                    / 100
                </text>
            </svg>
        </div>
    );
}

function HealthScoreBlock({ block, data }: BlockComponentProps): ReactElement {
    const score = typeof data === 'number' ? data : Number(data) || 0;

    return (
        <Section title={str(prop(block, 'title'), 'Estado general')} style={block.style}>
            <Gauge score={score} />
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
        <Section style={block.style}>
            <p className="ir-text-sm ir-text-muted-foreground">{str(prop(block, 'label'))}</p>
            <p className="ir-text-3xl ir-font-semibold">{formatNumber(value, str(block.style?.format))}</p>
            {changePercent !== null && <TrendBadge percent={changePercent} />}
        </Section>
    );
}

function ChartBlock({ block, data }: BlockComponentProps): ReactElement {
    const rows = asChartRows(data);
    const chartType = str(prop(block, 'chartType'), 'line');
    const legend = block.style?.legend === true;
    const accent = 'hsl(var(--ir-primary))';

    return (
        <Section title={str(prop(block, 'title'))} style={block.style}>
            <div className="ir-h-64 ir-w-full">
                <ResponsiveContainer width="100%" height="100%">
                    {chartType === 'bar' ? (
                        <BarChart data={rows}>
                            <CartesianGrid strokeDasharray="3 3" />
                            <XAxis dataKey="name" />
                            <YAxis />
                            <Tooltip />
                            {legend && <Legend />}
                            <Bar dataKey="value" fill={accent} radius={[4, 4, 0, 0]} />
                        </BarChart>
                    ) : chartType === 'area' ? (
                        <AreaChart data={rows}>
                            <CartesianGrid strokeDasharray="3 3" />
                            <XAxis dataKey="name" />
                            <YAxis />
                            <Tooltip />
                            {legend && <Legend />}
                            <Area dataKey="value" stroke={accent} fill="hsl(var(--ir-muted))" />
                        </AreaChart>
                    ) : chartType === 'donut' || chartType === 'pie' ? (
                        <PieChart>
                            <Tooltip />
                            {legend && <Legend />}
                            <Pie
                                data={rows}
                                dataKey="value"
                                nameKey="name"
                                innerRadius={chartType === 'donut' ? 55 : 0}
                                outerRadius={92}
                                paddingAngle={2}
                            >
                                {rows.map((_, index) => (
                                    <Cell key={index} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                                ))}
                            </Pie>
                        </PieChart>
                    ) : (
                        <LineChart data={rows}>
                            <CartesianGrid strokeDasharray="3 3" />
                            <XAxis dataKey="name" />
                            <YAxis />
                            <Tooltip />
                            {legend && <Legend />}
                            <Line dataKey="value" stroke={accent} strokeWidth={2} dot={false} />
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
    const bars = block.style?.bars === true;
    const max = bars ? Math.max(0, ...rows.map((row) => Number(row.value) || 0)) : 0;

    return (
        <Section title={str(prop(block, 'title'))} style={block.style}>
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
                                <td key={col} className="ir-py-1.5">
                                    {bars && col === 'value' && typeof row[col] !== 'undefined' ? (
                                        <div className="ir-flex ir-items-center ir-gap-2">
                                            <div className="ir-h-1.5 ir-flex-1 ir-overflow-hidden ir-rounded ir-bg-muted">
                                                <div
                                                    className="ir-h-full ir-rounded ir-bg-primary"
                                                    style={{ width: `${max > 0 ? ((Number(row[col]) || 0) / max) * 100 : 0}%` }}
                                                />
                                            </div>
                                            <span className="ir-tabular-nums">{Number(row[col]).toLocaleString()}</span>
                                        </div>
                                    ) : (
                                        String(row[col] ?? '')
                                    )}
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
        <Section title={str(prop(block, 'title'))} style={block.style}>
            <p className="ir-whitespace-pre-line ir-text-sm ir-leading-relaxed">{text}</p>
        </Section>
    );
}

const SECURITY_STATS: { key: string; label: string }[] = [
    { key: 'threats_blocked', label: 'Amenazas bloqueadas' },
    { key: 'attacks_blocked', label: 'Ataques bloqueados' },
    { key: 'malware_found', label: 'Malware detectado' },
];

function SecurityShieldBlock({ block, data }: BlockComponentProps): ReactElement {
    const stats = data !== null && typeof data === 'object' ? (data as Record<string, unknown>) : {};
    const tiles = SECURITY_STATS.filter((stat) => typeof stats[stat.key] === 'number');

    return (
        <Section title={str(prop(block, 'title'), 'Seguridad')} style={block.style}>
            <div className="ir-flex ir-items-center ir-gap-3">
                <ShieldCheck className="ir-size-8 ir-shrink-0 ir-text-emerald-500" />
                <p className="ir-text-sm ir-text-muted-foreground">
                    Tu sitio está protegido y monitorizado.
                </p>
            </div>
            {tiles.length > 0 && (
                <div className="ir-mt-4 ir-grid ir-grid-cols-3 ir-gap-3">
                    {tiles.map((stat) => (
                        <div key={stat.key} className="ir-rounded-md ir-bg-muted ir-p-3 ir-text-center">
                            <p className="ir-text-2xl ir-font-semibold">{Number(stats[stat.key]).toLocaleString()}</p>
                            <p className="ir-text-xs ir-text-muted-foreground">{stat.label}</p>
                        </div>
                    ))}
                </div>
            )}
        </Section>
    );
}

function WorklogTimelineBlock({ block, data }: BlockComponentProps): ReactElement {
    const entries = asRecords(data);

    return (
        <Section title={str(prop(block, 'title'), 'Lo que hicimos este mes')} style={block.style}>
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
        <Section title={str(prop(block, 'title'), 'Ventas')} style={block.style}>
            <p className="ir-text-3xl ir-font-semibold">{formatNumber(value, str(block.style?.format))}</p>
            {changePercent !== null && <TrendBadge percent={changePercent} />}
        </Section>
    );
}

function CustomBlock({ block }: BlockComponentProps): ReactElement {
    return (
        <Section title={str(prop(block, 'title'))} style={block.style}>
            {str(prop(block, 'text'))}
        </Section>
    );
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
