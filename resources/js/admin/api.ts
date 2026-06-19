import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api, fetchCsrfCookie } from '@shared/lib/api';

import type { Block } from '@shared/blocks/types';

import type {
    AgencyTrends,
    AuthUser,
    CatalogEntry,
    Client,
    Connector,
    DataSourceDto,
    ReportDefinitionDto,
    ReportSummary,
    ReportTemplateDto,
    Site,
    UpdateStatus,
} from './types';

async function get<T>(url: string): Promise<T> {
    const { data } = await api.get<T>(url);

    return data;
}

/* ----------------------------------- auth ---------------------------------- */

export function useAuthUser() {
    return useQuery({
        queryKey: ['auth-user'],
        queryFn: () => get<{ user: AuthUser }>('/user').then((r) => r.user),
        retry: false,
        staleTime: Infinity,
    });
}

export function useLogin() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (payload: { email: string; password: string }): Promise<AuthUser> => {
            await fetchCsrfCookie();
            const { data } = await api.post<{ user: AuthUser }>('/login', payload);

            return data.user;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['auth-user'] }),
    });
}

export function useLogout() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: () => api.post('/logout').then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['auth-user'] }),
    });
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

/* --------------------------------- system ---------------------------------- */

export function useUpdateStatus() {
    return useQuery({ queryKey: ['update-status'], queryFn: () => get<UpdateStatus>('/system/update/status') });
}

export function useRunUpdate() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: () => api.post('/system/update/run').then((r) => r.data),
        onSuccess: () => setTimeout(() => void queryClient.invalidateQueries({ queryKey: ['update-status'] }), 1500),
    });
}

export function useRollback() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: () => api.post('/system/update/rollback').then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['update-status'] }),
    });
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

export function useReportTemplate(id: number | null) {
    return useQuery({
        queryKey: ['report-template', id],
        queryFn: () => get<ReportTemplateDto>(`/report-templates/${id}`),
        enabled: id !== null,
    });
}

export function useCreateReportTemplate() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { name: string; blocks: Block[] }) =>
            api.post<ReportTemplateDto>('/report-templates', payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['report-templates'] }),
    });
}

export function useUpdateReportTemplate(id: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { name: string; blocks: Block[] }) =>
            api.put<ReportTemplateDto>(`/report-templates/${id}`, payload).then((r) => r.data),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['report-templates'] });
            void queryClient.invalidateQueries({ queryKey: ['report-template', id] });
        },
    });
}

export function useDeleteReportTemplate() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id: number) => api.delete(`/report-templates/${id}`).then((r) => r.data),
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
