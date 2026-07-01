import type { DatasetFilter } from '@shared/blocks/types';

export type { DatasetFilter };

export interface Client {
    id: number;
    name: string;
    contact_email: string | null;
    locale: string | null;
    timezone: string | null;
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
    calculated_metrics: { key: string; label: string; formula: string }[];
}

export interface ReportComment {
    id: number;
    body: string;
    visibility: 'internal' | 'client';
    author: string | null;
    created_at: string;
}

export type WorkLogStatus = 'done' | 'in_progress' | 'planned';

export interface WorkLog {
    id: number;
    report_id: number | null;
    site_id: number;
    performed_at: string;
    description: string;
    status: WorkLogStatus;
    minutes: number | null;
    category: string | null;
    screenshot_path: string | null;
    screenshot_url: string | null;
}

export interface BillingPlanOption {
    id: number;
    name: string;
    monthly_price: number | null;
    currency: string;
    max_sites: number | null;
    max_clients: number | null;
    max_users: number | null;
    features: Record<string, boolean>;
}

export interface BillingInfo {
    status: string;
    current_plan_id: number | null;
    billing_email: string | null;
    plan: { name: string; monthly_price: number | null; currency: string } | null;
    subscription: { provider: string; plan_id: number | null; status: string; current_period_end: string | null } | null;
    plans: BillingPlanOption[];
    providers: { key: string; label: string }[];
}

export interface PlatformBillingSettings {
    mercadopago_configured: boolean;
    paypal_configured: boolean;
    billing_sandbox: boolean;
}

export interface TeamMember {
    id: number;
    name: string;
    email: string;
    role: 'owner' | 'admin' | 'collaborator';
    is_self: boolean;
    created_at: string | null;
}

export interface ReportDelivery {
    id: number;
    report_id: number;
    channel: string;
    recipient: string;
    status: 'pending' | 'sent' | 'failed';
    sent_at: string | null;
    error: string | null;
    created_at: string | null;
}

export interface AnomalyAlert {
    id: number;
    site_id: number;
    site_name: string | null;
    report_id: number | null;
    type: 'traffic_drop' | 'attack_spike';
    metric: string;
    current: number;
    previous: number;
    change_percent: number;
    acknowledged_at: string | null;
    detected_at: string | null;
}

export interface ConfigFieldDef {
    key: string;
    label: string;
    type: string;
    required: boolean;
    secret: boolean;
    help: string | null;
}

export interface ConnectorGuide {
    intro: string;
    steps: string[];
    docs_url: string | null;
}

export interface Connector {
    key: string;
    label: string;
    config_schema: ConfigFieldDef[];
    guide?: ConnectorGuide | null;
}

export interface DataSourceDto {
    id: number;
    site_id: number;
    type: string;
    status: string;
    config: Record<string, unknown> | null;
    last_synced_at: string | null;
    last_error: string | null;
    /** MainWP only: child site records its activity history (null otherwise / unsynced). */
    child_reports_active?: boolean | null;
    /** Push-capable sources (CrowdSec): the VPS posts data outbound to ingest_url. */
    is_push?: boolean;
    push_token?: string | null;
    ingest_url?: string | null;
}

export type ReportVisibility = 'public' | 'password' | 'private';

export interface ReportDefinitionDto {
    id: number;
    site_id: number;
    name: string;
    template_id: number | null;
    locale: string;
    recipients: string[];
    visibility: ReportVisibility;
    has_password: boolean;
    embed_domains: string[];
    dashboard_enabled: boolean;
    dashboard_token: string | null;
}

export type ScheduleCadence = 'monthly' | 'weekly';

export interface ScheduleDto {
    id: number;
    report_definition_id: number;
    cadence: ScheduleCadence;
    send_day: number | null;
    next_run_at: string;
}

export interface ReportSharingPayload {
    visibility: ReportVisibility;
    password?: string | null;
    embed_domains?: string[];
    dashboard_enabled?: boolean;
}

export interface ReportSummary {
    id: number;
    report_definition_id: number;
    period_start: string;
    period_end: string;
    health_score: number | null;
    status: string;
    executive_summary: string | null;
    has_advisory?: boolean;
    advisory?: string | null;
    public_token: string;
    pdf_path: string | null;
    hidden_metrics: string[];
    created_at: string | null;
}

export interface CatalogMeasure {
    key: string;
    label: string;
    unit: string | null;
}

export interface CatalogEntry {
    source: string;
    metric: string;
    key: string;
    label: string;
    type: string;
    unit: string | null;
    dimensions: string[];
    // Dataset metadata (empty for plain metrics): the measures a block can pick and the
    // human labels for each dimension key — drives the editor's modeling panel.
    measures?: CatalogMeasure[];
    dimension_labels?: Record<string, string>;
}

export interface ReportTheme {
    accent?: string | null;
    density?: 'normal' | 'compact' | null;
    nav?: import('@shared/blocks/types').ReportNav | null;
}

/**
 * Page/dashboard filters (CLAUDE.md §10 dashboards): keyed by scope — `all` applies to
 * every page, a numeric page index applies to that page only. Block-level filters then
 * override these per dimension (block wins).
 */
export type PageFilters = Record<string, DatasetFilter[]>;

export interface ReportTemplateDto {
    id: number;
    name: string;
    blocks: unknown[];
    calculated_metrics: { key: string; label: string; formula: string }[];
    theme?: ReportTheme | null;
    filters?: PageFilters | null;
    /** Named pages for the navigation menu (§11 — Looker/Power-BI parity), indexed by page. */
    pages?: { name: string }[] | null;
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

export interface UpsellOpportunityView {
    type: string;
    label: string;
    context: Record<string, unknown>;
}

export interface SiteUpsell {
    site_id: number;
    site_name: string;
    client_name: string | null;
    period_end: string;
    opportunities: UpsellOpportunityView[];
}

export interface AgencyUpsell {
    summary: {
        sites_count: number;
        sites_with_opportunities: number;
        opportunities_count: number;
    };
    sites: SiteUpsell[];
}

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    role: string;
    is_platform_admin?: boolean;
    impersonating?: number | null;
    app_version?: string;
}

export interface PlanLimits {
    max_sites: number | null;
    max_data_sources: number | null;
    max_clients: number | null;
    max_users: number | null;
    max_reports_per_month: number | null;
    allowed_connectors: string[] | null;
    features: Record<string, boolean>;
}

export interface PlanUsage {
    sites: number;
    data_sources: number;
    clients: number;
    users: number;
    reports_this_month: number;
}

export interface Plan {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    sort: number;
    max_sites: number | null;
    max_data_sources: number | null;
    max_clients: number | null;
    max_users: number | null;
    max_reports_per_month: number | null;
    retention_months: number | null;
    allowed_connectors: string[] | null;
    features: Record<string, boolean>;
    monthly_price: number | null;
    currency: string;
}

export interface PlatformAgency {
    id: number;
    name: string;
    slug: string;
    status: string;
    plan: { id: number; name: string; slug: string } | null;
    plan_id: number | null;
    plan_overrides: Record<string, unknown> | null;
    limits: PlanLimits;
    usage: PlanUsage;
    created_at: string | null;
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
    snapshot_retention_months: number | null;
    calculated_metrics: { key: string; label: string; formula: string }[];
    webhook_urls: string[];
    webhook_secret_set: boolean;
    plan: { name: string; slug: string } | null;
    status: string;
    limits: PlanLimits;
    usage: PlanUsage;
}

export interface UpdateStatus {
    current: string | null;
    available: string | null;
    update_available: boolean;
    worker_version: string | null;
    worker_checked_at: string | null;
    last_run: UpdateRunState;
}
