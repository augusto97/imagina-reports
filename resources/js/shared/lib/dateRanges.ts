export interface DateRange {
    start: string; // YYYY-MM-DD
    end: string; // YYYY-MM-DD
}

export interface RangePreset {
    key: string;
    label: string;
    /** Compute the range relative to `today` (defaults to now). */
    range: (today?: Date) => DateRange;
}

/** Format a Date to a local YYYY-MM-DD (no timezone shift). */
function iso(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');

    return `${y}-${m}-${d}`;
}

/**
 * Practical reporting-range presets (CLAUDE.md §11.2 — client/agency period selection).
 * Each returns inclusive [start, end] days. Complete-period presets (last month/quarter/
 * year) end on the period's last day; the "this …" presets end today (period so far).
 */
export const RANGE_PRESETS: RangePreset[] = [
    {
        key: 'last_week',
        label: 'Semana pasada',
        range: (today = new Date()): DateRange => {
            // Previous Monday→Sunday (ISO week; Monday = 1).
            const day = (today.getDay() + 6) % 7; // 0 = Monday
            const thisMonday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - day);
            const lastMonday = new Date(thisMonday.getFullYear(), thisMonday.getMonth(), thisMonday.getDate() - 7);
            const lastSunday = new Date(lastMonday.getFullYear(), lastMonday.getMonth(), lastMonday.getDate() + 6);

            return { start: iso(lastMonday), end: iso(lastSunday) };
        },
    },
    {
        key: 'this_month',
        label: 'Este mes',
        range: (today = new Date()): DateRange => ({
            start: iso(new Date(today.getFullYear(), today.getMonth(), 1)),
            end: iso(today),
        }),
    },
    {
        key: 'last_month',
        label: 'Mes pasado',
        range: (today = new Date()): DateRange => ({
            start: iso(new Date(today.getFullYear(), today.getMonth() - 1, 1)),
            end: iso(new Date(today.getFullYear(), today.getMonth(), 0)),
        }),
    },
    {
        key: 'last_3_months',
        label: 'Últimos 3 meses',
        range: (today = new Date()): DateRange => ({
            start: iso(new Date(today.getFullYear(), today.getMonth() - 3, today.getDate())),
            end: iso(today),
        }),
    },
    {
        key: 'last_6_months',
        label: 'Últimos 6 meses',
        range: (today = new Date()): DateRange => ({
            start: iso(new Date(today.getFullYear(), today.getMonth() - 6, today.getDate())),
            end: iso(today),
        }),
    },
    {
        key: 'this_year',
        label: 'Este año',
        range: (today = new Date()): DateRange => ({
            start: iso(new Date(today.getFullYear(), 0, 1)),
            end: iso(today),
        }),
    },
    {
        key: 'last_year',
        label: 'Año pasado',
        range: (today = new Date()): DateRange => ({
            start: iso(new Date(today.getFullYear() - 1, 0, 1)),
            end: iso(new Date(today.getFullYear() - 1, 11, 31)),
        }),
    },
];
