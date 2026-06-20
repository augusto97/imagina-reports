import { useQuery } from '@tanstack/react-query';

import type { Block } from '../blocks/types';
import { api } from './api';
import { hexToHslString } from './color';

export interface PublicReportAgency {
    name: string;
    brand_color: string | null;
    logo_path: string | null;
    logo_url: string | null;
    locale: string;
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
}

export interface ReportPeriod {
    public_token: string;
    period_start: string;
    period_end: string;
}

export function usePublicReport(token: string) {
    return useQuery({
        queryKey: ['public-report', token],
        queryFn: async () => {
            const { data } = await api.get<PublicReport>(`/public/reports/${token}`);

            return data;
        },
    });
}

export function useReportPeriods(token: string) {
    return useQuery({
        queryKey: ['report-periods', token],
        queryFn: async () => {
            const { data } = await api.get<ReportPeriod[]>(`/public/reports/${token}/periods`);

            return data;
        },
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
