/**
 * Catalog unit → number format (mirrors App\Reports\MetricUnits::FORMATS). The server fills a
 * block's format from its metric's unit when nobody picked one; the editor does the same so the
 * canvas matches the report.
 */
export const UNIT_FORMATS: Record<string, string> = {
    percent: 'percent',
    currency: 'currency',
    duration: 'duration',
    seconds: 'seconds',
    ms: 'ms',
    days: 'days',
    bytes: 'bytes',
    MB: 'mb',
    position: 'position',
    ratio: 'ratio',
    bool: 'bool',
};

/** The format a unit implies, or undefined when it reads fine as a plain number. */
export function formatForUnit(unit: string | null | undefined): string | undefined {
    return unit !== null && unit !== undefined ? UNIT_FORMATS[unit] : undefined;
}

/** «1,2 GB» from bytes (binary steps, the way hosting panels count). */
export function formatBytes(bytes: number, locale: string): string {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = Math.abs(bytes);
    let index = 0;
    while (value >= 1024 && index < units.length - 1) {
        value /= 1024;
        index++;
    }

    return `${(bytes < 0 ? -value : value).toLocaleString(locale, { maximumFractionDigits: index === 0 ? 0 : 1 })} ${units[index]}`;
}
