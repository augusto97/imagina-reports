import type { CoverageGap } from '../api';

/** Compact month span from two ISO dates, e.g. "ene 2026 → jun 2026" (or one month). */
export function coverageSpan(from: string | null, to: string | null): string | null {
    if (from === null || to === null) {
        return null;
    }
    const fmt = (iso: string): string => new Date(iso).toLocaleDateString('es', { month: 'short', year: 'numeric' });
    const a = fmt(from);
    const b = fmt(to);

    return a === b ? a : `${a} → ${b}`;
}

/** Humanize a byte count: 0 B / 12 KB / 3.4 MB. */
export function humanBytes(bytes: number): string {
    if (bytes <= 0) {
        return '0 B';
    }
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    const kb = bytes / 1024;

    return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`;
}

const lastDayOfMonth = (d: Date): number => new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();

/**
 * Human label for a coverage gap. A whole-month hole reads as the month name ("may 2026");
 * a partial hole reads as a day range ("05/05 → 20/05/2026").
 */
export function formatGap(gap: CoverageGap): string {
    const start = new Date(gap.start);
    const end = new Date(gap.end);

    const wholeMonth =
        start.getFullYear() === end.getFullYear() &&
        start.getMonth() === end.getMonth() &&
        start.getDate() === 1 &&
        end.getDate() === lastDayOfMonth(end);

    if (wholeMonth) {
        return start.toLocaleDateString('es', { month: 'short', year: 'numeric' });
    }

    const day = (d: Date, withYear = false): string =>
        d.toLocaleDateString('es', withYear ? { day: '2-digit', month: '2-digit', year: 'numeric' } : { day: '2-digit', month: '2-digit' });

    return `${day(start)} → ${day(end, true)}`;
}

/** Join up to `max` gap labels, summarizing the rest as "+N más". */
export function formatGaps(gaps: CoverageGap[], max = 3): string {
    const labels = gaps.map(formatGap);
    if (labels.length <= max) {
        return labels.join(', ');
    }

    return `${labels.slice(0, max).join(', ')} +${labels.length - max} más`;
}
