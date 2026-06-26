import { useQuery } from '@tanstack/react-query';
import axios from 'axios';

import type { Block } from '../blocks/types';
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
}

export interface ReportTheme {
    accent?: string | null;
    density?: 'normal' | 'compact' | null;
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
}

export interface ReportPeriod {
    public_token: string;
    period_start: string;
    period_end: string;
}

export interface PublicDashboard extends PublicReport {
    // The full span of available snapshots, so the date picker can't wander past data.
    range: { start: string; end: string } | null;
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
