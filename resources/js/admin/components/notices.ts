import type { AppNotice } from '../types';

/** «hace 5 min», «hace 3 h», «ayer», «12 nov». */
export function noticeAge(iso: string | null, now: number = Date.now()): string {
    if (iso === null) return '';
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '';
    const minutes = Math.floor((now - then) / 60_000);
    if (minutes < 1) return 'ahora';
    if (minutes < 60) return `hace ${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `hace ${hours} h`;
    if (hours < 48) return 'ayer';

    return new Date(then).toLocaleDateString('es', { day: 'numeric', month: 'short' });
}

/** The site (and connection to reconnect) a notice points at, from its "#/clients?site=…" link. */
export function noticeTarget(notice: AppNotice): { site: number | null; reconnect: number | null } {
    const query = notice.link?.split('?')[1] ?? '';
    const params = new URLSearchParams(query);
    const site = Number(params.get('site'));
    const reconnect = Number(params.get('reconnect'));

    return { site: site > 0 ? site : notice.site_id, reconnect: reconnect > 0 ? reconnect : null };
}
