const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** "2026-10-01" → "octubre 2026". */
export function monthLabel(date: string): string {
    const [year, month] = date.split('-');

    return `${MONTHS[Number(month) - 1] ?? ''} ${year}`;
}
