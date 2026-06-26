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

import { matchCountry } from './geo';
import { GRID_COLS, GRID_MARGIN, GRID_ROW_HEIGHT } from './types';
import type { Block, BlockComponentProps, BlockLayout, BlockType } from './types';
import { WorldChoropleth } from './WorldChoropleth';

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
 * Branding + merge-field context for the cover / back-cover blocks: the agency name, logo
 * URL and the report's {{client}}/{{site}}/{{period}} tokens — so a cover renders a real
 * branded title page without each block re-plumbing the agency. Empty by default (editor
 * preview), where the cover falls back to sample text.
 */
interface ReportBrand {
    agencyName: string;
    logoUrl: string | null;
    context: Record<string, string>;
}

const ReportBrandContext = createContext<ReportBrand>({ agencyName: '', logoUrl: null, context: {} });

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
        case 'duration': {
            // A value in seconds rendered human-friendly: "30 s", "45 min", "1 h 30 min".
            const seconds = Math.max(0, Math.round(value));
            if (seconds < 60) {
                return `${seconds} s`;
            }
            const minutes = Math.round(seconds / 60);
            if (minutes < 60) {
                return `${minutes} min`;
            }
            const hours = Math.floor(minutes / 60);
            const restMinutes = minutes % 60;
            return restMinutes === 0 ? `${hours} h` : `${hours} h ${restMinutes} min`;
        }
        default:
            return value.toLocaleString(locale);
    }
}

/* -------------------------------- sections --------------------------------- */

function Section({ title, style: s, children }: { title?: string; style?: Style; children: React.ReactNode }): ReactElement {
    const settings = useContext(ReportSettingsContext);
    const pad = PAD[str(s?.pad)] ?? (settings.density === 'compact' ? 'ir-p-4' : 'ir-p-5');
    const radius = RADIUS[str(s?.radius)] ?? 'ir-rounded-lg';
    const align = ALIGN[str(s?.align)] ?? '';
    const border = s?.border === false ? '' : 'ir-border ir-shadow-ir-xs';
    const showTitle = title !== undefined && title !== '' && s?.hideTitle !== true;

    return (
        <section className={cn('ir-flex ir-h-full ir-flex-col ir-bg-card', pad, radius, align, border)} style={styleCss(s)}>
            {showTitle && (
                <h3 className="ir-mb-3 ir-text-[11px] ir-font-semibold ir-uppercase ir-tracking-wider ir-text-muted-foreground">{title}</h3>
            )}
            <div className="ir-min-h-0 ir-flex-1">{children}</div>
        </section>
    );
}

/* --------------------------------- blocks ---------------------------------- */

function HeaderBlock({ block, data }: BlockComponentProps): ReactElement {
    const record = data !== null && typeof data === 'object' ? (data as Record<string, unknown>) : undefined;
    const title = str(record?.title, str(prop(block, 'title'), 'Reporte'));
    // Eyebrow (e.g. agency) + subtitle (client · site · period) make the header read like a
    // real branded report cover rather than a bare title. Both resolve {{merge-fields}}.
    const eyebrow = str(record?.eyebrow, str(prop(block, 'eyebrow')));
    const subtitle = str(record?.subtitle, str(prop(block, 'subtitle')));

    return (
        <header className="ir-flex ir-flex-col ir-gap-1 ir-border-b-2 ir-border-primary/60 ir-pb-4" style={styleCss(block.style)}>
            {eyebrow !== '' && (
                <span className="ir-text-[11px] ir-font-semibold ir-uppercase ir-tracking-[0.18em] ir-text-primary">{eyebrow}</span>
            )}
            <h1 className="ir-text-3xl ir-font-bold ir-leading-tight ir-tracking-tight">{title}</h1>
            {subtitle !== '' && <p className="ir-text-sm ir-text-muted-foreground">{subtitle}</p>}
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

/** A compact trend pill: tinted green/red arrow + signed percent vs the previous period. */
function TrendBadge({ percent }: { percent: number }): ReactElement {
    const up = percent >= 0;
    const Arrow = up ? ArrowUpRight : ArrowDownRight;
    const tone = up ? 'ir-bg-success/10 ir-text-success' : 'ir-bg-danger/10 ir-text-danger';

    return (
        <span className={cn('ir-inline-flex ir-w-fit ir-items-center ir-gap-0.5 ir-rounded-full ir-px-1.5 ir-py-0.5 ir-text-xs ir-font-semibold ir-tabular-nums', tone)}>
            <Arrow className="ir-size-3" />
            {up ? '+' : ''}
            {percent.toLocaleString(undefined, { maximumFractionDigits: 1 })}%
        </span>
    );
}

function KpiBlock({ block, data }: BlockComponentProps): ReactElement {
    const { value, changePercent } = asKpi(data);
    const settings = useContext(ReportSettingsContext);

    return (
        <Section style={block.style}>
            <div className="ir-flex ir-h-full ir-flex-col ir-justify-center ir-gap-1.5">
                <p className="ir-text-[11px] ir-font-medium ir-uppercase ir-tracking-wider ir-text-muted-foreground">
                    {str(prop(block, 'label'))}
                </p>
                <p className="ir-text-[1.75rem] ir-font-bold ir-leading-none ir-tracking-tight ir-tabular-nums">
                    {formatNumber(value, str(block.style?.format), settings)}
                </p>
                {changePercent !== null && (
                    <div className="ir-flex ir-items-center ir-gap-1.5">
                        <TrendBadge percent={changePercent} />
                        <span className="ir-text-[11px] ir-text-muted-foreground">vs. anterior</span>
                    </div>
                )}
            </div>
        </Section>
    );
}

function ChartBlock({ block, data }: BlockComponentProps): ReactElement {
    const rows = useFilteredRows(asChartRows(data));
    const chartType = str(prop(block, 'chartType'), 'line');
    const legend = block.style?.legend === true;
    const accent = 'hsl(var(--ir-primary))';
    // When set, each bar is colored by a threshold (>= → green, below → red): the
    // status-page uptime bar. Values cluster near 100, so pin the axis to 0–100.
    const threshold = typeof block.style?.threshold === 'number' ? block.style.threshold : null;

    return (
        <Section title={str(prop(block, 'title'))} style={block.style}>
            <div className="ir-h-full ir-min-h-[12rem] ir-w-full">
                <ResponsiveContainer width="100%" height="100%">
                    {chartType === 'bar' ? (
                        <BarChart data={rows}>
                            <CartesianGrid strokeDasharray="3 3" />
                            <XAxis dataKey="name" />
                            <YAxis domain={threshold !== null ? [0, 100] : undefined} />
                            <Tooltip />
                            {legend && <Legend />}
                            <Bar dataKey="value" fill={accent} radius={[4, 4, 0, 0]}>
                                {threshold !== null &&
                                    rows.map((row, index) => (
                                        <Cell key={index} fill={row.value >= threshold ? '#16a34a' : '#dc2626'} />
                                    ))}
                            </Bar>
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
    // Interactive sort/search/paginate for the portal. A predefined order (set in the editor
    // via style.sort_col/sort_dir) is the DEFAULT — so the PDF and first view come pre-sorted;
    // a visitor clicking a header overrides it for their session (userSort). The PDF prints
    // fresh with no interaction → the predefined order, no search, and ALL rows (paginated
    // rows stay in the DOM, only hidden on screen — see `print:ir-table-row` below).
    const predefinedSort = useMemo<{ col: string; dir: 1 | -1 } | null>(() => {
        const col = str(block.style?.sort_col);

        return col === '' ? null : { col, dir: block.style?.sort_dir === 'asc' ? 1 : -1 };
    }, [block.style?.sort_col, block.style?.sort_dir]);
    const [userSort, setUserSort] = useState<{ col: string; dir: 1 | -1 } | null>(null);
    const sort = userSort ?? predefinedSort;
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(0);

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

    const columns = Object.keys(rows[0] ?? {});

    const q = query.trim().toLowerCase();
    const filtered = useMemo(
        () => (q === '' ? sorted : sorted.filter((row) => columns.some((col) => String(row[col] ?? '').toLowerCase().includes(q)))),
        [sorted, q, columns],
    );

    if (rows.length === 0) {
        return null;
    }

    const numeric = new Set(columns.filter((col) => rows.every((row) => row[col] === undefined || typeof row[col] === 'number' || (row[col] !== '' && !Number.isNaN(Number(row[col]))))));
    const bars = block.style?.bars === true;
    const max = bars ? Math.max(0, ...rows.map((row) => Number(row.value) || 0)) : 0;
    const toggleSort = (col: string): void =>
        setUserSort((current) => {
            const active = (current ?? predefinedSort)?.col === col ? (current ?? predefinedSort) : null;

            return active !== null ? { col, dir: active.dir === 1 ? -1 : 1 } : { col, dir: -1 };
        });

    const pageSizeRaw = Number(block.style?.rows_per_page);
    const pageSize = pageSizeRaw > 0 ? pageSizeRaw : 10;
    // Only show the toolbar (search + pager) once the table is big enough to need it.
    const interactive = rows.length > pageSize;
    const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
    const safePage = Math.min(Math.max(page, 0), pageCount - 1);
    const start = safePage * pageSize;
    const end = start + pageSize;

    return (
        <Section title={str(prop(block, 'title'))} style={block.style}>
            {interactive && (
                <div className="ir-mb-2 ir-flex ir-justify-end print:ir-hidden">
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setPage(0);
                        }}
                        placeholder="Buscar…"
                        className="ir-h-8 ir-w-40 ir-rounded-md ir-border ir-bg-background ir-px-2.5 ir-text-sm ir-outline-none focus:ir-border-accent"
                    />
                </div>
            )}
            <table className="ir-w-full ir-text-left ir-text-sm">
                <thead>
                    <tr className="ir-border-b ir-text-[11px] ir-uppercase ir-tracking-wide ir-text-muted-foreground">
                        {columns.map((col) => (
                            <th key={col} className={cn('ir-pb-2 ir-font-semibold', numeric.has(col) && 'ir-text-right')}>
                                <button
                                    type="button"
                                    className="ir-inline-flex ir-items-center ir-gap-1 hover:ir-text-foreground"
                                    onClick={() => toggleSort(col)}
                                >
                                    {col}
                                    {sort?.col === col && <span className="ir-text-[10px]">{sort.dir === 1 ? '▲' : '▼'}</span>}
                                </button>
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {filtered.map((row, index) => (
                        <tr
                            key={index}
                            className={cn(
                                'ir-border-b ir-border-border/50 last:ir-border-0',
                                // Off-page rows stay in the DOM (so the PDF prints them all) but hide on screen.
                                interactive && (index < start || index >= end) && 'ir-hidden print:ir-table-row',
                            )}
                        >
                            {columns.map((col) => (
                                <td key={col} className={cn('ir-py-2', numeric.has(col) && 'ir-text-right ir-tabular-nums')}>
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
                    {filtered.length === 0 && (
                        <tr>
                            <td colSpan={columns.length} className="ir-py-3 ir-text-center ir-text-xs ir-text-muted-foreground">
                                Sin resultados.
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>

            {interactive && pageCount > 1 && (
                <div className="ir-mt-2 ir-flex ir-items-center ir-justify-between ir-text-xs ir-text-muted-foreground print:ir-hidden">
                    <span>
                        {filtered.length === 0 ? 0 : start + 1}–{Math.min(end, filtered.length)} de {filtered.length}
                    </span>
                    <span className="ir-flex ir-items-center ir-gap-1">
                        <button
                            type="button"
                            onClick={() => setPage(safePage - 1)}
                            disabled={safePage === 0}
                            className="ir-rounded-md ir-border ir-px-2 ir-py-1 disabled:ir-opacity-40 hover:ir-bg-muted"
                        >
                            ‹
                        </button>
                        <span className="ir-tabular-nums">
                            {safePage + 1} / {pageCount}
                        </span>
                        <button
                            type="button"
                            onClick={() => setPage(safePage + 1)}
                            disabled={safePage >= pageCount - 1}
                            className="ir-rounded-md ir-border ir-px-2 ir-py-1 disabled:ir-opacity-40 hover:ir-bg-muted"
                        >
                            ›
                        </button>
                    </span>
                </div>
            )}
        </Section>
    );
}

/**
 * Sales/conversion funnel (CLAUDE.md §10 — Etapa C). Renders ordered `[{label, value}]`
 * stages as a centered narrowing funnel with each stage's share of the top and the
 * step-to-step drop-off. Data shape is the same the datasets/tables already produce, so
 * the agency just binds an ordered breakdown (e.g. sessions → add-to-cart → purchase).
 */
function FunnelBlock({ block, data }: BlockComponentProps): ReactElement | null {
    const rows = useFilteredRows(asRecords(data));
    const stages = rows
        .map((row) => ({ label: String(row.label ?? row.name ?? ''), value: Number(row.value) || 0 }))
        .filter((stage) => stage.label !== '');

    if (stages.length === 0) {
        return null;
    }

    const top = stages[0]?.value ?? 0;
    const max = Math.max(0, ...stages.map((stage) => stage.value));

    return (
        <Section title={str(prop(block, 'title'))} style={block.style}>
            <div className="ir-flex ir-flex-col ir-gap-2.5">
                {stages.map((stage, index) => {
                    const shareOfTop = top > 0 ? (stage.value / top) * 100 : 0;
                    const widthPct = max > 0 ? Math.max(8, (stage.value / max) * 100) : 0;
                    const prev = index > 0 ? stages[index - 1]?.value ?? 0 : null;
                    const drop = prev !== null && prev > 0 ? ((prev - stage.value) / prev) * 100 : null;

                    return (
                        <div key={index}>
                            <div className="ir-mb-1 ir-flex ir-items-baseline ir-justify-between ir-text-xs">
                                <span className="ir-font-medium">{stage.label}</span>
                                <span className="ir-tabular-nums ir-text-muted-foreground">
                                    {stage.value.toLocaleString()} · {shareOfTop.toFixed(0)}%
                                </span>
                            </div>
                            <div className="ir-flex ir-justify-center">
                                <div
                                    className="ir-flex ir-h-7 ir-items-center ir-justify-center ir-rounded ir-bg-primary/80 ir-text-[11px] ir-font-medium ir-text-primary-foreground"
                                    style={{ width: `${widthPct}%` }}
                                >
                                    {shareOfTop.toFixed(0)}%
                                </div>
                            </div>
                            {drop !== null && drop > 0.5 && (
                                <p className="ir-mt-0.5 ir-text-center ir-text-[10px] ir-text-muted-foreground">↓ {drop.toFixed(0)}% de caída</p>
                            )}
                        </div>
                    );
                })}
            </div>
        </Section>
    );
}

/**
 * Geographic breakdown (CLAUDE.md §10 — Etapa C). A ranked regions/cities list with a
 * proportional bar and each row's share of the total — the honest, dependency-free "geo"
 * view over the same `[{label, value}]` the geo datasets produce (country/region/city).
 */
function GeoMapBlock({ block, data }: BlockComponentProps): ReactElement | null {
    const rows = useFilteredRows(asRecords(data))
        .map((row) => ({ label: String(row.label ?? row.name ?? ''), value: Number(row.value) || 0 }))
        .filter((row) => row.label !== '');

    if (rows.length === 0) {
        return null;
    }

    const total = rows.reduce((sum, row) => sum + row.value, 0);
    const max = Math.max(0, ...rows.map((row) => row.value));

    // Choropleth when the data is country-level: show the map if a majority of rows match a
    // country (otherwise it's cities/regions and the ranked list alone is the honest view).
    // `display` lets the editor force 'map' / 'list' / 'both' (default: auto = both).
    const display = str(prop(block, 'display'), 'auto');
    const matched = rows.filter((row) => matchCountry(row.label) !== null).length;
    const showMap = display !== 'list' && matched > 0 && (display === 'map' || display === 'both' || matched >= Math.ceil(rows.length / 2));
    const showList = display !== 'map';

    return (
        <Section title={str(prop(block, 'title'))} style={block.style}>
            {showMap && (
                <div className="ir-mb-4">
                    <WorldChoropleth rows={rows} />
                </div>
            )}
            {showList && (
            <div className="ir-flex ir-flex-col ir-gap-2">
                {rows.map((row, index) => (
                    <div key={index} className="ir-flex ir-items-center ir-gap-3 ir-text-sm">
                        <span className="ir-w-5 ir-shrink-0 ir-text-right ir-text-xs ir-tabular-nums ir-text-muted-foreground">{index + 1}</span>
                        <span className="ir-w-32 ir-shrink-0 ir-truncate ir-font-medium">{row.label}</span>
                        <div className="ir-h-2 ir-flex-1 ir-overflow-hidden ir-rounded ir-bg-muted">
                            <div className="ir-h-full ir-rounded ir-bg-accent" style={{ width: `${max > 0 ? (row.value / max) * 100 : 0}%` }} />
                        </div>
                        <span className="ir-w-24 ir-shrink-0 ir-text-right ir-tabular-nums">
                            {row.value.toLocaleString()}
                            <span className="ir-ml-1 ir-text-xs ir-text-muted-foreground">{total > 0 ? `${((row.value / total) * 100).toFixed(0)}%` : ''}</span>
                        </span>
                    </div>
                ))}
            </div>
            )}
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
                        {typeof entry.screenshot_url === 'string' && entry.screenshot_url !== '' && (
                            <img src={entry.screenshot_url} alt="" className="ir-size-9 ir-shrink-0 ir-self-center ir-rounded ir-border ir-object-cover" />
                        )}
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
 * active and protecting your site"). Accent-tinted by default, with an optional button.
 * Honours the inspector's style controls (background, text colour, corner radius, border,
 * alignment, padding) like every other block, falling back to a polished accent design.
 */
function CtaBlock({ block }: BlockComponentProps): ReactElement {
    const s = block.style;
    const headline = str(prop(block, 'headline'), 'Tu plan de soporte está activo y protegiendo tu sitio.');
    const text = str(prop(block, 'text'));
    const buttonLabel = str(prop(block, 'buttonLabel'));
    const buttonUrl = str(prop(block, 'buttonUrl'), '#');

    const hasBg = typeof s?.bg === 'string' && s.bg !== '';
    const hasColor = typeof s?.color === 'string' && s.color !== '';
    const pad = PAD[str(s?.pad)] ?? 'ir-p-8';
    const radius = RADIUS[str(s?.radius)] ?? 'ir-rounded-2xl';
    const align = ALIGN[str(s?.align)] ?? 'ir-text-center';
    const border = s?.border === false ? '' : 'ir-border ir-border-primary/30';

    return (
        <div
            className={cn(
                'ir-flex ir-h-full ir-flex-col ir-justify-center ir-gap-2',
                pad,
                radius,
                border,
                hasBg ? '' : 'ir-bg-primary/[0.06]',
            )}
            style={styleCss(s)}
        >
            <div className={align}>
                <p className={cn('ir-text-xl ir-font-bold ir-leading-snug', hasColor ? '' : 'ir-text-primary')}>{headline}</p>
                {text !== '' && <p className={cn('ir-mt-1.5 ir-text-sm', hasColor ? 'ir-opacity-80' : 'ir-text-muted-foreground')}>{text}</p>}
                {buttonLabel !== '' && (
                    <a
                        href={buttonUrl}
                        className="ir-mt-4 ir-inline-flex ir-items-center ir-rounded-lg ir-bg-primary ir-px-5 ir-py-2.5 ir-text-sm ir-font-semibold ir-text-primary-foreground ir-shadow-sm ir-transition hover:ir-opacity-90"
                    >
                        {buttonLabel}
                    </a>
                )}
            </div>
        </div>
    );
}

/**
 * Cover page (CLAUDE.md §11.5 #1 — the branded title page). A full-height, accent-tinted
 * panel with the agency logo, report title, client · site, period and an optional health
 * score — the "portada" Looker/Power-BI users expect as the first PDF sheet. Reads the
 * agency/merge context so it self-populates; falls back to sample text in the editor.
 */
function CoverBlock({ block, data }: BlockComponentProps): ReactElement {
    const brand = useContext(ReportBrandContext);
    const ctx = brand.context;
    const record = data !== null && typeof data === 'object' ? (data as Record<string, unknown>) : undefined;

    const title = str(prop(block, 'title'), 'Informe de soporte y rendimiento');
    const subtitle = str(prop(block, 'subtitle'));
    const client = ctx.client ?? '';
    const site = ctx.site ?? '';
    const period = ctx.period ?? str(record?.period);
    const showScore = prop(block, 'showScore') !== false;
    const score = ctx.score !== undefined && ctx.score !== '' ? ctx.score : str(record?.score);
    const logo = brand.logoUrl;
    const subline = [client, site].filter((part) => part !== '').join(' · ');

    return (
        <div
            className={cn(
                'ir-flex ir-h-full ir-min-h-[60vh] ir-flex-col ir-justify-between ir-gap-8 ir-rounded-2xl ir-p-10',
                typeof block.style?.bg === 'string' && block.style.bg !== '' ? '' : 'ir-bg-primary/[0.06]',
            )}
            style={styleCss(block.style)}
        >
            <div className="ir-flex ir-items-center ir-justify-between">
                {logo !== null ? (
                    <img src={logo} alt={brand.agencyName} className="ir-h-10" />
                ) : (
                    <span className="ir-text-sm ir-font-semibold ir-uppercase ir-tracking-[0.18em] ir-text-primary">{brand.agencyName || 'Tu Agencia'}</span>
                )}
                {period !== '' && <span className="ir-text-xs ir-text-muted-foreground">{period}</span>}
            </div>

            <div className="ir-flex ir-flex-col ir-gap-3">
                <h1 className="ir-text-4xl ir-font-bold ir-leading-tight ir-tracking-tight">{title}</h1>
                {subtitle !== '' && <p className="ir-max-w-xl ir-text-base ir-text-muted-foreground">{subtitle}</p>}
                {subline !== '' && <p className="ir-text-lg ir-font-medium ir-text-foreground/80">{subline}</p>}
            </div>

            {showScore && score !== '' && (
                <div className="ir-flex ir-items-end ir-justify-between">
                    <div className="ir-w-44">
                        <Gauge score={Number(score) || 0} />
                        <p className="ir-mt-1 ir-text-center ir-text-xs ir-uppercase ir-tracking-wide ir-text-muted-foreground">Estado general</p>
                    </div>
                </div>
            )}
        </div>
    );
}

/**
 * Back cover (CLAUDE.md §11.5 #9 — the closing page). A full-height reassurance panel with
 * the retention message and the agency's contact line — the "contraportada" that closes the
 * PDF. Like the cover, it self-populates from the brand context.
 */
function BackCoverBlock({ block }: BlockComponentProps): ReactElement {
    const brand = useContext(ReportBrandContext);
    const headline = str(prop(block, 'headline'), 'Tu plan de soporte está activo y protegiendo tu sitio.');
    const text = str(prop(block, 'text'));
    const contact = str(prop(block, 'contact'));
    const logo = brand.logoUrl;

    return (
        <div
            className={cn(
                'ir-flex ir-h-full ir-min-h-[60vh] ir-flex-col ir-items-center ir-justify-center ir-gap-6 ir-rounded-2xl ir-p-10 ir-text-center',
                typeof block.style?.bg === 'string' && block.style.bg !== '' ? '' : 'ir-bg-primary/[0.06]',
            )}
            style={styleCss(block.style)}
        >
            {logo !== null && <img src={logo} alt={brand.agencyName} className="ir-h-10" />}
            <p className="ir-max-w-2xl ir-text-2xl ir-font-bold ir-leading-snug ir-text-primary">{headline}</p>
            {text !== '' && <p className="ir-max-w-xl ir-text-sm ir-text-muted-foreground">{text}</p>}
            {(contact !== '' || brand.agencyName !== '') && (
                <p className="ir-mt-2 ir-text-sm ir-font-medium ir-text-foreground/70">{contact !== '' ? contact : brand.agencyName}</p>
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
    cover: CoverBlock,
    back_cover: BackCoverBlock,
    header: HeaderBlock,
    kpi: KpiBlock,
    chart: ChartBlock,
    table: TableBlock,
    funnel: FunnelBlock,
    geo_map: GeoMapBlock,
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
    mode = 'paged',
    pages: pageMeta,
    agency,
}: {
    blocks: Block[];
    data?: Record<string, unknown>;
    context?: Record<string, string>;
    currency?: string;
    locale?: string;
    theme?: { accent?: string | null; density?: 'normal' | 'compact' | null } | null;
    /**
     * How the multi-page report is laid out (CLAUDE.md §11 — Looker/Power-BI parity):
     * - `paged` (default): interactive — ONE page at a time with a navigation menu, the way
     *   Looker Studio / Power BI present report pages on screen.
     * - `print`: every page stacked, each its own physical sheet — what Browsershot prints to PDF.
     * - `flow`: just the first page's blocks, no chrome — for tiny inline previews.
     */
    mode?: 'paged' | 'print' | 'flow';
    /** Per-page metadata (names) for the navigation menu, indexed by page. */
    pages?: { name?: string }[];
    /** Agency branding for the cover / back-cover blocks. */
    agency?: { name?: string | null; logo_url?: string | null } | null;
}): ReactElement {
    const resolved = context === undefined ? blocks : blocks.map((block) => applyContext(block, context));
    const periodLabel = context?.period ?? '';

    // Dashboard grid mode: when every block carries grid coordinates, place them on a
    // 12-column CSS grid identical to the editor canvas (CLAUDE.md §11.3/§11.4). Older
    // templates without coordinates keep the legacy width-flow so they still render.
    const useGrid = resolved.length > 0 && resolved.every((block) => block.layout != null);

    // Multi-page: group blocks by their page index.
    const pages = groupByPage(resolved);

    // Interactive paging: which page the viewer is looking at (Looker/Power-BI tabs).
    const [active, setActive] = useState(0);
    const safeActive = Math.min(Math.max(active, 0), pages.length - 1);
    const pageName = (index: number): string => {
        const name = pageMeta?.[index]?.name;

        return typeof name === 'string' && name.trim() !== '' ? name : `Página ${index + 1}`;
    };

    // Per-report theme: scope the accent (CSS var, overriding the agency brand) and density.
    const density = theme?.density === 'compact' ? 'compact' : 'normal';
    const accentHsl = typeof theme?.accent === 'string' ? hexToHslString(theme.accent) : null;
    const themeStyle: CSSProperties = accentHsl !== null ? ({ '--ir-primary': accentHsl, '--ir-ring': accentHsl } as CSSProperties) : {};

    const brand: ReportBrand = {
        agencyName: agency?.name ?? '',
        logoUrl: agency?.logo_url ?? null,
        context: context ?? {},
    };

    const renderPage = (pageBlocks: Block[]): ReactElement =>
        useGrid ? (
            <div
                className="ir-grid ir-report-grid"
                style={{
                    gridTemplateColumns: `repeat(${GRID_COLS}, 1fr)`,
                    gridAutoRows: `${GRID_ROW_HEIGHT}px`,
                    gap: `${GRID_MARGIN}px`,
                }}
            >
                {pageBlocks.map((block) => (
                    <div key={block.id} style={gridCellStyle(block.layout as BlockLayout)} className="ir-report-cell ir-overflow-hidden">
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
        );

    let body: ReactNode;
    if (mode === 'print') {
        // PDF: every page on its own physical sheet (the @page margins own the whitespace).
        body = (
            <div className="ir-sheets">
                {pages.map((pageBlocks, pageIndex) => (
                    <section key={pageIndex} className="ir-sheet">
                        {renderPage(pageBlocks)}
                        <footer className="ir-sheet-footer">
                            <span>{periodLabel}</span>
                            <span>
                                {pageName(pageIndex)} · {pageIndex + 1}/{pages.length}
                            </span>
                        </footer>
                    </section>
                ))}
            </div>
        );
    } else if (mode === 'flow') {
        body = renderPage(pages[0] ?? []);
    } else {
        // Interactive: a navigation menu + the active page only (Looker/Power-BI model).
        body = (
            <div className="ir-flex ir-flex-col ir-gap-4">
                {pages.length > 1 && (
                    <nav className="ir-flex ir-flex-wrap ir-gap-1 ir-border-b ir-pb-2" aria-label="Páginas del informe">
                        {pages.map((_, index) => (
                            <button
                                key={index}
                                type="button"
                                onClick={() => setActive(index)}
                                aria-current={index === safeActive}
                                className={cn(
                                    'ir-rounded-md ir-px-3 ir-py-1.5 ir-text-sm ir-font-medium ir-transition',
                                    index === safeActive
                                        ? 'ir-bg-primary/10 ir-text-primary'
                                        : 'ir-text-muted-foreground hover:ir-bg-muted',
                                )}
                            >
                                {pageName(index)}
                            </button>
                        ))}
                    </nav>
                )}
                {renderPage(pages[safeActive] ?? [])}
            </div>
        );
    }

    return (
        <div style={themeStyle}>
            <ReportBrandContext.Provider value={brand}>
                <ReportSettingsProvider currency={currency} locale={locale} density={density}>
                    <ReportFilterProvider>{body}</ReportFilterProvider>
                </ReportSettingsProvider>
            </ReportBrandContext.Provider>
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
