# CLAUDE.md — Imagina Reports

> **Imagina Reports** is a multi-tenant SaaS platform that unifies data from a web agency's
> tooling (MainWP, Google Analytics, Search Console, Cloudflare, CrowdSec, VirusDie, Better Stack,
> WooCommerce, and — later — Imagina Audit) into a single **branded, narrated client report**.
> Its purpose is client **retention**: making invisible technical work visible so clients
> understand the value of their support plan and keep paying for it.
>
> Built by Imagina WP. Stack: Laravel 11 (PHP 8.3) API-first + React 18 SPA, on a Hetzner VPS
> managed with ServerAvatar (OLS / LSPHP / MariaDB / Redis).

---

## ⚠️ 0. HOW TO WORK ON THIS PROJECT — READ THIS FIRST, EVERY SESSION

You are Claude Code. This is a long, multi-phase project that will span many sessions and possibly
many separate conversations. To avoid losing context or repeating work, follow these rules **without exception**:

### Session start checklist (do this before writing any code)
1. **Read `PROGRESS.md`** at the repo root. It is the single source of truth for *what is done,
   what is in progress, and what comes next*. If it does not exist yet, create it from the template
   in §15 of this file as your very first action.
2. Read the **"Current Task"** and **"Next Up"** sections of `PROGRESS.md`.
3. Re-read the relevant section of this `CLAUDE.md` for the task you are about to do.
4. Run the project's test suite and `composer run stan` to confirm a clean baseline before changing anything.

### While working
- **Work strictly phase by phase** (see §13). Do **not** skip ahead or implement future-phase features early.
- Implement **one task at a time**. Keep changes small and focused.
- **Never invent connector behavior, schema, or API shapes.** If this spec is silent or ambiguous,
  STOP and add a question to the "Open Questions" section of `PROGRESS.md` rather than guessing.
- Follow the coding standards in §6 exactly (strict types, PHPStan level max, naming, layering).
- Write tests for every service, connector, and endpoint you create (see §14).

### Session end checklist (do this before you stop)
1. **Update `PROGRESS.md`:**
   - Move finished items to "Completed" (with the date).
   - Update "Current Task" and "Next Up".
   - Append any decisions you made to the "Decisions Log" (with date + rationale).
   - Append any new uncertainties to "Open Questions".
2. Ensure tests + PHPStan pass. If not, note the failing state in `PROGRESS.md`.
3. Commit with a clear message (see §6 commit convention). Commit `PROGRESS.md` too.
4. Write a one-paragraph "Where I left off" note at the top of `PROGRESS.md` so the next session
   (or a brand-new conversation) can resume in under a minute.

### The golden rule
> If a new conversation started right now with zero memory, could it read `PROGRESS.md` + this
> `CLAUDE.md` and continue seamlessly? If not, `PROGRESS.md` is not detailed enough — fix it.

---

## 1. Product vision

- **One job, done well:** collect data from all sources → normalize → produce a unified, branded,
  narrated report a non-technical client understands in 30 seconds.
- **It is a retention machine, not a chart aggregator.** It translates invisible technical work
  (updates, blocked attacks, uptime) into visible business value.
- **It replaces** the agency's Modular DS subscription and MainWP's Pro Reports extension.
- **Differentiator vs Looker Studio:** unified data across the *entire* stack (security + uptime +
  maintenance, not just GA/GSC/Woo), the "what we did this month" work log, full white-label
  branding, and one platform instead of one dashboard per client.

---

## 2. Locked technical decisions

These are **decided**. Do not relitigate them in code.

| Area | Decision |
|---|---|
| Environment | Hetzner VPS managed by **ServerAvatar**, stack **OLS** (LSPHP 8.3/8.4), **MariaDB**, **Redis** (all included in ServerAvatar). |
| Backend | **Laravel 11**, PHP **8.3**, strict types everywhere. |
| Architecture | **API-first**: versioned REST API (`/api/v1`) is the core; everything consumes it. |
| Auth | **Laravel Sanctum** — cookie sessions for own SPAs, API tokens for third-party integrations. |
| Frontend | **Two React 18 + TypeScript SPAs** (admin panel + interactive client portal). Built in **GitHub Actions** — Node.js is NOT installed on the server. |
| Multi-tenancy | **Agency = tenant.** Every domain row is scoped by `agency_id`. Internal use first, commercial-ready. |
| Queues/cache/sessions | **Redis** + **persistent queue worker** + **Horizon**. |
| Scheduler | Laravel scheduler via one cron (`* * * * * php artisan schedule:run`). |
| Report model | **Block-based templates** (drag-drop editor, dnd-kit + Tiptap). Reports are blocks bound to metrics, not fixed sections. |
| Metrics | **Not hardcoded.** Connectors expose a `MetricCatalog`; editor + AI pick freely from it. |
| AI builder | **`AiReportBuilder`** assembles a full draft report (validated block JSON) + per-period narrative via the **Claude API (Anthropic)** — "create a report in seconds". Always editable. _(Owner override 2026-06-18: replaces the originally-specced `gpt.imagina.cloud`.)_ |
| Report rendering | **Single source of truth**: a shared React `BlockRenderer`; the **PDF is produced by headless Chromium printing that same page** (pixel-perfect). |
| PDF engine | Spatie **Browsershot** (headless Chromium installed on the VPS). VPS isolation contains RAM spikes. |
| Deployment | **Atomic releases** (CI builds a self-contained ZIP → symlink swap) with in-app **Update** + **Rollback** (see §12). |

---

## 3. High-level architecture

### 3.1 Decoupling principle (critical)
Never call external APIs while *generating* a report. Three independent stages:

```
SYNC      → scheduled jobs hit each source API, normalize, store a snapshot
GENERATE  → (manual or scheduled) assemble active snapshots → ReportDTO
DELIVER   → portal (React via API) + PDF (Chromium) + email
```

Benefits: a failing API never breaks a report (uses last snapshot or marks "data unavailable");
manual reports are instant; rate limits never touch the client experience.

### 3.2 Layers
- **Connectors** — one class per source implementing `DataSourceConnector` (§9).
- **Snapshots** — normalized JSON per source × site × period in `ir_metric_snapshots`.
- **Report engine** — composes `ReportDTO` from typed sections; computes health score + summary (§10).
- **API** — versioned REST resources, the only data interface (§8).
- **Frontends** — admin SPA + client portal SPA, both consuming the API (§11).
- **Delivery** — portal links, Chromium PDF, scheduled branded email.
- **Updater** — `UpdateManager` for atomic deploy + rollback (§12).

### 3.3 Performance principle — aggregate at the source, never pull raw rows
This is what makes report generation fast regardless of data volume, and it is a hard rule for every
connector. The heavy lifting happens in the **sync** stage, and even there the connector must request
**already-aggregated** results, not raw records:

- API sources (GA4, GSC, Cloudflare, WooCommerce, Better Stack) aggregate **server-side by design** —
  the response is tiny (totals + top-N) no matter how many millions of underlying events exist.
- The `database`/CSV/endpoint connector must run `GROUP BY`/aggregate queries **on the client's source**
  and store only the summarized result.
- **Forbidden:** pulling millions of raw rows into PHP/MariaDB and aggregating in-app. That is the only
  thing that would break performance.

Report generation then reads small, pre-aggregated JSON snapshots from cache → milliseconds, always.
This is why Imagina Reports is high-performance *as a reporting tool*. It is **not** a BI engine
(no in-memory columnar store / DAX-style modeling over raw data); do not attempt to replicate Power BI's
analytical engine. Its niche is branded, scheduled, unified reporting at low cost — not ad-hoc analytics
over massive raw datasets.

---

## 4. Repository layout

```
imagina-reports/
├── app/
│   ├── Connectors/            # one class per source + the interface & registry
│   ├── Reports/               # blocks, MetricCatalog, ReportGenerator, HealthScoreCalculator, AiReportBuilder
│   ├── Services/              # SyncService, ReportGenerator, DeliveryService, UpdateManager
│   ├── Models/                # Eloquent models (tenant-scoped)
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   ├── Resources/         # API resources (JSON shaping)
│   │   └── Requests/          # FormRequests (validation)
│   ├── Jobs/                  # SyncSourceJob, GenerateReportJob, DeliverReportJob, RunUpdateJob
│   └── Support/Svg/           # (optional) server-side helpers if needed
├── resources/
│   ├── js/admin/              # admin SPA (React)
│   ├── js/portal/             # client portal SPA (React)
│   └── views/report/          # the report page (rendered for portal + printed to PDF)
├── routes/api.php
├── database/migrations/
├── tests/
├── .github/workflows/         # build + release + deploy
├── deploy.sh
├── CLAUDE.md                  # this file
└── PROGRESS.md                # living state — maintained by Claude Code
```

---

## 5. Data model (schema)

All domain tables are prefixed `ir_` and carry `agency_id` (tenant scope). Use a global scope so
every query is automatically tenant-filtered. `id` is an immutable BIGINT PK.

| Table | Key columns |
|---|---|
| `ir_agencies` | id, name, slug, logo_path, brand_color, default_locale, domain, settings(json) |
| `ir_users` | id, agency_id, name, email, password, role (owner/admin/collaborator) |
| `ir_clients` | id, agency_id, name, contact_email, locale, notes |
| `ir_sites` | id, agency_id, client_id, name, url, hosting, support_plan, status |
| `ir_data_sources` | id, agency_id, site_id, type (enum), credentials(**encrypted** json), config(json), status, last_synced_at, last_error |
| `ir_metric_snapshots` | id, agency_id, data_source_id, period_start, period_end, payload(json, **normalized**), status (ok/partial/failed), captured_at |
| `ir_report_templates` | id, agency_id, name, blocks(json: block-based layout), is_default, locale |
| `ir_report_definitions` | id, agency_id, site_id, name, template_id (nullable), blocks(json: overrides), requested_metrics(json), locale, schedule(json), recipients(json) |
| `ir_reports` | id, agency_id, report_definition_id, period_start, period_end, resolved_blocks(json snapshot), health_score, executive_summary(text), pdf_path, public_token, status (draft/approved/sent) |
| `ir_report_work_logs` | id, agency_id, report_id (nullable), site_id, performed_at, description, screenshot_path |
| `ir_report_deliveries` | id, agency_id, report_id, channel (email/portal), recipient, status, sent_at, error |
| `ir_schedules` | id, agency_id, report_definition_id, cadence (monthly/weekly), next_run_at |
| `ir_app_releases` | id, version, channel, bundle_url, checksum, released_at (for the self-updater) |

> **`ir_data_sources.type` is an extensible enum.** Adding Imagina Audit later = a new enum value
> `imagina_audit` + a new connector class. No schema refactor.

---

## 6. Coding standards & conventions

- **PHP 8.3, `declare(strict_types=1)`** in every file. Final classes by default.
- **PSR-4**, namespace `App\`. Domain code under `App\Connectors`, `App\Reports`, `App\Services`.
- **Service + Repository layering.** Controllers stay thin; business logic in services; data access via models/repositories.
- **Larastan / PHPStan at max level.** `composer run stan` must pass before any commit.
- **Pint** (Laravel code style) on every file.
- **API**: namespace `/api/v1`, plural resource nouns, `JsonResource` for output, `FormRequest` for input.
- **Tailwind prefix `ir-`** in both SPAs. **TypeScript strict.** shadcn/ui components copied locally.
- **Frontend stack (do not substitute):** React 18, Vite 5, TanStack Query + Table v8, Zustand,
  React Hook Form + Zod, Lucide React, Framer Motion, Inter (loaded locally). Design tokens: Linear/Vercel aesthetic.
- **Secrets**: connector credentials use Laravel `encrypted` casts. Never log credentials.
- **i18n**: report content is localized (ES default; EN, PT-BR available). App messages via Laravel localization.
- **Commits**: Conventional Commits (`feat:`, `fix:`, `chore:`, `refactor:`, `test:`). Small and frequent.
- **Idempotency**: every sync job must be safely re-runnable.

---

## 7. The connector contract & registry

```php
interface DataSourceConnector
{
    public function key(): string;                 // 'mainwp', 'ga4', 'gsc', ...
    public function label(): string;
    public function configSchema(): array;         // fields needed to configure it (drives admin UI)
    public function testConnection(DataSource $source): ConnectionResult;

    // What this source CAN provide. Drives the editor's binding picker and the AI builder.
    // Metrics/dimensions are NOT hardcoded in report logic — they come from here.
    public function metricCatalog(DataSource $source): MetricCatalog;

    // Fetch ONLY the metrics referenced by active report definitions for this source,
    // aggregated at source (§3.3). Returns a normalized metric bag, not fixed sections.
    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet;
}
```

- A **`ConnectorRegistry`** maps `type` → connector class. Adding a source = register one class.
- **`MetricCatalog`** lists available metrics, dimensions, and their types/units. The report editor and
  AI builder pick from it; **nothing about which metrics to show is hardcoded.**
- **`MetricSet`** is a normalized **metric bag** keyed by metric name (e.g. `ga4.sessions`,
  `ga4.sessions_by_date` series, `ga4.top_pages` table). Report **blocks** (§10) bind to these keys.
- Connectors must catch their own API errors and return a `partial`/`failed` MetricSet rather than throw.

---

## 8. REST API (v1)

Auth: Sanctum. Tenant resolved from the authenticated user's `agency_id`. Public report endpoints
use a signed `public_token` (no auth).

Core resources (CRUD unless noted):

```
GET    /api/v1/clients
GET    /api/v1/sites
GET    /api/v1/sites/{site}/data-sources
POST   /api/v1/sites/{site}/data-sources        # configure a connector (validates via testConnection)
POST   /api/v1/data-sources/{ds}/test           # re-test a connector
GET    /api/v1/report-definitions
GET    /api/v1/reports
POST   /api/v1/reports/generate                 # enqueue GenerateReportJob (manual generation)
GET    /api/v1/reports/{report}                 # report data for the admin preview / portal
POST   /api/v1/reports/{report}/approve
POST   /api/v1/reports/{report}/send
POST   /api/v1/reports/{report}/work-logs       # add manual "work done" entries
GET    /api/v1/schedules
GET    /api/v1/system/update/status             # current version + available version
POST   /api/v1/system/update/run                # trigger self-update (queues RunUpdateJob)
POST   /api/v1/system/update/rollback

# Public (signed token, no auth):
GET    /api/v1/public/reports/{public_token}    # data for the interactive client portal
```

- **Webhooks (outbound)**: emit `report.generated`, `report.sent`, `anomaly.detected` to
  configured URLs (for integrations / commercialization). Implement behind a `WebhookDispatcher`.
- Document the API with OpenAPI (generate from routes/resources).

---

## 9. Connectors — per-source spec

> For every connector: auth method, required config, what it reads, and which section(s) it populates.
> All produce **normalized** output (a metric bag, §10.1); never store raw API payloads. The last column
> lists the **default block grouping** each source feeds in the default template — not a fixed data
> contract. Actual metrics come from each connector's `MetricCatalog`, and reports bind blocks freely.

| `type` | Auth | Config | Reads | Default blocks |
|---|---|---|---|---|
| `mainwp` | Bearer (v2) or consumer key/secret (v1) | dashboard_url, token | Sites, available updates, plugin/theme/core inventory, abandoned plugins, SSL monitor | `MaintenanceSection`, `UptimeSection` (SSL) |
| `ga4` | Service Account JSON | property_id | Sessions, users, traffic sources, conversions, top pages (Analytics Data API `runReport`) | `TrafficSection` |
| `gsc` | Service Account JSON | site_url | Clicks, impressions, CTR, avg position, top queries/pages (`searchanalytics.query`) | `SeoSection` |
| `cloudflare` | API token (Analytics:Read) | zone_id | Requests, WAF threats blocked, cache ratio, bandwidth (GraphQL Analytics) | `SecuritySection` (threats), `PerformanceSection` |
| `crowdsec` | Console API token (or per-VPS LAPI) | console_token / lapi config | Alerts, decisions (bans), attack types | `SecuritySection` (attacks) |
| `virusdie` | via **MainWP Virusdie extension** | (none extra; uses mainwp source) | Malware scan results, firewall status | `SecuritySection` (malware) |
| `betteruptime` | Bearer token | monitor_id(s) | Uptime %, SLA, incidents, history | `UptimeSection` |
| `woocommerce` | consumer key/secret (read-only) | store_url, ck, cs | Revenue, orders, top products (`/wc/v3/reports`) | `SalesSection` |
| `imagina_audit` *(Phase 3)* | Imagina Audit REST API | audit_url, token | 7-module audit metrics + **WPVulnerability** CVEs | `AuditSection` |
| `database` *(Phase 3)* | DB credentials (read-only) | host, db, user, pass, **aggregate queries** per metric | Aggregated results of `GROUP BY` queries run **on the client's DB** | configurable / `CustomSection` |
| `endpoint` / `csv` *(Phase 3)* | token / URL | url, mapping | Any external endpoint or CSV returning data | configurable / `CustomSection` |

> **Performance golden rule (hard requirement): aggregate at the source, never pull raw rows.**
> The `database`/`endpoint`/`csv` connectors must aggregate at the origin and store only the summary.
> Pulling millions of raw rows into the app is forbidden (see §3.3).

### MainWP "work done" deltas (important)
MainWP's REST API exposes **current state**, not a historical work log. The "X updates applied this
month" value is **computed by diffing snapshots** over the period inside the report engine. This is
what replaces the Pro Reports extension. Implement a `MaintenanceDeltaCalculator` that compares the
earliest and latest snapshots in the period.

### Auth patterns summary
- Bearer token: MainWP v2, Cloudflare, Better Stack, CrowdSec Console.
- Consumer key/secret: WooCommerce, MainWP v1.
- Service Account JSON: GA4, GSC (add the SA email as a reader in the GA property / GSC property).
- Via MainWP: VirusDie.

---

## 10. Report engine — block-based & AI-built

> Design principle: **what to show is data, not code.** Connectors expose *catalogs*; reports are
> *block templates* bound to metrics; the AI builder and the editor produce/edit those templates.
> Nothing about which metrics appear in a report is hardcoded.

### 10.1 Data layer — metric bag & catalog
- Snapshots store a **normalized metric bag** keyed by metric name (`ga4.sessions`, `ga4.sessions_by_date`
  series, `ga4.top_pages` table, `cloudflare.threats_blocked`, ...).
- Each connector's **`MetricCatalog`** declares the available metrics/dimensions/units — this drives both
  the editor's binding picker and the AI builder.
- The sync fetches **only the metrics referenced by active report definitions** (`requested_metrics`).

### 10.2 Block-based templates (the report definition)
A report is an ordered list of **blocks** (`ir_report_templates.blocks` / `ir_report_definitions.blocks`).
Each block has a `type`, a `binding` to one or more metrics, and `style`. Example block:
```json
{ "id": "b1", "type": "kpi", "binding": { "source": "ga4", "metric": "sessions", "compare": "prev_period" }, "style": {} }
```
- **Default templates** ship the narrative layout from §11.3 as ready starting points — so reports stay
  clean and retention-focused by default, with full freedom to customize.
- Templates are reusable across clients; definitions can override per site.

### 10.3 Block types
`header`, `kpi`, `chart` (line/bar/area/donut), `table`, `narrative` (AI-fillable Tiptap rich text),
`healthscore` (gauge), `security_shield`, `worklog_timeline`, `image`, `divider`, `sales_summary`, `custom`.
The set is extensible (register a renderer + an editor control).

### 10.4 ReportGenerator
Resolves each block's binding against the period's snapshots → `ir_reports.resolved_blocks` (a frozen
snapshot of the rendered report). Blocks whose bound metric has no data are **gracefully hidden** (no Woo
→ the sales block disappears). Runs `HealthScoreCalculator` and the AI narrative for `narrative`/summary blocks.

### 10.5 HealthScoreCalculator
0–100 score combining uptime, security, updates-current, performance. **Re-weights when a signal is
missing** — never penalizes a client for a source they don't have. Rendered by the `healthscore` block.

### 10.6 AI report builder (`AiReportBuilder`)
The "create a report in seconds" feature. Two modes, both using the **Claude API (Anthropic)** (owner override 2026-06-18; was `gpt.imagina.cloud`). Implement behind an `AiClient` interface (model from `services.anthropic.model`):
- **Template assembly:** input = the client/site context + the **MetricCatalog of connected sources** +
  optional user prompt ("focus on SEO and security"). Output = a **validated blocks JSON** (a draft
  template) + narrative text. **The AI returns structured blocks, never free prose**, and every binding
  is **validated against the real catalog** before saving — it cannot invent metrics or bind to data
  that doesn't exist. The result opens directly in the editor for refinement.
- **Per-period narrative:** regenerates the `narrative`/executive-summary text each period from the
  resolved data, in the report's locale. Always editable before sending.

### 10.7 Rendering (single source of truth)
- A shared **block renderer** (React) renders the blocks for both the **interactive portal** and the **PDF**.
- The **PDF** is produced by **Browsershot loading the same rendered report page** (internal print route,
  one-time token), waiting for `window.reportReady === true`, then printing. Stored at `ir_reports.pdf_path`.
- The **email** carries: branded summary + PDF attachment + link to the interactive portal.

---

## 11. Frontends

Two Vite-built React SPAs, both consuming `/api/v1`. Build artifacts produced in CI; server serves static.

### 11.1 Admin panel (`resources/js/admin`)
- Clients & sites management.
- Data-source configuration (driven by each connector's `configSchema()`), with a "Test connection" action.
- **Report editor** (see 11.3), report definitions, scheduling, recipients.
- **"Generate with AI"** action → calls `AiReportBuilder`, opens the resulting draft in the editor.
- Report list → preview → edit narrative → add work logs → approve → send.
- System → Updates (current/available version, Update button, status, Rollback button).
- Heavy on TanStack Table (client-side sort/filter), TanStack Query for data, Zustand for UI state.

### 11.2 Client portal (`resources/js/portal`)
- Opened via signed `public_token` (no login).
- **Interactive dashboard** (Looker-parity): period selector, drill-down, interactive charts (Recharts).
- This same view, in print mode, is what Chromium prints to PDF.

### 11.3 Report editor (block-based, dnd-kit + Tiptap)
Follows the established Imagina pattern (Signatures, Proposals): a drag-and-drop **block editor**.
- **Block palette** → drag blocks onto the canvas (types in §10.3).
- **Binding picker** per block: choose source + metric (+ dimension) from the connected sources'
  **MetricCatalog** — this is the "free metrics" UX; nothing is hardcoded.
- **Tiptap** rich-text for `narrative` blocks, with an "AI write" action per block.
- **Style controls** (accent color from agency brand, layout) and **live preview**.
- Save as a reusable **template** or as a site-specific **definition**.

### 11.4 Shared block renderer (single source of truth)
A `BlockRenderer` component library renders each block type. **The same library powers the editor
preview, the interactive portal, and the Chromium-printed PDF** — so what you design is exactly what
the client sees and what the PDF prints. Charts via Recharts; set `window.reportReady = true` when all
blocks finish rendering (the PDF job waits on this).

### 11.5 Default template — narrative layout (top to bottom)
This is the **default block template** the AI builder and new definitions start from (fully editable).
Aesthetic: Linear/Vercel tokens, Inter, generous whitespace, single accent color from agency brand.
Each block = *a number that matters + a mini-visual + one plain-language sentence*. No untranslated jargon.

1. **Header** — agency logo, client, site, period, large **Health Score gauge** (0–100, semantic color).
2. **Executive summary** — AI-generated, editable plain Spanish.
3. **KPI cards** — Uptime %, attacks blocked, updates applied, visits, sales — each with big number + trend + vs previous period.
4. **Security** — shield visual; CrowdSec (network/WAF) + VirusDie (malware) sub-blocks; reassurance.
5. **Uptime** — uptime chart, SLA, SSL expiry.
6. **Traffic & SEO** — GA4 visits trend + GSC clicks/impressions/position; one plain line per chart.
7. **Maintenance — "What we did this month"** — dated timeline of actions + optional screenshots. **This justifies the payment.**
8. **Sales** *(if Woo)* — revenue, orders, top products.
9. **Footer / CTA** — "Your support plan is active and protecting your site."

---

## 12. Deployment, self-update & rollback

### 12.1 CI build (GitHub Actions)
On a release tag: `composer install --no-dev --optimize-autoloader` + build both SPAs → produce a
**self-contained ZIP** (includes `vendor/` and compiled assets) published as a release asset (or served
by **Imagina Updater** with license check). The server never runs composer/npm.

### 12.2 Atomic release layout (enables instant rollback)
```
/home/user/imagina-reports/
├── releases/<timestamp>_<version>/   # new, beside the old
├── shared/                            # .env, storage/, uploads — symlinked into each release
└── current -> releases/...            # symlink; OLS docroot = current/public
```
The running app **never overwrites live files**; it builds the new version beside the old and swaps the symlink.
ServerAvatar **custom webroot** points to `current/public`.

### 12.3 `UpdateManager` flow (in-app "Update" button → `RunUpdateJob`)
1. Pre-flight checks (disk, permissions, version compatibility).
2. **Backup**: `mysqldump` + keep current release intact.
3. Download the new release ZIP (verify checksum).
4. Extract to `releases/<new>`.
5. Symlink `shared` (.env, storage) into the new release.
6. `php artisan migrate --force`.
7. `config:cache` + `route:cache` + `view:cache`.
8. **Flip** `current` symlink → new release (instant).
9. `php artisan queue:restart`.
10. **Health check** `/health` → on failure, **auto-rollback**.

### 12.4 Rollback (auto on failed health check, or manual button)
- Point `current` back to the previous release (instant; old release is intact).
- Restore the DB dump (safer than `migrate:rollback` — many migrations aren't loss-reversible).
- `php artisan queue:restart`.
- Keep the last N releases for manual rollback.

### 12.5 Notes
- Run `RunUpdateJob` so it dispatches `queue:restart` only as its final step.
- Keep `/health` cheap and meaningful (DB connectivity, cache, key services).
- `deploy.sh` (used by CI/ServerAvatar Git deploy) mirrors steps 5–9 for the operator's own pushes.

---

## 13. Roadmap — build in this order

> Implement phase by phase. A phase is "done" only when its Definition of Done (§14) is met and
> `PROGRESS.md` reflects it.

### Phase 1 — Core engine + immediate value
- [ ] Laravel skeleton, Sanctum, multi-tenant scaffolding (agency scope), base migrations.
- [ ] `DataSourceConnector` interface + `ConnectorRegistry` + `MetricCatalog` + `MetricSet` (metric bag).
- [ ] Snapshot pipeline: `SyncSourceJob`, `SyncService`, `ir_metric_snapshots`.
- [ ] Connectors: **MainWP, GA4, Search Console** (+ `MaintenanceDeltaCalculator`).
- [ ] Block model + `BlockRenderer` library + default narrative template (§11.5).
- [ ] `ReportGenerator` (resolve blocks) + `HealthScoreCalculator`.
- [ ] Report React page + portal route + Browsershot PDF.
- [ ] Admin SPA: clients/sites, data-source config, manual report generation + preview.
- [ ] API v1 for the above. Manual generation only.

### Phase 2 — Editor, AI & full 360 + automation
- [ ] **Block-based report editor** (dnd-kit + Tiptap, binding picker from `MetricCatalog`) + reusable templates.
- [ ] **`AiReportBuilder`** — AI template assembly (validated against catalog) + per-period narrative.
- [ ] Connectors: **Cloudflare, CrowdSec, Better Stack, VirusDie, WooCommerce**.
- [ ] Scheduling (`ir_schedules`) + recurring generation + branded email delivery.
- [ ] White-label per agency + i18n (ES/EN/PT-BR) + work logs + historical archive.
- [ ] Client portal interactivity (period selector, drill-down).
- [ ] Self-updater (`UpdateManager`) + GitHub Actions release pipeline + rollback.

### Phase 3 — Intelligence & differentiation
- [ ] **Imagina Audit + WPVulnerability** connector + `AuditSection`.
- [ ] **Database / CSV / endpoint connector** (`type = 'database'`) — aggregate-at-source queries
      configurable per client. Positioned for clients over-paying for Power BI licenses who only need
      branded, scheduled dashboards (not a BI engine).
- [ ] Anomaly detection (traffic drop / attack spike) → internal alerts + webhooks.
- [ ] Upsell-opportunity detector.
- [ ] Advanced comparisons + multi-client trends dashboard.

---

## 14. Testing & Definition of Done

- **Every connector**: unit tests with mocked HTTP (happy path + failed/partial). Never hit live APIs in tests.
- **Report engine**: tests for section composition, missing-source re-weighting, delta calculation.
- **API**: feature tests per endpoint (auth, tenant isolation, validation).
- **Tenant isolation test**: assert agency A can never read agency B's data.
- **PHPStan max + Pint** clean.
- A phase is **done** when: all its tasks checked in `PROGRESS.md`, tests green, PHPStan clean,
  and the feature is demoable end-to-end.

---

## 15. `PROGRESS.md` template (Claude Code creates & maintains this)

Create `PROGRESS.md` at the repo root on first run, using this structure. **Update it every session.**

```markdown
# Imagina Reports — PROGRESS

## Where I left off (read me first)
<One paragraph: last thing done, current state, exactly what to do next.>

## Current phase
Phase 1 — Core engine

## Current task
<The single task in progress.>

## Next up
- <next task>
- <next task>

## Completed
- [x] (YYYY-MM-DD) <task> — <commit hash>

## Decisions log
- (YYYY-MM-DD) <decision> — <rationale>

## Open questions / blockers
- <anything ambiguous in CLAUDE.md, or waiting on the operator>

## Environment notes
- <connector credentials location, test accounts, gotchas discovered>
```

---

## 16. Appendix — key env vars (illustrative)

```
APP_ENV=production
APP_URL=https://reports.imagina.cloud
DB_CONNECTION=mariadb
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
ANTHROPIC_API_KEY=...           # AI report builder / narrative (Claude API)
ANTHROPIC_MODEL=claude-sonnet-4-6
UPDATER_CHANNEL=stable          # stable/beta
UPDATER_SOURCE=imagina-updater  # or github-releases
BROWSERSHOT_CHROME_PATH=/usr/bin/chromium
```

---

*End of CLAUDE.md. Remember §0: read and update `PROGRESS.md` every session.*
