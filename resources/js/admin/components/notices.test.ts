import { describe, expect, it } from 'vitest';

import type { AppNotice } from '../types';
import { noticeAge, noticeTarget } from './notices';

const notice = (link: string | null, siteId: number | null = 3): AppNotice => ({
    id: 1,
    kind: 'source_failed',
    title: 'Google Analytics 4 dejó de funcionar en Clínica Sonrisa',
    body: null,
    site_id: siteId,
    data_source_id: 40,
    link,
    read: false,
    created_at: null,
});

describe('noticeTarget', () => {
    it('reads the site and the connection to reconnect from the link', () => {
        expect(noticeTarget(notice('#/clients?site=12&reconnect=40'))).toEqual({ site: 12, reconnect: 40 });
        expect(noticeTarget(notice('#/clients?site=12'))).toEqual({ site: 12, reconnect: null });
    });

    it('falls back to the notice site when there is no link', () => {
        expect(noticeTarget(notice(null, 7))).toEqual({ site: 7, reconnect: null });
    });
});

describe('noticeAge', () => {
    const now = new Date('2026-11-11T12:00:00Z').getTime();

    it('says how long ago in plain words', () => {
        expect(noticeAge('2026-11-11T11:59:40Z', now)).toBe('ahora');
        expect(noticeAge('2026-11-11T11:55:00Z', now)).toBe('hace 5 min');
        expect(noticeAge('2026-11-11T09:00:00Z', now)).toBe('hace 3 h');
        expect(noticeAge('2026-11-10T08:00:00Z', now)).toBe('ayer');
        expect(noticeAge(null, now)).toBe('');
    });
});
