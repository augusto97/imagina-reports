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
    mercadopago_webhook_configured: boolean;
    paypal_configured: boolean;
    paypal_webhook_configured: boolean;
    billing_sandbox: boolean;
    /** Where each provider must send its notifications (to paste in their panels). */
    mercadopago_webhook_url?: string;
    paypal_webhook_url?: string;
}

export interface PlatformIntegrationsSettings {
    google_oauth_client_id: string;
    google_ads_login_customer_id: string;
    meta_oauth_app_id: string;
    google_oauth_client_secret_set: boolean;
    google_ads_developer_token_set: boolean;
    meta_oauth_app_secret_set: boolean;
    google_connect_ready: boolean;
    meta_connect_ready: boolean;
    google_from_env: boolean;
    meta_from_env: boolean;
    setup: {
        app_url: string;
        https: boolean;
        domain: string;
        homepage_url: string;
        privacy_url: string;
        terms_url: string;
        google_redirect_uris: string[];
        meta_redirect_uris: string[];
    };
    /** Claude for agencies without their own key. Choices come live from Anthropic's model list. */
    anthropic_key_set: boolean;
    ai_ready: boolean;
    /** A key was left at install time and is in use (nothing saved in the panel yet). */
    ai_key_preinstalled: boolean;
    /** `auto:{family}` (always the newest of that family) or a pinned model id. */
    ai_model: string;
    /** What that choice means today, e.g. «Claude Opus 5.5». */
    ai_model_now: string;
    ai_choices: AiChoice[];
    ai_effort: string;
    /** The levels the model in use accepts (empty: it doesn't take one). */
    ai_efforts: AiModelOption[];
    /** The level that will actually be sent, or null. */
    ai_effort_now: string | null;
    /** False while Anthropic's list couldn't be read (what the model accepts isn't known yet). */
    ai_model_known: boolean;
    /** Why Anthropic's list couldn't be read (the last good one is still used). */
    ai_models_error: string | null;
}

export interface AiModelOption {
    id: string;
    label: string;
    hint: string;
}

export interface AiChoice extends AiModelOption {
    /** `auto`: always the newest of a family; `fixed`: one model. */
    group: 'auto' | 'fixed';
}

/** Result of «Probar» on the AI key + model. */
export interface AiTestResult {
    ok: boolean;
    model?: string;
    model_label?: string;
    message: string;
}

export interface PlatformMailSettings {
    mail_mailer: string;
    mail_host: string;
    mail_port: string;
    mail_username: string;
    mail_scheme: string;
    mail_from_address: string;
    mail_from_name: string;
    mail_password_set: boolean;
    mail_sends: boolean;
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

/** One-click connect metadata for a connector (null when only the manual form applies). */
export interface ConnectorConnect {
    label: string;
    fields: ConfigFieldDef[];
}

export interface Connector {
    key: string;
    label: string;
    config_schema: ConfigFieldDef[];
    guide?: ConnectorGuide | null;
    connect?: ConnectorConnect | null;
    /** The connector can list the accounts/projects its credentials reach ("Detectar cuentas"). */
    lists_resources?: boolean;
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
    /** Site Agent only: plugin version reported by the site + whether it's behind ours. */
    agent_version?: string | null;
    agent_latest?: string | null;
    agent_outdated?: boolean;
    agent_logging_since?: string | null;
    /** After a one-click OAuth connect: the resources (GA4 properties, ad accounts…) to pick from. */
    connect_options?: { field: string; label: string; options: { value: string; label: string }[] } | null;
    /** «Pedir reconexión al cliente» (Etapa 5): when and to whom it was last sent. */
    reconnect_requested_at?: string | null;
    reconnect_requested_to?: string | null;
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
    /** Client visits to the portal (the agency's own previews don't count). */
    first_viewed_at?: string | null;
    last_viewed_at?: string | null;
    view_count?: number;
    /** Client questions from the portal still waiting for an answer (Etapa 4). */
    pending_questions?: number;
}

export interface CatalogMeasure {
    key: string;
    label: string;
    unit: string | null;
}

/** A client's goal for one site (Etapa 4), shown by the «Metas» block. */
export interface SiteGoalDto {
    id: number;
    site_id: number;
    label: string;
    source: string;
    metric: string;
    target: number;
    direction: 'at_least' | 'at_most';
    format: 'number' | 'percent' | 'currency' | 'duration' | null;
    position: number;
}

/** One message in a report's conversation with the client (Etapa 4). */
export interface ReportMessageDto {
    id: number;
    kind: 'client' | 'agency' | 'ai';
    author: string | null;
    body: string;
    answer: string | null;
    created_at: string;
    author_email?: string | null;
    handled_at?: string | null;
}

/** A report with client questions still waiting for the agency. */
export interface QuestionInboxRow {
    report_id: number;
    report_definition_id: number;
    site: string;
    period: string;
    count: number;
    author: string | null;
    excerpt: string;
    created_at: string;
}

export interface CatalogEntry {
    source: string;
    metric: string;
    key: string;
    label: string;
    type: string;
    unit: string | null;
    dimensions: string[];
    // One plain sentence on what the number means (null when the connector doesn't say).
    description?: string | null;
    // Dataset metadata (empty for plain metrics): the measures a block can pick and the
    // human labels for each dimension key — drives the editor's modeling panel.
    measures?: CatalogMeasure[];
    dimension_labels?: Record<string, string>;
}

export interface ReportTheme {
    accent?: string | null;
    density?: 'normal' | 'compact' | null;
    style?: import('@shared/lib/publicReport').ReportVisualStyle | null;
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
    /** The clients' reports that follow this template (editing it changes their next report). */
    used_by?: { id: number; name: string; site: string | null }[];
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
    client_email?: string | null;
    period_end: string;
    opportunities: UpsellOpportunityView[];
}

/** A client that may be about to cancel, and why (Etapa 5). */
export interface ChurnRiskSite {
    site_id: number;
    site_name: string;
    client_name: string | null;
    score: number;
    level: 'high' | 'medium' | 'low';
    reasons: { key: string; text: string; weight: number; action: 'reports' | 'questions' | 'site' | 'worklogs' }[];
    latest_report_id: number | null;
}

export interface ChurnRisk {
    summary: { sites: number; high: number; medium: number; low: number };
    sites: ChurnRiskSite[];
}

/** When a signal counts as an opportunity — tuned by each agency (Etapa 5). */
export interface UpsellThresholds {
    traffic_growth_pct: number;
    sales_growth_pct: number;
    security_min_total: number;
    plan_overuse_pct: number;
    slow_response_ms: number;
}

export interface AgencyUpsell {
    summary: {
        sites_count: number;
        sites_with_opportunities: number;
        opportunities_count: number;
        /** Hidden because the agency already offered them recently. */
        offered_count?: number;
    };
    sites: SiteUpsell[];
    thresholds?: UpsellThresholds;
}

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    role: string;
    is_platform_admin?: boolean;
    impersonating?: number | null;
    app_version?: string;
    two_factor_enabled?: boolean;
    pending_email?: string | null;
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
    domain?: string | null;
    domain_status?: 'pending_dns' | 'pending_ssl' | 'active' | null;
    plan: { id: number; name: string; slug: string } | null;
    plan_id: number | null;
    plan_overrides: Record<string, unknown> | null;
    limits: PlanLimits;
    usage: PlanUsage;
    created_at: string | null;
}

/** One person inside any agency, as the platform operator sees them. */
export interface PlatformAgencyUser {
    id: number;
    name: string;
    email: string;
    role: 'owner' | 'admin' | 'collaborator';
    two_factor_enabled: boolean;
    created_at: string | null;
}

/** The agency detail view: everything the operator needs on one screen. */
export interface PlatformAgencyDetail extends PlatformAgency {
    users: PlatformAgencyUser[];
    subscription: { provider: string; status: string; current_period_end: string | null } | null;
}

/** Platform-wide vital signs shown on the operator's landing tab. */
export interface PlatformOverview {
    agencies: { total: number; active: number; suspended: number; new_this_month: number };
    users: { total: number; with_two_factor: number };
    workload: { clients: number; sites: number; data_sources: number; reports_this_month: number };
    health: { failing_sources: number; snapshots: number; storage_bytes: number };
    billing: { active_subscriptions: number; past_due: number };
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
    /** The agency's own model choice (only with its own key); null = the platform's. */
    ai_model: string | null;
    /** Choices from Anthropic's live list for the agency's own key (empty without one). */
    ai_choices: AiChoice[];
    /** The model in use right now, and the platform's. */
    ai_model_now: string;
    ai_platform_model: string;
    ai_platform_available: boolean;
    /** The agency's own effort level (only with its own key); null = the platform's. */
    ai_effort: string | null;
    ai_efforts: AiModelOption[];
    ai_effort_now: string | null;
    ai_model_known: boolean;
    ai_platform_effort: string;
    ai_models_error: string | null;
    snapshot_retention_months: number | null;
    calculated_metrics: { key: string; label: string; formula: string }[];
    webhook_urls: string[];
    webhook_secret_set: boolean;
    slack_webhook_url: string;
    /** Own domain for the client pages (plan `custom_domain`): set-up state + where links point. */
    domain: string | null;
    domain_status: 'pending_dns' | 'pending_ssl' | 'active' | null;
    domain_error: string | null;
    domain_checked_at: string | null;
    domain_target: string;
    public_base_url: string;
    /** Own SMTP for report emails (the password is never sent back). */
    sender: AgencySender | null;
    /** How clients reach the agency (portal "Escríbenos" + reply-to of the report email). */
    contact_email: string;
    contact_phone: string;
    website: string;
    /** «Pregúntale a tu reporte» in the client portal (plan with AI). */
    client_ai: boolean;
    /** Which emails the team gets (Etapa 5). */
    notify: { source_failures: boolean; client_questions: boolean };
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

export interface AuditLogEntry {
    id: number;
    action: string;
    summary: string | null;
    actor_name: string | null;
    actor_email: string | null;
    subject_type: string | null;
    subject_id: number | null;
    ip: string | null;
    created_at: string | null;
}

export interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface AgencySender {
    from_address: string;
    from_name: string;
    host: string;
    port: number | string;
    username: string;
    scheme: string;
    password_set: boolean;
}
