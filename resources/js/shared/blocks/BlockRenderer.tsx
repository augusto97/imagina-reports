import { ArrowDownRight, ArrowUpRight, ShieldCheck } from 'lucide-react';
import { type CSSProperties, type ReactElement, type ReactNode, createContext, useContext, useMemo, useState } from 'react';
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

import { hexToHslString } from '@shared/lib/color';

import { GRID_COLS, GRID_MARGIN, GRID_ROW_HEIGHT } from './types';
import type { Block, BlockComponentProps, BlockLayout, BlockType } from './types';

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

/**
 * Per-report render settings — currency (the site's, shown as-is with no FX
 * conversion, CLAUDE.md §5) and locale. Provided by BlockList / ReportSettingsProvider
 * and read by the currency-formatting blocks.
 */
interface ReportSettings {
    currency: string;
    locale?: string;
    density?: 'normal' | 'compact';
}

const ReportSettingsContext = createContext<ReportSettings>({ currency: 'USD' });

export function ReportSettingsProvider({
    currency = 'USD',
    locale,
    density,
    children,
}: {
    currency?: string;
    locale?: string;
    density?: 'normal' | 'compact';
    children: ReactNode;
}): ReactElement {
    return <ReportSettingsContext.Provider value={{ currency, locale, density }}>{children}</ReportSettingsContext.Provider>;
}

/**
 * Active page-level filters (CLAUDE.md §11.3): a map of row-field → selected value, set
 * by `control` blocks and applied to the list blocks on the page. Empty by default, so
 * with no control present every block renders exactly as before (no regression). This is
 * an honest client-side ROW filter over the pre-aggregated snapshot rows — not a
 * cross-filter that re-queries raw data (which the snapshot model doesn't hold, §3.3).
 */
interface FilterContextValue {
    filters: Record<string, string>;
    setFilter: (field: string, value: string) => void;
}

const ReportFilterContext = createContext<FilterContextValue>({ filters: {}, setFilter: () => {} });

/**
 * Keep only the rows matching the active filters. A row is kept when, for every active
 * filter, it either matches the selected value (on the named field, or a `name`/`label`
 * fallback) OR simply doesn't carry that field — so a filter never blanks out an unrelated
 * block that lacks the dimension.
 */
function useFilteredRows<T>(rows: T[]): T[] {
    const { filters } = useContext(ReportFilterContext);
    const active = Object.entries(filters).filter(([, value]) => value !== '');

    if (active.length === 0) {
        return rows;
    }

    return rows.filter((row) => {
        if (typeof row !== 'object' || row === null) {
            return true;
        }
        const record = row as Record<string, unknown>;

        return active.every(([field, value]) => {
            const cell = record[field] ?? record.name ?? record.label;

            return cell === undefined || String(cell) === value;
        });
    });
}

/** Format a KPI/sales number per the block's `style.format`, in the report's currency/locale. */
function formatNumber(value: number, format: string, settings: ReportSettings = { currency: 'USD' }): string {
    const { currency, locale } = settings;

    switch (format) {
        case 'compact':
            return new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 }).format(value);
        case 'percent':
            return `${value.toLocaleString(locale, { maximumFractionDigits: 1 })}%`;
        case 'currency':
            return new Intl.NumberFormat(locale, { style: 'currency', currency, maximumFractionDigits: 0 }).format(value);
        default:
            return value.toLocaleString(locale);
    }
}

/* -------------------------------- sections --------------------------------- */

function Section({ title, style: s, children }: { title?: string; style?: Style; children: React.ReactNode }): ReactElement {
    const settings = useContext(ReportSettingsContext);
    const pad = PAD[str(s?.pad)] ?? (settings.density === 'compact' ? 'ir-p-4' : 'ir-p-6');
    const radius = RADIUS[str(s?.radius)] ?? 'ir-rounded-lg';
    const align = ALIGN[str(s?.align)] ?? '';
    const border = s?.border === false ? '' : 'ir-border';
    const showTitle = title !== undefined && title !== '' && s?.hideTitle !== true;

    return (
        <section className={cn('ir-flex ir-h-full ir-flex-col ir-bg-card', pad, radius, align, border)} style={styleCss(s)}>
            {showTitle && <h3 className="ir-mb-3 ir-text-sm ir-font-semibold ir-text-muted-foreground">{title}</h3>}
            <div className="ir-min-h-0 ir-flex-1">{children}</div>
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
    const settings = useContext(ReportSettingsContext);

    return (
        <Section style={block.style}>
            <p className="ir-text-sm ir-text-muted-foreground">{str(prop(block, 'label'))}</p>
            <p className="ir-text-3xl ir-font-semibold">{formatNumber(value, str(block.style?.format), settings)}</p>
            {changePercent !== null && <TrendBadge percent={changePercent} />}
        </Section>
    );
}

function ChartBlock({ block, data }: BlockComponentProps): ReactElement {
    const rows = useFilteredRows(asChartRows(data));
    const chartType = str(prop(block, 'chartType'), 'line');
    const legend = block.style?.legend === true;
    const accent = 'hsl(var(--ir-primary))';

    return (
        <Section title={str(prop(block, 'title'))} style={block.style}>
            <div className="ir-h-full ir-min-h-[12rem] ir-w-full">
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
                    ) : chartType === 'hbar' ? (
                        <BarChart data={rows} layout="vertical">
                            <CartesianGrid strokeDasharray="3 3" />
                            <XAxis type="number" />
                            <YAxis type="category" dataKey="name" width={90} />
                            <Tooltip />
                            {legend && <Legend />}
                            <Bar dataKey="value" fill={accent} radius={[0, 4, 4, 0]} />
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
    const rows = useFilteredRows(asRecords(data));
    // Interactive sort for the portal (clicking a header). The PDF prints the
    // default order; the editor canvas is pointer-events-none so it stays static.
    const [sort, setSort] = useState<{ col: string; dir: 1 | -1 } | null>(null);

    const sorted = useMemo(() => {
        if (sort === null) {
            return rows;
        }

        return [...rows].sort((a, b) => {
            const av = a[sort.col];
            const bv = b[sort.col];
            const numeric = typeof av !== 'string' || !Number.isNaN(Number(av));
            const cmp = numeric ? (Number(av) || 0) - (Number(bv) || 0) : String(av ?? '').localeCompare(String(bv ?? ''));

            return cmp * sort.dir;
        });
    }, [rows, sort]);

    if (rows.length === 0) {
        return null;
    }

    const columns = Object.keys(rows[0] ?? {});
    const bars = block.style?.bars === true;
    const max = bars ? Math.max(0, ...rows.map((row) => Number(row.value) || 0)) : 0;
    const toggleSort = (col: string): void =>
        setSort((current) => (current?.col === col ? { col, dir: current.dir === 1 ? -1 : 1 } : { col, dir: -1 }));

    return (
        <Section title={str(prop(block, 'title'))} style={block.style}>
            <table className="ir-w-full ir-text-left ir-text-sm">
                <thead>
                    <tr className="ir-text-muted-foreground">
                        {columns.map((col) => (
                            <th key={col} className="ir-py-1 ir-font-medium">
                                <button type="button" className="ir-inline-flex ir-items-center ir-gap-1 hover:ir-text-foreground" onClick={() => toggleSort(col)}>
                                    {col}
                                    {sort?.col === col && <span className="ir-text-xs">{sort.dir === 1 ? '▲' : '▼'}</span>}
                                </button>
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {sorted.map((row, index) => (
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
    const entries = useFilteredRows(asRecords(data));

    // Total invested hours across the listed tasks (only those with logged time).
    const totalMinutes = entries.reduce((sum, entry) => sum + (typeof entry.minutes === 'number' ? entry.minutes : 0), 0);

    return (
        <Section title={str(prop(block, 'title'), 'Lo que hicimos este mes')} style={block.style}>
            <ul className="ir-space-y-2">
                {entries.map((entry, index) => (
                    <li key={index} className="ir-flex ir-items-baseline ir-gap-3 ir-text-sm">
                        <span className="ir-w-20 ir-shrink-0 ir-text-muted-foreground">{str(entry.performed_at)}</span>
                        <span className="ir-flex-1">{str(entry.description)}</span>
                        {typeof entry.category === 'string' && entry.category !== '' && (
                            <span className="ir-rounded ir-bg-muted ir-px-2 ir-py-0.5 ir-text-xs ir-text-muted-foreground">{entry.category}</span>
                        )}
                        {typeof entry.minutes === 'number' && entry.minutes > 0 && (
                            <span className="ir-shrink-0 ir-tabular-nums ir-text-muted-foreground">
                                {(entry.minutes / 60).toLocaleString(undefined, { maximumFractionDigits: 1 })} h
                            </span>
                        )}
                    </li>
                ))}
            </ul>
            {totalMinutes > 0 && (
                <p className="ir-mt-3 ir-border-t ir-pt-2 ir-text-right ir-text-sm ir-font-medium">
                    Total: {(totalMinutes / 60).toLocaleString(undefined, { maximumFractionDigits: 1 })} h
                </p>
            )}
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
    const settings = useContext(ReportSettingsContext);

    return (
        <Section title={str(prop(block, 'title'), 'Ventas')} style={block.style}>
            <p className="ir-text-3xl ir-font-semibold">{formatNumber(value, str(block.style?.format), settings)}</p>
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

/**
 * Retention call-to-action banner — the §11.5 closing block ("Your support plan is
 * active and protecting your site"). Accent-tinted, with an optional button.
 */
function CtaBlock({ block }: BlockComponentProps): ReactElement {
    const headline = str(prop(block, 'headline'), 'Tu plan de soporte está activo y protegiendo tu sitio.');
    const text = str(prop(block, 'text'));
    const buttonLabel = str(prop(block, 'buttonLabel'));
    const buttonUrl = str(prop(block, 'buttonUrl'), '#');

    return (
        <div className="ir-rounded-xl ir-border-2 ir-border-primary ir-bg-muted ir-p-6 ir-text-center">
            <p className="ir-text-lg ir-font-semibold ir-text-primary">{headline}</p>
            {text !== '' && <p className="ir-mt-1 ir-text-sm ir-text-muted-foreground">{text}</p>}
            {buttonLabel !== '' && (
                <a
                    href={buttonUrl}
                    className="ir-mt-4 ir-inline-block ir-rounded-lg ir-bg-primary ir-px-5 ir-py-2 ir-text-sm ir-font-medium ir-text-primary-foreground"
                >
                    {buttonLabel}
                </a>
            )}
        </div>
    );
}

/**
 * Goal / target block: a metric's current value against a target, with a progress
 * bar and an on-track (green) / behind (amber) tint. Competitor parity — "goal
 * tracking". Binds to a metric; the target lives in `props.target`.
 */
function GoalBlock({ block, data }: BlockComponentProps): ReactElement {
    const { value } = asKpi(data);
    const settings = useContext(ReportSettingsContext);
    // Target can come from the bound metric (e.g. worklog.hours_vs_plan → plan hours)
    // or, failing that, the block's own `target` prop.
    const dataTarget = data !== null && typeof data === 'object' && 'target' in data ? Number((data as Record<string, unknown>).target) : NaN;
    const target = !Number.isNaN(dataTarget) ? dataTarget : Number(prop(block, 'target')) || 0;
    const pct = target > 0 ? Math.min(100, (value / target) * 100) : 0;
    const onTrack = pct >= 100;
    const format = str(block.style?.format);

    return (
        <Section title={str(prop(block, 'label'), 'Meta')} style={block.style}>
            <div className="ir-flex ir-items-baseline ir-justify-between">
                <span className="ir-text-2xl ir-font-semibold">{formatNumber(value, format, settings)}</span>
                <span className="ir-text-sm ir-text-muted-foreground">/ {formatNumber(target, format, settings)}</span>
            </div>
            <div className="ir-mt-2 ir-h-2 ir-overflow-hidden ir-rounded ir-bg-muted">
                <div
                    className={cn('ir-h-full ir-rounded', onTrack ? 'ir-bg-emerald-500' : 'ir-bg-amber-500')}
                    style={{ width: `${pct}%` }}
                />
            </div>
            <p className={cn('ir-mt-1 ir-text-xs', onTrack ? 'ir-text-emerald-600' : 'ir-text-muted-foreground')}>
                {pct.toLocaleString(undefined, { maximumFractionDigits: 0 })}% de la meta
            </p>
        </Section>
    );
}

/** A4 page break for the PDF — forces a new printed page. The label is hidden in print. */
function PageBreakBlock(): ReactElement {
    return (
        <div className="ir-break-after-page">
            <div className="ir-flex ir-items-center ir-gap-2 ir-py-2 ir-text-[10px] ir-uppercase ir-tracking-wide ir-text-muted-foreground print:ir-hidden">
                <span className="ir-h-px ir-flex-1 ir-bg-border" />
                Salto de página
                <span className="ir-h-px ir-flex-1 ir-bg-border" />
            </div>
        </div>
    );
}

/**
 * Client-visible comments / annotations from the team (CLAUDE.md §11), rendered as
 * quoted note cards. Internal notes never reach this block (filtered server-side).
 */
function CommentsBlock({ block, data }: BlockComponentProps): ReactElement | null {
    const notes = asRecords(data);

    if (notes.length === 0) {
        return null;
    }

    return (
        <Section title={str(prop(block, 'title'), 'Comentarios del equipo')} style={block.style}>
            <div className="ir-flex ir-flex-col ir-gap-3">
                {notes.map((note, index) => (
                    <blockquote key={index} className="ir-border-l-2 ir-border-primary ir-pl-3 ir-text-sm">
                        <p>{str(note.body)}</p>
                        {typeof note.created_at === 'string' && note.created_at !== '' && (
                            <span className="ir-text-xs ir-text-muted-foreground">{note.created_at}</span>
                        )}
                    </blockquote>
                ))}
            </div>
        </Section>
    );
}

/**
 * A page-level filter control (CLAUDE.md §11.3): a dropdown of the distinct values from
 * its bound metric, which narrows the rows shown by the list blocks on the page. Honest
 * client-side row filtering over snapshot rows — no raw-data re-query.
 */
function ControlBlock({ block, data }: BlockComponentProps): ReactElement {
    const { filters, setFilter } = useContext(ReportFilterContext);
    const field = str(prop(block, 'field'), 'name');
    const options = Array.from(
        new Set(
            asChartRows(data)
                .map((row) => row.name)
                .filter((name): name is string => typeof name === 'string' && name !== ''),
        ),
    );

    return (
        <Section style={block.style}>
            <label className="ir-text-xs ir-text-muted-foreground">{str(prop(block, 'label'), 'Filtro')}</label>
            <select
                className="ir-mt-1 ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm"
                value={filters[field] ?? ''}
                onChange={(event) => setFilter(field, event.target.value)}
            >
                <option value="">Todos</option>
                {options.map((option) => (
                    <option key={option} value={option}>
                        {option}
                    </option>
                ))}
            </select>
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
    pagebreak: PageBreakBlock,
    sales_summary: SalesSummaryBlock,
    goal: GoalBlock,
    cta: CtaBlock,
    comments: CommentsBlock,
    control: ControlBlock,
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

/** CSS grid placement for a block on the 12-column dashboard grid (matches the editor canvas). */
function gridCellStyle(layout: BlockLayout): CSSProperties {
    return {
        gridColumn: `${layout.x + 1} / span ${layout.w}`,
        gridRow: `${layout.y + 1} / span ${layout.h}`,
        minHeight: 0,
        minWidth: 0,
    };
}

/** Replace {{token}} merge fields (e.g. {{client}}, {{period}}) with context values. */
function mergeFields(text: string, context: Record<string, string>): string {
    return text.replace(/\{\{\s*(\w+)\s*\}\}/g, (match, key: string) => context[key] ?? match);
}

/** Apply merge fields to every string prop of a block (header titles, narrative html, CTA…). */
function applyContext(block: Block, context: Record<string, string>): Block {
    if (block.props === undefined || block.props === null) {
        return block;
    }

    const props: Record<string, unknown> = {};
    for (const [key, value] of Object.entries(block.props)) {
        props[key] = typeof value === 'string' ? mergeFields(value, context) : value;
    }

    return { ...block, props };
}

/**
 * Render an ordered list of blocks on a 6-column grid (single source of truth for
 * the editor preview, the portal and the PDF). Each block flows into the next column
 * according to its `style.width` (full / half / third), so a row of KPI cards sits
 * side by side like a real report (CLAUDE.md §11.4/§11.5). When a `context` is given,
 * {{merge-fields}} in text blocks are resolved (client/site/period/score).
 */
export function BlockList({
    blocks,
    data = {},
    context,
    currency = 'USD',
    locale,
    theme,
}: {
    blocks: Block[];
    data?: Record<string, unknown>;
    context?: Record<string, string>;
    currency?: string;
    locale?: string;
    theme?: { accent?: string | null; density?: 'normal' | 'compact' | null } | null;
}): ReactElement {
    const resolved = context === undefined ? blocks : blocks.map((block) => applyContext(block, context));

    // Dashboard grid mode: when every block carries grid coordinates, place them on a
    // 12-column CSS grid identical to the editor canvas (CLAUDE.md §11.3/§11.4). Older
    // templates without coordinates keep the legacy width-flow so they still render.
    const useGrid = resolved.length > 0 && resolved.every((block) => block.layout != null);

    // Multi-page: group blocks by their page index; each page prints on its own sheet.
    const pages = groupByPage(resolved);

    // Per-report theme: scope the accent (CSS var, overriding the agency brand) and density.
    const density = theme?.density === 'compact' ? 'compact' : 'normal';
    const accentHsl = typeof theme?.accent === 'string' ? hexToHslString(theme.accent) : null;
    const themeStyle: CSSProperties = accentHsl !== null ? ({ '--ir-primary': accentHsl, '--ir-ring': accentHsl } as CSSProperties) : {};

    return (
        <div style={themeStyle}>
        <ReportSettingsProvider currency={currency} locale={locale} density={density}>
            <ReportFilterProvider>
            {pages.map((pageBlocks, pageIndex) => (
                <div key={pageIndex} className={pageIndex > 0 ? 'ir-break-before-page ir-mt-8' : undefined}>
                    {useGrid ? (
                        <div
                            className="ir-grid"
                            style={{
                                gridTemplateColumns: `repeat(${GRID_COLS}, 1fr)`,
                                gridAutoRows: `${GRID_ROW_HEIGHT}px`,
                                gap: `${GRID_MARGIN}px`,
                            }}
                        >
                            {pageBlocks.map((block) => (
                                <div key={block.id} style={gridCellStyle(block.layout as BlockLayout)} className="ir-overflow-hidden">
                                    <BlockRenderer block={block} data={data[block.id]} />
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="ir-grid ir-grid-cols-6 ir-gap-6">
                            {pageBlocks.map((block) => (
                                <div key={block.id} className={widthSpan(block)}>
                                    <BlockRenderer block={block} data={data[block.id]} />
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            ))}
            </ReportFilterProvider>
        </ReportSettingsProvider>
        </div>
    );
}

/** Stateful provider for page-level filter controls. */
function ReportFilterProvider({ children }: { children: ReactNode }): ReactElement {
    const [filters, setFilters] = useState<Record<string, string>>({});
    const setFilter = (field: string, value: string): void => setFilters((prev) => ({ ...prev, [field]: value }));

    return <ReportFilterContext.Provider value={{ filters, setFilter }}>{children}</ReportFilterContext.Provider>;
}

/** Group blocks into ordered pages by their `page` index (default 0), dropping empty pages. */
function groupByPage(blocks: Block[]): Block[][] {
    const maxPage = blocks.reduce((max, block) => Math.max(max, block.page ?? 0), 0);
    const pages: Block[][] = [];

    for (let index = 0; index <= maxPage; index += 1) {
        const pageBlocks = blocks.filter((block) => (block.page ?? 0) === index);
        if (pageBlocks.length > 0) {
            pages.push(pageBlocks);
        }
    }

    return pages.length > 0 ? pages : [[]];
}
