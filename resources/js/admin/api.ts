import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@shared/lib/api';

import type { Block } from '@shared/blocks/types';

import type {
    AgencyTrends,
    CatalogEntry,
    Client,
    Connector,
    DataSourceDto,
    ReportDefinitionDto,
    ReportSummary,
    ReportTemplateDto,
    Site,
} from './types';

async function get<T>(url: string): Promise<T> {
    const { data } = await api.get<T>(url);

    return data;
}

/* --------------------------------- clients --------------------------------- */

export function useClients() {
    return useQuery({ queryKey: ['clients'], queryFn: () => get<Client[]>('/clients') });
}

export function useCreateClient() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { name: string; contact_email?: string }) =>
            api.post<Client>('/clients', payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['clients'] }),
    });
}

/* ---------------------------------- sites ---------------------------------- */

export function useSites() {
    return useQuery({ queryKey: ['sites'], queryFn: () => get<Site[]>('/sites') });
}

export function useCreateSite() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { client_id: number; name: string; url: string }) =>
            api.post<Site>('/sites', payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['sites'] }),
    });
}

/* -------------------------------- connectors ------------------------------- */

export function useConnectors() {
    return useQuery({ queryKey: ['connectors'], queryFn: () => get<Connector[]>('/connectors') });
}

/* ------------------------------- data sources ------------------------------ */

export function useSiteDataSources(siteId: number | null) {
    return useQuery({
        queryKey: ['data-sources', siteId],
        queryFn: () => get<DataSourceDto[]>(`/sites/${siteId}/data-sources`),
        enabled: siteId !== null,
    });
}

export function useCreateDataSource(siteId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { type: string; config: Record<string, string>; credentials: Record<string, string> }) =>
            api.post<DataSourceDto>(`/sites/${siteId}/data-sources`, payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['data-sources', siteId] }),
    });
}

export interface ConnectionTestResult {
    successful: boolean;
    message: string;
}

export function useTestConnection() {
    return useMutation({
        mutationFn: (dataSourceId: number) =>
            api.post<ConnectionTestResult>(`/data-sources/${dataSourceId}/test`).then((r) => r.data),
    });
}

/* ---------------------------- report definitions --------------------------- */

export function useReportDefinitions() {
    return useQuery({ queryKey: ['report-definitions'], queryFn: () => get<ReportDefinitionDto[]>('/report-definitions') });
}

export function useCreateReportDefinition() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { site_id: number; name: string }) =>
            api.post<ReportDefinitionDto>('/report-definitions', payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['report-definitions'] }),
    });
}

/* --------------------------------- reports --------------------------------- */

export function useReports() {
    return useQuery({ queryKey: ['reports'], queryFn: () => get<ReportSummary[]>('/reports') });
}

/* --------------------------------- trends ---------------------------------- */

export function useTrends() {
    return useQuery({ queryKey: ['trends'], queryFn: () => get<AgencyTrends>('/trends') });
}

/* ------------------------------ editor: catalog ---------------------------- */

export function useMetricCatalog(siteId: number | null) {
    return useQuery({
        queryKey: ['metric-catalog', siteId],
        queryFn: () => get<CatalogEntry[]>(`/sites/${siteId}/metric-catalog`),
        enabled: siteId !== null,
    });
}

/* ----------------------------- editor: templates --------------------------- */

export function useReportTemplates() {
    return useQuery({ queryKey: ['report-templates'], queryFn: () => get<ReportTemplateDto[]>('/report-templates') });
}

export interface TemplateValidationErrors {
    blocks?: string[];
}

export function useCreateReportTemplate() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { name: string; blocks: Block[] }) =>
            api.post<ReportTemplateDto>('/report-templates', payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['report-templates'] }),
    });
}

export interface AiTemplateResult {
    blocks: Block[];
    narrative: string;
}

export function useAiTemplate(siteId: number) {
    return useMutation({
        mutationFn: (prompt: string) =>
            api.post<AiTemplateResult>(`/sites/${siteId}/ai-template`, { prompt }).then((r) => r.data),
    });
}

export function useGenerateReport() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { report_definition_id: number; period_start: string; period_end: string }) =>
            api.post('/reports/generate', payload).then((r) => r.data),
        onSuccess: () => {
            // Generation is queued; refresh shortly after.
            setTimeout(() => void queryClient.invalidateQueries({ queryKey: ['reports'] }), 500);
        },
    });
}
