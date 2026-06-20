export interface Client {
    id: number;
    name: string;
    contact_email: string | null;
    locale: string | null;
    notes: string | null;
}

export interface Site {
    id: number;
    client_id: number;
    name: string;
    url: string;
    hosting: string | null;
    support_plan: string | null;
    status: string;
    currency: string;
    plan_hours: string | null;
}

export interface WorkLog {
    id: number;
    report_id: number | null;
    site_id: number;
    performed_at: string;
    description: string;
    minutes: number | null;
    category: string | null;
    screenshot_path: string | null;
}

export interface ConfigFieldDef {
    key: string;
    label: string;
    type: string;
    required: boolean;
    secret: boolean;
    help: string | null;
}

export interface Connector {
    key: string;
    label: string;
    config_schema: ConfigFieldDef[];
}

export interface DataSourceDto {
    id: number;
    site_id: number;
    type: string;
    status: string;
    config: Record<string, unknown> | null;
    last_synced_at: string | null;
    last_error: string | null;
}

export interface ReportDefinitionDto {
    id: number;
    site_id: number;
    name: string;
    template_id: number | null;
    locale: string;
}

export interface ReportSummary {
    id: number;
    report_definition_id: number;
    period_start: string;
    period_end: string;
    health_score: number | null;
    status: string;
    public_token: string;
    pdf_path: string | null;
}

export interface CatalogEntry {
    source: string;
    metric: string;
    key: string;
    label: string;
    type: string;
    unit: string | null;
    dimensions: string[];
}

export interface ReportTemplateDto {
    id: number;
    name: string;
    blocks: unknown[];
    calculated_metrics: { key: string; label: string; formula: string }[];
    is_default: boolean;
    locale: string;
}

export interface HealthPoint {
    period_end: string;
    health_score: number | null;
}

export interface SiteTrend {
    site_id: number;
    site_name: string;
    client_name: string | null;
    latest_health_score: number | null;
    reports_count: number;
    health_series: HealthPoint[];
}

export interface AgencyTrends {
    summary: {
        sites_count: number;
        reports_count: number;
        average_health_score: number | null;
    };
    sites: SiteTrend[];
}

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    role: string;
}

export type UpdateRunStatus = 'idle' | 'queued' | 'running' | 'success' | 'failed';

export interface UpdateRunState {
    status: UpdateRunStatus;
    version: string | null;
    message: string;
    at: string | null;
}

export interface AgencySettings {
    id: number;
    name: string;
    brand_color: string | null;
    default_locale: string;
    logo_path: string | null;
    logo_url: string | null;
    ai_key_set: boolean;
}

export interface UpdateStatus {
    current: string | null;
    available: string | null;
    update_available: boolean;
    last_run: UpdateRunState;
}
