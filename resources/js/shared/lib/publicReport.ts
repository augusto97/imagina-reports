import { useQuery } from '@tanstack/react-query';
import axios from 'axios';

import type { Block, ReportNav } from '../blocks/types';
import { api } from './api';
import { hexToHslString } from './color';

/** True when the report is password-protected and the supplied password was missing/wrong (Etapa D). */
export function isPasswordRequired(error: unknown): boolean {
    return (
        axios.isAxiosError(error) &&
        error.response?.status === 401 &&
        (error.response.data as { requires_password?: boolean } | undefined)?.requires_password === true
    );
}

/** True when the report is private and not reachable via its public token (Etapa D). */
export function isPrivate(error: unknown): boolean {
    return axios.isAxiosError(error) && error.response?.status === 403;
}

export interface PublicReportAgency {
    name: string;
    brand_color: string | null;
    logo_path: string | null;
    logo_url: string | null;
    locale: string;
    /** How the client reaches the agency (only the filled fields). */
    contact?: { email?: string; phone?: string; website?: string };
}

export type ReportVisualStyle = 'modern' | 'executive' | 'minimal' | 'bold';

export interface ReportTheme {
    accent?: string | null;
    density?: 'normal' | 'compact' | null;
    /** Visual style of the whole report (cards, titles, page) — see app.css. */
    style?: ReportVisualStyle | null;
    nav?: ReportNav | null;
}

export interface PublicReport {
    period_start: string;
    period_end: string;
    health_score: number | null;
    status: string;
    blocks: Block[];
    data: Record<string, unknown>;
    agency: PublicReportAgency | null;
    context?: Record<string, string>;
    currency?: string;
    theme?: ReportTheme | null;
    /** Named pages for the interactive navigation menu (§11 — Looker/Power-BI parity). */
    pages?: { name?: string }[];
    /** "Powered by" attribution shown unless the agency's plan removes branding. */
    show_branding?: boolean;
    /** The period as a client says it: "octubre 2026". */
    period_label?: string;
    site?: { name: string; url: string } | null;
    client?: string | null;
}

export interface ReportPeriod {
    public_token: string;
    period_start: string;
    period_end: string;
}

export interface PublicDashboard extends PublicReport {
    // The full span of available snapshots, so the date picker can't wander past data.
    range: { start: string; end: string } | null;
    // The exact windows that have data — the client picks one of these (a snapshot can't be
    // re-sliced to an arbitrary sub-range, so only whole periods are selectable).
    periods: { start: string; end: string; label: string }[];
}

export function usePublicDashboard(token: string, options?: { from?: string; to?: string; password?: string }) {
    return useQuery({
        queryKey: ['public-dashboard', token, options?.from ?? '', options?.to ?? '', options?.password ?? ''],
        queryFn: async () => {
            const headers: Record<string, string> = {};
            if (options?.password != null && options.password !== '') headers['X-Report-Password'] = options.password;

            const params: Record<string, string> = {};
            if (options?.from != null && options.from !== '') params.from = options.from;
            if (options?.to != null && options.to !== '') params.to = options.to;

            const { data } = await api.get<PublicDashboard>(`/public/dashboards/${token}`, { headers, params });

            return data;
        },
        retry: false,
        // Keep the previous page on screen while a new date range loads (no flash).
        placeholderData: (previous) => previous,
    });
}

export function usePublicReport(token: string, options?: { printToken?: string; password?: string }) {
    return useQuery({
        queryKey: ['public-report', token, options?.password ?? ''],
        queryFn: async () => {
            const headers: Record<string, string> = {};
            // The PDF renderer carries the server-only print token (bypasses the gate);
            // the portal sends the password the visitor typed (Etapa D).
            if (options?.printToken != null && options.printToken !== '') headers['X-Print-Token'] = options.printToken;
            if (options?.password != null && options.password !== '') headers['X-Report-Password'] = options.password;

            const { data } = await api.get<PublicReport>(`/public/reports/${token}`, { headers });

            return data;
        },
        retry: false,
    });
}

export function useReportPeriods(token: string, options?: { password?: string }) {
    return useQuery({
        queryKey: ['report-periods', token, options?.password ?? ''],
        queryFn: async () => {
            const headers: Record<string, string> = {};
            if (options?.password != null && options.password !== '') headers['X-Report-Password'] = options.password;

            const { data } = await api.get<ReportPeriod[]>(`/public/reports/${token}/periods`, { headers });

            return data;
        },
        retry: false,
    });
}

/** White-label: apply an agency's brand colour as the accent (CLAUDE.md §11.5). */
export function applyBrandAccent(brand: string | null | undefined): void {
    if (typeof brand !== 'string') {
        return;
    }

    const hsl = hexToHslString(brand);
    if (hsl !== null) {
        document.documentElement.style.setProperty('--ir-primary', hsl);
        document.documentElement.style.setProperty('--ir-ring', hsl);
    }
}

/** "octubre 2026" for a calendar month; "1–15 oct 2026" otherwise (mirrors the server's PeriodLabel). */
export function periodLabel(start: string, end: string, locale = 'es'): string {
    const from = new Date(start.slice(0, 10) + 'T00:00:00');
    const to = new Date(end.slice(0, 10) + 'T00:00:00');
    const lastDay = new Date(from.getFullYear(), from.getMonth() + 1, 0).getDate();
    if (from.getDate() === 1 && to.getFullYear() === from.getFullYear() && to.getMonth() === from.getMonth() && to.getDate() === lastDay) {
        return from.toLocaleDateString(locale, { month: 'long', year: 'numeric' }).replace(' de ', ' ');
    }
    const short = (date: Date): string => date.toLocaleDateString(locale, { day: 'numeric', month: 'short' }).replace('.', '');

    return `${short(from)} – ${short(to)} ${to.getFullYear()}`;
}

/** Download the report's PDF (sends the password header when the report is protected). */
export async function downloadReportPdf(token: string, password?: string): Promise<void> {
    const headers: Record<string, string> = {};
    if (password != null && password !== '') headers['X-Report-Password'] = password;

    const response = await api.get<Blob>(`/public/reports/${token}/pdf`, { headers, responseType: 'blob' });
    const disposition = String(response.headers['content-disposition'] ?? '');
    const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? 'reporte.pdf';
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
