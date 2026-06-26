import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api, fetchCsrfCookie } from '@shared/lib/api';

import type { Block } from '@shared/blocks/types';

import type {
    AgencySettings,
    AgencyTrends,
    AgencyUpsell,
    AuthUser,
    CatalogEntry,
    Client,
    Connector,
    DataSourceDto,
    PageFilters,
    ReportDefinitionDto,
    ReportSharingPayload,
    ReportSummary,
    ReportComment,
    ReportTemplateDto,
    ReportTheme,
    Site,
    UpdateStatus,
    WorkLog,
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

export interface PasswordChange {
    current_password: string;
    password: string;
    password_confirmation: string;
}

export function useChangePassword() {
    return useMutation({
        mutationFn: (payload: PasswordChange) => api.put('/user/password', payload).then((r) => r.data),
    });
}

export function useLogout() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: () => api.post('/logout').then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['auth-user'] }),
    });
}

/* --------------------------------- agency ---------------------------------- */

export function useAgency() {
    return useQuery({ queryKey: ['agency'], queryFn: () => get<AgencySettings>('/agency') });
}

export interface AgencyUpdate {
    name: string;
    brand_color: string | null;
    default_locale: string;
    anthropic_key?: string;
    snapshot_retention_months?: number | null;
}

export function useUpdateAgency() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: AgencyUpdate) => api.put<AgencySettings>('/agency', payload).then((r) => r.data),
        onSuccess: (data) => {
            queryClient.setQueryData(['agency'], data);
            void queryClient.invalidateQueries({ queryKey: ['retention-preview'] });
        },
    });
}

export interface RetentionPreview {
    snapshots: number;
    bytes: number;
}

/** How much a retention prune would free for the agency right now. */
export function useRetentionPreview() {
    return useQuery({ queryKey: ['retention-preview'], queryFn: () => get<RetentionPreview>('/agency/retention/preview') });
}

/** Run the retention prune now; returns how many snapshots were deleted. */
export function usePruneSnapshots() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: () => api.post<{ deleted: number }>('/agency/retention/prune').then((r) => r.data),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['retention-preview'] });
            void queryClient.invalidateQueries({ queryKey: ['data-source-coverage'] });
        },
    });
}

export function useUploadLogo() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (file: File) => {
            const form = new FormData();
            form.append('logo', file);

            return api.post<AgencySettings>('/agency/logo', form).then((r) => r.data);
        },
        onSuccess: (data) => queryClient.setQueryData(['agency'], data),
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

export function useUpdateClient(clientId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { name?: string; contact_email?: string | null; locale?: string | null; timezone?: string | null; notes?: string | null }) =>
            api.put<Client>(`/clients/${clientId}`, payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['clients'] }),
    });
}

export function useDeleteClient() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (clientId: number) => api.delete(`/clients/${clientId}`).then((r) => r.data),
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
        mutationFn: (payload: { client_id: number; name: string; url: string; currency?: string }) =>
            api.post<Site>('/sites', payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['sites'] }),
    });
}

export function useUpdateSite(id: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { client_id?: number; name?: string; url?: string; currency?: string; plan_hours?: number | null }) =>
            api.put<Site>(`/sites/${id}`, payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['sites'] }),
    });
}

/* -------------------------------- work logs -------------------------------- */

export interface WorkLogInput {
    description: string;
    minutes?: number | null;
    category?: string | null;
    performed_at?: string;
    screenshot?: File | null;
}

/** Work logs for a site within a period (CLAUDE.md §11.5). */
export function useSiteWorkLogs(siteId: number | null, from?: string, to?: string) {
    return useQuery({
        queryKey: ['site-work-logs', siteId, from, to],
        enabled: siteId !== null,
        queryFn: () => {
            const params = new URLSearchParams();
            if (from !== undefined) params.set('from', from);
            if (to !== undefined) params.set('to', to);

            return get<WorkLog[]>(`/sites/${siteId}/work-logs?${params.toString()}`);
        },
    });
}

export function useCreateSiteWorkLog(siteId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ screenshot, ...fields }: WorkLogInput) => {
            // Multipart only when a proof-of-work screenshot is attached; otherwise JSON.
            if (screenshot instanceof File) {
                const form = new FormData();
                form.append('description', fields.description);
                if (fields.minutes != null) form.append('minutes', String(fields.minutes));
                if (fields.category != null && fields.category !== '') form.append('category', fields.category);
                if (fields.performed_at != null) form.append('performed_at', fields.performed_at);
                form.append('screenshot', screenshot);

                return api.post<WorkLog>(`/sites/${siteId}/work-logs`, form).then((r) => r.data);
            }

            return api.post<WorkLog>(`/sites/${siteId}/work-logs`, fields).then((r) => r.data);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['site-work-logs'] }),
    });
}

export function useDeleteWorkLog() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id: number) => api.delete(`/work-logs/${id}`),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['site-work-logs'] }),
    });
}

/* -------------------------------- connectors ------------------------------- */

export function useConnectors() {
    return useQuery({ queryKey: ['connectors'], queryFn: () => get<Connector[]>('/connectors') });
}

/* ------------------------------- data sources ------------------------------ */

export function useSiteDataSources(siteId: number | null, options?: { refetchInterval?: number | false }) {
    return useQuery({
        queryKey: ['data-sources', siteId],
        queryFn: () => get<DataSourceDto[]>(`/sites/${siteId}/data-sources`),
        enabled: siteId !== null,
        refetchInterval: options?.refetchInterval ?? false,
    });
}

export interface CoverageGap {
    start: string;
    end: string;
}

export interface DataSourceCoverage {
    data_source_id: number;
    period_start: string | null;
    period_end: string | null;
    snapshots: number;
    bytes: number;
    // Uncovered day-ranges inside the span (e.g. a month that was never synced).
    gaps: CoverageGap[];
}

/** Stored-data coverage per source (date span, snapshot count, approx storage). */
export function useDataSourceCoverage(siteId: number | null, options?: { refetchInterval?: number | false }) {
    return useQuery({
        queryKey: ['data-source-coverage', siteId],
        queryFn: () => get<DataSourceCoverage[]>(`/sites/${siteId}/data-sources/coverage`),
        enabled: siteId !== null,
        refetchInterval: options?.refetchInterval ?? false,
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

export function useUpdateDataSource(siteId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, ...payload }: { id: number; config?: Record<string, string>; credentials?: Record<string, string> }) =>
            api.put<DataSourceDto>(`/data-sources/${id}`, payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['data-sources', siteId] }),
    });
}

export function useDeleteDataSource(siteId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id: number) => api.delete(`/data-sources/${id}`).then((r) => r.data),
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

/* ----------------------- GA4 self-serve dataset builder -------------------- */

export interface Ga4MetaField {
    api: string;
    label: string;
    category: string;
    custom: boolean;
}
export interface Ga4Metadata {
    dimensions: Ga4MetaField[];
    metrics: (Ga4MetaField & { type: string })[];
}
export interface Ga4DatasetSpec {
    key: string;
    label: string;
    dimensions: { key: string; label: string; api: string }[];
    measures: { key: string; label: string; api: string; unit: string | null; cast: 'int' | 'float'; scale: number }[];
    limit: number;
}

/** GA4 property metadata for the builder dropdowns (only fetched while the builder is open). */
export function useGa4Metadata(dataSourceId: number | null) {
    return useQuery({
        queryKey: ['ga4-metadata', dataSourceId],
        queryFn: () => get<Ga4Metadata>(`/data-sources/${dataSourceId ?? 0}/ga4/metadata`),
        enabled: dataSourceId !== null,
        staleTime: 60 * 60 * 1000,
        retry: false,
    });
}

/** Dry-run a composed dataset for the last 28 days (no save) → a sample of rows. */
export function useTestGa4Dataset(dataSourceId: number) {
    return useMutation({
        mutationFn: (spec: Ga4DatasetSpec) =>
            api
                .post<{ ok: boolean; rows: Record<string, unknown>[]; error: string | null }>(`/data-sources/${dataSourceId}/ga4/datasets/test`, spec)
                .then((r) => r.data),
    });
}

export function useSaveGa4Dataset(dataSourceId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (spec: Ga4DatasetSpec) =>
            api.post<{ custom_datasets: Ga4DatasetSpec[] }>(`/data-sources/${dataSourceId}/ga4/datasets`, spec).then((r) => r.data),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['data-sources'] });
            void queryClient.invalidateQueries({ queryKey: ['metric-catalog'] });
        },
    });
}

export function useDeleteGa4Dataset(dataSourceId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (key: string) =>
            api.delete<{ custom_datasets: Ga4DatasetSpec[] }>(`/data-sources/${dataSourceId}/ga4/datasets/${key}`).then((r) => r.data),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['data-sources'] });
            void queryClient.invalidateQueries({ queryKey: ['metric-catalog'] });
        },
    });
}

/* ---------------------------- report definitions --------------------------- */

export interface SnapshotPeriod {
    period_start: string;
    period_end: string;
}

/** Periods for which a site has synced snapshots — to default/validate the generate period. */
export function useSnapshotPeriods(siteId: number | null) {
    return useQuery({
        queryKey: ['snapshot-periods', siteId],
        queryFn: () => get<SnapshotPeriod[]>(`/sites/${siteId ?? 0}/snapshot-periods`),
        enabled: siteId !== null,
    });
}

export function useReportDefinitions() {
    return useQuery({ queryKey: ['report-definitions'], queryFn: () => get<ReportDefinitionDto[]>('/report-definitions') });
}

export function useCreateReportDefinition() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { site_id: number; name: string; template_id?: number; recipients?: string[] }) =>
            api.post<ReportDefinitionDto>('/report-definitions', payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['report-definitions'] }),
    });
}

export function useUpdateReportDefinition() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, ...payload }: { id: number; template_id?: number | null; name?: string; recipients?: string[] }) =>
            api.put<ReportDefinitionDto>(`/report-definitions/${id}`, payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['report-definitions'] }),
    });
}

export function useUpdateReportSharing() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & ReportSharingPayload) =>
            api.put<ReportDefinitionDto>(`/report-definitions/${id}/sharing`, payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['report-definitions'] }),
    });
}

export function useRotateDashboardToken() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (definitionId: number) =>
            api.post<ReportDefinitionDto>(`/report-definitions/${definitionId}/sharing/dashboard-token`).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['report-definitions'] }),
    });
}

export function useDeleteReport() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (reportId: number) => api.delete(`/reports/${reportId}`).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['reports'] }),
    });
}

export function useDeleteReportDefinition() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (definitionId: number) => api.delete(`/report-definitions/${definitionId}`).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['report-definitions'] }),
    });
}

export function useApproveReport() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (reportId: number) => api.post(`/reports/${reportId}/approve`).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['reports'] }),
    });
}

export function useSendReport() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (reportId: number) => api.post(`/reports/${reportId}/send`).then((r) => r.data),
        onSuccess: () => setTimeout(() => void queryClient.invalidateQueries({ queryKey: ['reports'] }), 800),
    });
}

/** AI insights for a generated report (CLAUDE.md §10.6). */
export function useReportInsights() {
    return useMutation({
        mutationFn: (reportId: number) =>
            api.post<{ insights: string[] }>(`/reports/${reportId}/insights`).then((r) => r.data.insights),
    });
}

/** Save an operator-edited executive summary (CLAUDE.md §10.6 "always editable"). */
export function useUpdateReportNarrative() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ reportId, text }: { reportId: number; text: string }) =>
            api.put<{ executive_summary: string | null }>(`/reports/${reportId}/narrative`, { text }).then((r) => r.data.executive_summary),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['reports'] }),
    });
}

/** Re-write the executive summary with the AI from the report's stored figures. */
export function useRegenerateReportNarrative() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (reportId: number) =>
            api.post<{ executive_summary: string | null }>(`/reports/${reportId}/narrative/regenerate`).then((r) => r.data.executive_summary),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['reports'] }),
    });
}

/* -------------------------------- comments --------------------------------- */

export function useReportComments(reportId: number | null) {
    return useQuery({
        queryKey: ['report-comments', reportId],
        enabled: reportId !== null,
        queryFn: () => get<ReportComment[]>(`/reports/${reportId}/comments`),
    });
}

export function useCreateReportComment(reportId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { body: string; visibility: 'internal' | 'client' }) =>
            api.post<ReportComment>(`/reports/${reportId}/comments`, payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['report-comments'] }),
    });
}

export function useDeleteComment() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id: number) => api.delete(`/comments/${id}`),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['report-comments'] }),
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

/* --------------------------------- upsell ---------------------------------- */

export function useUpsell() {
    return useQuery({ queryKey: ['upsell'], queryFn: () => get<AgencyUpsell>('/upsell') });
}

/* --------------------------------- system ---------------------------------- */

export function useUpdateStatus() {
    return useQuery({
        queryKey: ['update-status'],
        queryFn: () => get<UpdateStatus>('/system/update/status'),
        // While an update is queued/running, poll so the UI reflects success/failure live.
        refetchInterval: (query) => {
            const status = query.state.data?.last_run.status;

            return status === 'queued' || status === 'running' ? 3000 : false;
        },
    });
}

/** Poll GitHub on demand ("Buscar actualizaciones") instead of waiting for the hourly job. */
export function useCheckUpdates() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: () => api.post<UpdateStatus>('/system/update/check').then((r) => r.data),
        onSuccess: (status) => queryClient.setQueryData(['update-status'], status),
    });
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

/** Generate (headless Chromium) and download a report's PDF. */
export function useDownloadReportPdf() {
    return useMutation({
        mutationFn: async (reportId: number) => {
            // Chromium rendering can take a while; don't abort early.
            const response = await api.get(`/reports/${reportId}/pdf`, { responseType: 'blob', timeout: 120000 });
            const url = URL.createObjectURL(response.data as Blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `reporte-${reportId}.pdf`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        },
        onError: async (error) => {
            // The server sends a JSON reason, but responseType:'blob' wraps it — unwrap it.
            let message = 'No se pudo generar el PDF.';
            const data = (error as { response?: { data?: unknown } }).response?.data;
            if (data instanceof Blob) {
                try {
                    const parsed = JSON.parse(await data.text()) as { message?: string };
                    if (typeof parsed.message === 'string') {
                        message = parsed.message;
                    }
                } catch {
                    /* keep default */
                }
            }
            window.alert(message);
        },
    });
}

export function useRestartWorkers() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: () => api.post('/system/update/restart-workers').then((r) => r.data),
        // Give Horizon a few seconds to respawn and the worker to re-record its version.
        onSuccess: () => setTimeout(() => void queryClient.invalidateQueries({ queryKey: ['update-status'] }), 4000),
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

/** Load the default narrative layout (CLAUDE.md §11.5) as an editor starting point. */
export function useDefaultTemplateBlocks() {
    return useMutation({
        mutationFn: () => get<{ blocks: Block[] }>('/report-templates/default-blocks').then((result) => result.blocks),
    });
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
        mutationFn: (payload: { name: string; blocks: Block[]; calculated_metrics?: CalcMetric[]; theme?: ReportTheme | null; filters?: PageFilters | null }) =>
            api.post<ReportTemplateDto>('/report-templates', payload).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['report-templates'] }),
    });
}

export function useUpdateReportTemplate(id: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { name: string; blocks: Block[]; calculated_metrics?: CalcMetric[]; theme?: ReportTheme | null; filters?: PageFilters | null }) =>
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
    dropped: { type: string; metric: string }[];
}

export function useAiTemplate(siteId: number) {
    return useMutation({
        mutationFn: (prompt: string) =>
            api.post<AiTemplateResult>(`/sites/${siteId}/ai-template`, { prompt }).then((r) => r.data),
    });
}

/* ------------------------------ editor: preview ---------------------------- */

export interface PreviewResult {
    blocks: Block[];
    data: Record<string, unknown>;
    score: number;
    period: { start: string; end: string };
    has_data: boolean;
    sources_with_data: string[];
}

export interface CalcMetric {
    key: string;
    label: string;
    formula: string;
}

/** Resolve a draft layout against a site + period into REAL metric data (CLAUDE.md §11.3). */
export function usePreview(siteId: number) {
    return useMutation({
        mutationFn: (payload: { blocks: Block[]; period_start?: string; period_end?: string; calculated_metrics?: CalcMetric[]; filters?: PageFilters }) =>
            api.post<PreviewResult>(`/sites/${siteId}/preview`, payload).then((r) => r.data),
    });
}

/**
 * Trigger an on-demand sync of the site's data sources ("Sincronizar ahora"). Pass
 * `data_source_ids` to sync only those (e.g. a new source/metric) instead of all.
 */
export function useSyncSite(siteId: number) {
    return useMutation({
        mutationFn: (payload?: { period_start: string; period_end: string; data_source_ids?: number[] }) =>
            api.post<{ queued: number; period: { start: string; end: string } }>(`/sites/${siteId}/sync`, payload ?? {}).then((r) => r.data),
    });
}

/** Sync a site for a period from anywhere (siteId provided per call, e.g. a report row). */
export function useSyncSiteById() {
    return useMutation({
        mutationFn: ({ siteId, period_start, period_end, data_source_ids }: { siteId: number; period_start: string; period_end: string; data_source_ids?: number[] }) =>
            api.post<{ queued: number }>(`/sites/${siteId}/sync`, { period_start, period_end, data_source_ids }).then((r) => r.data),
    });
}

export function useGenerateReport() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: { report_definition_id: number; period_start: string; period_end: string }) =>
            api.post('/reports/generate', payload).then((r) => r.data),
        onSuccess: () => {
            // Generation runs on the queue and the report row only appears once the job
            // finishes, so a single quick refetch usually misses it. Re-fetch on a short
            // bounded schedule until it lands — no manual reload needed.
            const delays = [600, 1500, 3000, 5000, 8000, 12000];
            for (const delay of delays) {
                setTimeout(() => void queryClient.invalidateQueries({ queryKey: ['reports'] }), delay);
            }
        },
    });
}

/* -------------------------------- site agent ------------------------------- */

/**
 * Fetch the companion WordPress plugin ZIP (authenticated) and trigger a browser
 * download. Used by the "Agente Imagina (sitio)" connector setup panel.
 */
export async function downloadSiteAgentPlugin(): Promise<void> {
    const { data } = await api.get<Blob>('/system/site-agent/download', { responseType: 'blob' });

    const url = URL.createObjectURL(data);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'imagina-reports-agent.zip';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
