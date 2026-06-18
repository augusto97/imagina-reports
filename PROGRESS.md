# Imagina Reports — PROGRESS

> Living state file. **Claude Code: read this and `CLAUDE.md` at the start of every session, and
> update this file at the end of every session** (see `CLAUDE.md` §0). This file is what lets a brand-new
> conversation resume in under a minute.

---

## Where I left off (read me first)
**Phase 1 complete; Phase 2 underway — P2·1…P2·4 DONE.** Editor (1), AI builder (2), all connectors (3),
and now **scheduling + recurring generation + branded email** (4). `ir_schedules`/`Schedule` (cadence
monthly/weekly + `next_run_at`, with `ScheduleCadence::periodFor/nextRun`) and `ir_report_deliveries`/
`ReportDelivery`. The `reports:run-schedules` command (wired hourly in `routes/console.php`) → `ScheduleRunner`
finds due schedules, dispatches `RunScheduledReportJob` (generates the just-ended period's report via
`ReportGenerator`, then queues delivery) and advances `next_run_at`. `DeliverReportJob` → `DeliveryService`
renders the PDF (`ReportPdfService`) and emails the branded `ReportReadyMail` (summary + PDF + portal link)
to the definition's recipients, recording each attempt. Schedules API (`GET/POST /api/v1/schedules`). All
queue-safe + tenant-bound. **107 PHP tests green** (delivery via `Mail::fake`+`FakePdfRenderer`, runner,
command, API isolation), **PHPStan max clean, Pint clean.**
**Next action: Phase 2 · White-label per agency + i18n (ES/EN/PT-BR) + work logs + historical archive**
(`ir_report_work_logs`; agency branding into the report page/email; localization; reports list/archive).
Then portal interactivity, self-updater.

---

## Current phase
**Phase 2 — Editor, AI & full 360 + automation** (Phase 1 complete)

## Current task
**Phase 2 · White-label + i18n + work logs + historical archive** (not started, CLAUDE.md §5/§6/§11.5).
`ir_report_work_logs` (+ `WorkLog` model + `POST /api/v1/reports/{report}/work-logs`, §8) feeding the
`worklog_timeline` block. Agency white-label: pass `brand_color`/`logo_path` through to the report page
(`ReportResource` already exposes `agency`) and the email; render the accent. i18n: app + report content
localized (ES default; EN, PT-BR) via Laravel localization + the report's locale. Historical archive:
reports list/filtering already exists (`GET /api/v1/reports`) — add period filters / an archive view.

## Phase 2 — progress
- [x] (2026-06-18) **P2·1 — Block editor** (dnd-kit + Tiptap) + templates CRUD API + metric-catalog endpoint. — 21fa283
- [x] (2026-06-18) **P2·2 — AiReportBuilder** (Claude API; validated against catalog) + "Generar con IA" + endpoint. — 77e9b53
- [x] (2026-06-18) **P2·3 — Remaining connectors** (Cloudflare, CrowdSec, Better Stack, Virusdie, WooCommerce). — 65e643b
- [x] (2026-06-18) **P2·4 — Scheduling + recurring generation + branded email** (`ir_schedules`, `ir_report_deliveries`). — _commit pending_
- [ ] **(current)** White-label per agency + i18n (ES/EN/PT-BR) + work logs + historical archive.
- [ ] White-label per agency + i18n (ES/EN/PT-BR) + work logs + historical archive.
- [ ] Client portal interactivity (period selector, drill-down).
- [ ] Self-updater (`UpdateManager`) + GitHub Actions release pipeline + rollback.

### P2·4 — Scheduling + recurring generation + branded email ✅ DONE (2026-06-18)
- [x] `ir_schedules`/`Schedule` (+ `ScheduleCadence` period/next), `ir_report_deliveries`/`ReportDelivery` (+ `DeliveryChannel`/`DeliveryStatus`); factory.
- [x] `reports:run-schedules` command (hourly via `routes/console.php`) → `ScheduleRunner::dispatchDue` → `RunScheduledReportJob` → generate + `DeliverReportJob`.
- [x] `DeliveryService` (PDF + branded `ReportReadyMail` + records) ; `GET/POST /api/v1/schedules` (`ScheduleController`).
- [x] Tests: delivery (Mail::fake + FakePdfRenderer), runner due/not-due, command, schedule API + isolation. 107 tests green; PHPStan max + Pint clean.

### P2·3 — Remaining connectors ✅ DONE (2026-06-18)
- [x] `Cloudflare` (GraphQL), `CrowdSec` (alerts/decisions), `BetterUptime` (SLA), `Virusdie` (via MainWP ext.), `WooCommerce` (`/wc/v3/reports`).
- [x] Shared `App\Connectors\Support\ParsesValues` trait; all registered in `ConnectorServiceProvider`.
- [x] Tests: one `Http::fake` happy path each + a failed-HTTP case + registration covers all 8. 101 tests green; PHPStan max + Pint clean.

### P2·2 — AiReportBuilder ✅ DONE (2026-06-18)
- [x] `App\Ai\AiClient` + `AnthropicAiClient` (Claude Messages API, `config('services.anthropic')`); bound in `AppServiceProvider`.
- [x] `AiReportBuilder::assembleTemplate` (catalog-constrained, validated, drops invented bindings → `AiReportException`) + `narrative()`.
- [x] `POST /api/v1/sites/{site}/ai-template` (`AiTemplateController`, 422 on failure); editor "Generar con IA" button loads the draft.
- [x] Tests (FakeAiClient, no live API): catalog drop, unparseable→throw, invalid layout→throw, endpoint 200/422. 95 tests green; PHPStan max + Pint clean; TS clean.

### P2·1 — Block editor ✅ DONE (2026-06-18)
- [x] `report-templates` CRUD (`ReportTemplateController`) + `ValidatesBlocks` FormRequest trait (server-side block validation → 422).
- [x] `PUT report-definitions/{id}` (edit blocks); `GET sites/{site}/metric-catalog` (`MetricCatalogController`) for the binding picker.
- [x] Editor frontend (`resources/js/admin/editor`): dnd-kit sortable canvas + palette, binding picker, Tiptap narrative, live `BlockList` preview, save-as-template.
- [x] Tests: template store valid/invalid(422)/update/isolation + metric-catalog. 90 tests green; PHPStan max + Pint clean; TS clean.

### Task 12 — Admin SPA ✅ DONE (2026-06-18)
- [x] `GET /api/v1/connectors` (key/label/config_schema) to drive the data-source form; feature test.
- [x] Admin SPA (`resources/js/admin`): Zustand nav; TanStack Query hooks; generic TanStack-Table `DataTable`; RHF+Zod forms; UI primitives.
- [x] Screens: Clients, Sites (→ pick site), Data Sources (configSchema-driven form + Test connection), Reports (definition create + generate + public preview link).
- [x] 85 tests green; PHPStan max + Pint clean; TS typecheck/lint/build clean.

### Task 11 — API v1 CRUD + manual generation ✅ DONE (2026-06-18)
- [x] Controllers (Api/V1): Client/Site/DataSource/ReportDefinition/Report; `FormRequest`s; resources (flat, credentials hidden).
- [x] Routes under `auth:sanctum`+`tenant`: clients, sites, sites/{site}/data-sources, data-sources/{ds}/test, report-definitions, reports, reports/generate, reports/{report}/approve.
- [x] `GenerateReportJob` (queue-safe, tenant-bound) wrapping `ReportGenerator`.
- [x] **Middleware priority**: `BindTenant` before `SubstituteBindings` (route-model binding is agency-scoped). `DataSource` default `status` attribute.
- [x] Tests: auth, CRUD, §14 isolation across bound routes, test-connection (Http::fake), generate→report, approve. 83 tests green; PHPStan max + Pint clean.

### Task 10 — Report page + public endpoint + PDF ✅ DONE (2026-06-18)
- [x] `GET /api/v1/public/reports/{token}` (`PublicReportController` + `ReportResource`, no auth, scope-bypassing); `JsonResource::withoutWrapping()`.
- [x] React `report` entry renders `resolved_blocks` via shared `BlockList`, sets `window.reportReady`; web route `report.public` serves it.
- [x] `PdfRenderer` interface + `BrowsershotPdfRenderer` + `ReportPdfService` (→ `pdf_path`); `FakePdfRenderer` for tests.
- [x] Tests: public endpoint (found/404/no-auth) + PDF service (renders public URL, stores). 73 tests green; PHPStan max + Pint clean; TS clean.

### Task 9 — ReportGenerator + HealthScoreCalculator ✅ DONE (2026-06-18)
- [x] Tables/models: `ir_sites`/`Site`, `ir_report_templates`/`ReportTemplate`, `ir_report_definitions`/`ReportDefinition`, `ir_reports`/`Report` (+ `ReportStatus`); factories.
- [x] `ReportGenerator`: snapshot→bag resolution, graceful hide (§10.4), maintenance-delta `updates_applied`, persists draft `Report` with `public_token`.
- [x] `HealthScoreCalculator`: weighted uptime/updates/security/performance with missing-signal re-weighting (§10.5).
- [x] Tests: generator resolve+hide + delta KPI + health on block; health calc re-weighting. 70 tests green; PHPStan max + Pint clean.

### Task 8 — Block model + BlockRenderer + default template ✅ DONE (2026-06-18)
- [x] PHP: `BlockType` enum, `Block` VO, `BlocksValidator` (+ `BlockValidationException`); `DefaultTemplate` (§11.5 layout as valid blocks JSON).
- [x] React (`resources/js/shared/blocks`): `types.ts` + `BlockRenderer`/`BlockList` (renderer per type, Recharts charts) — single source of truth for portal + PDF.
- [x] Tests: `BlocksValidator` (valid parse, error collection, data-block binding rule) + `DefaultTemplate` (validates, order, unique ids). 63 tests green; PHPStan max + Pint clean; TS typecheck/lint/build clean.

### Task 7 — Search Console (GSC) connector ✅ DONE (2026-06-18)
- [x] Generalized Google auth to `App\Connectors\Google\GoogleTokenProvider` (+ `ServiceAccountTokenProvider`); refactored GA4 onto it.
- [x] `GscConnector`: `gsc.*` catalog; totals (clicks/impressions/ctr/position) in one query + top_queries/top_pages tables; defensive parse, ok/partial/failed; registered.
- [x] Tests: catalog, totals single-query, table parse, partial failure, missing site_url, auth failure + registration. 55 tests green; PHPStan max + Pint clean.

### Task 6 — GA4 connector ✅ DONE (2026-06-18)
- [x] `Ga4Connector` (Service Account via the shared `GoogleTokenProvider`); `ga4.*` catalog (scalar/series/table); `runReport` per metric, defensive parse, ok/partial/failed; registered.

### Task 5 — MainWP connector ✅ DONE (2026-06-18)
- [x] `MainWpConnector` (configSchema dashboard_url+token, `mainwp.*` catalog, defensive aggregated `fetch()`, testConnection) registered in `ConnectorServiceProvider`.
- [x] `MaintenanceDeltaCalculator` + `MaintenanceDelta` VO: earliest-vs-latest snapshot diff, "updates applied" = clamped reduction in pending updates (§9).
- [x] Tests: MainWP via `Http::fake` (aggregate, requested-metrics filter, failed HTTP, testConnection) + delta calc (between, clamp, forDataSource boundary, null<2). 41 tests green; PHPStan max + Pint clean.

### Task 4 — Snapshot pipeline ✅ DONE (2026-06-18)
- [x] `ir_metric_snapshots` migration + `MetricSnapshot` model (agency-scoped, `belongsTo` DataSource, unique per source+period).
- [x] `SyncService` (resolve connector → fetch → upsert snapshot, idempotent; records source status/last_synced_at/last_error).
- [x] `SyncSourceJob` (loads source w/o AgencyScope, runs inside `TenantContext::actingAs` — queue-safe).
- [x] Feature tests: persist+ok, idempotent re-sync, failed-fetch→error, job sync w/o pre-bound tenant. 31 tests green; PHPStan max + Pint clean.

### Task 3 — Connector contracts ✅ DONE (2026-06-18)
- [x] `DataSourceConnector` interface (§7) + `ConnectorRegistry` (singleton via `ConnectorServiceProvider`).
- [x] Value objects: `MetricCatalog`/`MetricDefinition`/`MetricType`, `MetricSet`/`MetricSetStatus`, `ConnectionResult`, `Period`, `ConfigField`/`ConfigFieldType`.
- [x] Enums `DataSourceType` (extensible) + `DataSourceStatus`; `DataSource` model + `ir_data_sources` migration (encrypted credentials, agency-scoped; `site_id` nullable, FK deferred).
- [x] Unit tests (Period, MetricCatalog, MetricSet, ConnectorRegistry w/ FakeConnector) + DataSource feature test (encryption + scope + registry resolve). 26 tests green; PHPStan max + Pint clean.

### Task 2 — Multi-tenant scaffolding ✅ DONE (2026-06-18)
- [x] `ir_agencies` (+ `Agency` model, tenant root) migration owning the first migration slot for FK order.
- [x] `ir_users`: `User` moved to table `ir_users`, `agency_id` FK + `role` enum (`App\Enums\UserRole`), `HasApiTokens`.
- [x] `ir_clients` (+ `Client` model) as the first agency-scoped domain entity.
- [x] Tenancy mechanism: `TenantContext` singleton, `AgencyScope` global scope, `BelongsToAgency` trait (auto-stamps `agency_id`), `BindTenant` middleware (alias `tenant`, after `auth:sanctum`).
- [x] §14 tenant-isolation feature test (A can't read B) + middleware test + auto-stamp test. 9 tests green; PHPStan max + Pint clean.

### Task 1 — Project skeleton & tooling baseline ✅ DONE (2026-06-18)
- [x] Laravel 11 (11.54) scaffolded; PHP pinned `^8.3`; `declare(strict_types=1)` enforced by Pint.
- [x] Installed: Sanctum (+`install:api`, `/api/v1` prefix), Horizon, Browsershot, spatie/laravel-permission,
      google/apiclient, Larastan (dev). PHPStan **level max** clean; Pint clean.
- [x] `.env`/`.env.example` target MariaDB + Redis (queue/cache/sessions = redis); tests use sqlite/array/sync.
- [x] `composer run stan` / `composer pint` / `composer test` scripts; `.github/workflows/ci.yml` (PHP lint+stan+test, Node typecheck+lint+build both SPAs).
- [x] Two Vite 5 + React 18 + TS SPAs (`admin`, `portal`) with locked stack; Tailwind prefix `ir-`; Inter local; `cn()` util + design tokens. `npm run build` produces both bundles.
- [x] `/api/v1/health` liveness route (+ feature test) for the updater health check.

## Next up (Phase 1, in order)
1. ~~Multi-tenant scaffolding~~ ✅ done (Task 2).
2. ~~`DataSourceConnector` interface + `ConnectorRegistry` + `MetricCatalog` + `MetricSet`~~ ✅ done (Task 3); `ir_data_sources` + `DataSource` model also landed here.
3. ~~Snapshot pipeline: `ir_metric_snapshots` + `MetricSnapshot`, `SyncSourceJob`, `SyncService`~~ ✅ done (Task 4).
4. ~~Connector: **MainWP** (+ `MaintenanceDeltaCalculator`)~~ ✅ done (Task 5).
5. ~~Connector: **GA4** (Service Account; catalog-driven, aggregated)~~ ✅ done (Task 6).
6. ~~Connector: **Search Console**~~ ✅ done (Task 7).
7. ~~Block model + `BlockRenderer` React library + default narrative template (§11.5)~~ ✅ done (Task 8).
8. ~~`ReportGenerator` (resolve blocks against snapshots) + `HealthScoreCalculator`~~ ✅ done (Task 9).
9. ~~Report React page + portal route + Browsershot PDF~~ ✅ done (Task 10).
10. ~~API v1 endpoints (CRUD + manual generation)~~ ✅ done (Task 11).
11. ~~Admin SPA: clients/sites, data-source config, manual generation + preview~~ ✅ done (Task 12).
12. **Phase 1 DoD:** tests green ✅, PHPStan max clean ✅, end-to-end demo of a manual report — _live demo pending operator env (MariaDB/Redis/Chromium)._
4. Connector: **MainWP** (+ `MaintenanceDeltaCalculator` for "work done" deltas).
5. Connector: **GA4** (Service Account; catalog-driven, aggregated).
6. Connector: **Search Console** (Service Account; catalog-driven).
7. Block model + `BlockRenderer` React library + default narrative template (`CLAUDE.md` §11.5).
8. `ReportGenerator` (resolve blocks against snapshots) + `HealthScoreCalculator`.
9. Report React page + portal route + Browsershot PDF (single source of truth).
10. Admin SPA: clients/sites, data-source config (driven by `configSchema()`), manual generation + preview.
11. API v1 endpoints for all of the above (manual generation only).
12. Phase 1 Definition of Done: tests green, PHPStan max clean, end-to-end demo of a manual report.

## Completed
- [x] (2026-06-18) **Phase 1 · Task 1 — Project skeleton & tooling baseline.** Laravel 11 + Sanctum/API v1,
      Horizon, Browsershot, laravel-permission, google/apiclient; PHPStan max + Pint clean; 3 tests green;
      two Vite 5/React 18 SPAs (admin+portal) with the locked stack; CI workflow building both SPAs. — 99135e8
- [x] (2026-06-18) **Phase 1 · Task 2 — Multi-tenant scaffolding.** `ir_agencies`/`ir_users`/`ir_clients`
      migrations + `Agency`/`User`/`Client` models; `UserRole` enum; `TenantContext` + `AgencyScope` +
      `BelongsToAgency` trait + `BindTenant` middleware; §14 isolation test. 9 tests green; PHPStan max + Pint clean. — 4d27d0b
- [x] (2026-06-18) **Phase 1 · Task 3 — Connector contracts.** `DataSourceConnector` interface + `ConnectorRegistry`
      (+ `ConnectorServiceProvider`); `MetricCatalog`/`MetricDefinition`/`MetricType`, `MetricSet`/`MetricSetStatus`,
      `ConnectionResult`, `Period`, `ConfigField`/`ConfigFieldType`; `DataSourceType`/`DataSourceStatus` enums;
      `DataSource` model + `ir_data_sources` (encrypted credentials). 26 tests green; PHPStan max + Pint clean. — 4dc1689
- [x] (2026-06-18) **Phase 1 · Task 4 — Snapshot pipeline.** `ir_metric_snapshots` + `MetricSnapshot` model;
      `SyncService` (idempotent upsert) + `SyncSourceJob` (queue-safe, tenant-bound). 31 tests green; PHPStan max + Pint clean. — 4a5bd82
- [x] (2026-06-18) **Phase 1 · Task 5 — MainWP connector.** `MainWpConnector` (v2 Bearer, aggregated defensive
      `fetch()`) registered in the provider; `MaintenanceDeltaCalculator` + `MaintenanceDelta` for work-done deltas.
      41 tests green; PHPStan max + Pint clean. — 1f66951
- [x] (2026-06-18) **Phase 1 · Task 6 — GA4 connector.** `Ga4Connector` (Service Account via `Ga4TokenProvider`),
      `ga4.*` catalog (scalar/series/table), `runReport` defensive parse, ok/partial/failed; registered in the provider.
      49 tests green; PHPStan max + Pint clean. — 7095021
- [x] (2026-06-18) **Phase 1 · Task 7 — GSC connector.** Generalized Google auth to `GoogleTokenProvider`
      (+ `ServiceAccountTokenProvider`), refactored GA4 onto it; `GscConnector` (`gsc.*`: totals + top queries/pages).
      55 tests green; PHPStan max + Pint clean. — f9adb53
- [x] (2026-06-18) **Phase 1 · Task 8 — Block model + BlockRenderer + default template.** PHP `BlockType`/`Block`/
      `BlocksValidator` + `DefaultTemplate` (§11.5); React `BlockRenderer`/`BlockList` (Recharts), single source of truth.
      63 tests green; PHPStan max + Pint clean; TS typecheck/lint/build clean. — 417779f
- [x] (2026-06-18) **Phase 1 · Task 9 — ReportGenerator + HealthScoreCalculator.** `ir_sites`/report tables + models;
      `ReportGenerator` (resolve, graceful hide, delta-wired `updates_applied`) + `HealthScoreCalculator` (re-weighting).
      70 tests green; PHPStan max + Pint clean. — 06f490b
- [x] (2026-06-18) **Phase 1 · Task 10 — Report page + public endpoint + PDF.** `PublicReportController`/`ReportResource`
      (`GET /api/v1/public/reports/{token}`), React `report` SPA (BlockList + `window.reportReady`), `report.public` web route,
      `PdfRenderer`/`BrowsershotPdfRenderer`/`ReportPdfService`. 73 tests green; PHPStan max + Pint clean; TS clean. — f59d185
- [x] (2026-06-18) **Phase 1 · Task 11 — API v1 CRUD + manual generation.** Client/Site/DataSource/ReportDefinition/Report
      controllers + FormRequests + flat resources; `GenerateReportJob`; **BindTenant before SubstituteBindings** (binding isolation).
      83 tests green; PHPStan max + Pint clean. — 623841b
- [x] (2026-06-18) **Phase 1 · Task 12 — Admin SPA.** `GET /api/v1/connectors` endpoint; admin SPA (Zustand nav, TanStack
      Query/Table, RHF+Zod) — Clients/Sites/DataSources(configSchema-driven + test)/Reports(generate + preview).
      85 tests green; PHPStan max + Pint clean; TS clean. — 5e06106

---

## Decisions log
> History of locked decisions so any new conversation has full context. Append new ones with date + rationale.

- (2026-06-18) **Product name: Imagina Reports.** Working name, confirmed by owner.
- (2026-06-18) **Environment: Hetzner VPS managed by ServerAvatar** (stack OLS, LSPHP 8.3/8.4, MariaDB, Redis).
  Rationale: this app polls many external APIs on schedule for many clients — hostile to shared hosting
  (exec-time limits, throttling, capped cron). VPS is the operator's domain; the "installable anywhere"
  philosophy belongs to the WordPress plugins, not to this operator-run platform.
- (2026-06-18) **API-first** (REST `/api/v1` + Sanctum), multi-tenant, with webhooks. Rationale: owner wants
  it expandable and possibly commercial (other agencies / integrations / future mobile app). Dual auth:
  cookie for own SPAs, API tokens for third parties.
- (2026-06-18) **Two React 18 SPAs** (admin + interactive client portal), built in GitHub Actions; **Node.js
  NOT installed on the server**. Rationale: reuse owner's React stack; portal gives Looker-parity interactivity.
- (2026-06-18) **Redis + persistent worker + Horizon** (available in all ServerAvatar stacks).
- (2026-06-18) **PDF via headless Chromium (Browsershot)** printing the same React report page → single source
  of truth (one `BlockRenderer` for editor, portal, and PDF). VPS isolation contains Chromium RAM spikes.
- (2026-06-18) **Block-based report model** with a **dnd-kit + Tiptap editor** (owner's established pattern
  from Imagina Signatures/Proposals). Reports are blocks bound to metrics, not fixed sections.
- (2026-06-18) **Metrics are NOT hardcoded** — connectors expose a `MetricCatalog`; editor + AI pick freely.
- (2026-06-18) **`AiReportBuilder`** creates a full draft (validated block JSON, constrained to the real
  catalog — cannot invent data) + per-period narrative, via the **Claude API** (see override entry below). "Create a report in seconds."
- (2026-06-18) **Performance golden rule: aggregate at the source, never pull raw rows.** This is why GA4's
  millions of visits never touch the app — GA4/GSC/Cloudflare/Woo aggregate server-side. The `database`
  connector must `GROUP BY` on the client's DB. NOT a BI engine; do not try to replicate Power BI.
- (2026-06-18) **Atomic releases (symlink) + in-app Update/Rollback** (`UpdateManager`); CI builds a
  self-contained ZIP (vendor + compiled assets). Reuses the **Imagina Updater** mechanism.
- (2026-06-18) **Replaces Modular DS + MainWP Pro Reports.** Maintenance "work done" is computed by diffing
  MainWP snapshots (its REST API exposes current state, not a historical work log).
- (2026-06-18) **VirusDie via the MainWP Virusdie extension**, not VirusDie's partner API (avoids the contract).
- (2026-06-18) **Spec language: English** (for Claude Code). Client-facing report content is localized (ES default).
- (2026-06-18) **AI provider = Claude API (Anthropic)** — OWNER OVERRIDE of CLAUDE.md §2/§10.6/§16, which named
  `gpt.imagina.cloud` (owner confirmed that service is not used in this project). Env `ANTHROPIC_API_KEY` /
  `ANTHROPIC_MODEL` (default `claude-sonnet-4-6`, configurable), `config('services.anthropic')`. `AiReportBuilder`
  (Phase 2) will sit behind an `AiClient` interface. CLAUDE.md §2/§10.6/§16 updated to match.
- (2026-06-18) **Dev env runs PHP 8.4, but `composer.json` pins `^8.3`** (the locked target). 8.4 is backward-compatible for local work.
- (2026-06-18) **Vite pinned to 5** (`^5.4`) to honor the locked frontend stack, even though `laravel new` shipped Vite 6.
- (2026-06-18) **PHPStan analyses `app`/`bootstrap/app.php`/`database`/`routes` at level max; `config/` is excluded.**
  Rationale: the framework's `config/*.php` are declarative `env()`-based defaults (typed `bool|string`) that produce
  only false positives, not domain signal. `checkModelProperties` left off for now (it rewrites Factory return types
  and fights Laravel's factories); revisit once models/factories exist.
- (2026-06-18) **API prefix is `/api/v1`** via `withRouting(apiPrefix: 'api/v1')`; added `/api/v1/health` as the
  updater's liveness probe (CLAUDE.md §12.5), separate from Laravel's `/up`.
- (2026-06-18) **Tests run on sqlite in-memory + array cache/session + sync queue** (`phpunit.xml`); production `.env`
  targets MariaDB + Redis. Keeps CI/tests hermetic without external services.
- (2026-06-18) **Roles: simple `role` enum on `ir_users`** (owner/admin/collaborator) per §5 — owner-confirmed.
  spatie/laravel-permission stays installed but reserved for finer-grained per-agency permissions later, not the 3 base roles.
- (2026-06-18) **The default `users` table is renamed to `ir_users`** (all domain tables are `ir_`-prefixed, §5). The
  default users migration was renamed to `0001_01_01_000100_create_ir_users_table.php` and a new
  `0001_01_01_000000_create_ir_agencies_table.php` owns the first slot so the `agency_id` FK resolves in order.
  Framework tables `password_reset_tokens`/`sessions` keep their names (not domain tables).
- (2026-06-18) **Tenancy = TenantContext singleton + AgencyScope global scope + `BelongsToAgency` trait + `BindTenant`
  middleware.** The scope is a no-op until a tenant is bound, so framework boot / auth / CLI / seeders run unscoped.
  The trait auto-stamps `agency_id` on create. `BindTenant` (alias `tenant`) binds the tenant from the authed user and
  MUST run after `auth:sanctum`. **`User` is intentionally NOT auto-scoped** (the auth guard must resolve users before a
  tenant is bound); user listings are scoped explicitly in controllers later.
- (2026-06-18) **`Client` (`ir_clients`) is the first agency-scoped domain model**, added now as the canonical example
  to validate tenant isolation (§14). Sites/data-sources/reports hang off it in later tasks.
- (2026-06-18) **`User` gained `HasApiTokens`** (Sanctum) for the dual-auth model (§2: cookie for SPAs, API tokens for third parties).
- (2026-06-18) **`ir_data_sources` + `DataSource` model were pulled forward into Task 3** (connector contracts), because the
  `DataSourceConnector` interface type-hints the model. Schema is straight from §5 (not invented). `ir_metric_snapshots` +
  `SyncSourceJob` + `SyncService` remain in the next task. **`site_id` is a nullable column with no FK** until `ir_sites` exists.
- (2026-06-18) **Connector value objects live in `App\Connectors`** (per §4 layout); the interface in `App\Connectors\Contracts`.
  `MetricSet` is the normalized metric bag with `ok()/partial()/failed()` factories so connectors never throw on API errors (§7).
  `DataSource.credentials` uses the `encrypted:array` cast and is in `$hidden` — never logged (§6).
- (2026-06-18) **`ConnectorRegistry` is a deferred singleton** (`ConnectorServiceProvider`); concrete connectors will register
  themselves there as they are implemented (Tasks 5–7+).
- (2026-06-18) **Snapshot payload = `MetricSet::toArray()`** (`{status, error, metrics}`); the snapshot also has a `status`
  column (cast to `MetricSetStatus`) for querying. Idempotency via a **unique index `(data_source_id, period_start, period_end)`**
  + `updateOrCreate`. `SyncService` sets `agency_id` explicitly from the source (robust whether or not a tenant is bound).
- (2026-06-18) **`SyncSourceJob` is queue-safe**: it loads the `DataSource` with `withoutGlobalScope(AgencyScope)` (no tenant on
  the worker) and wraps the sync in `TenantContext::actingAs($source->agency_id, …)`, restoring the previous context after.
- (2026-06-18) **MainWP connector targets the v2 REST API with a Bearer token** (owner-chosen), matching the
  `dashboard_url + token` config. `fetch()` parses **defensively** (tolerant of missing keys → 0) and aggregates at the
  source. The exact v2 endpoint paths/field names are an assumption to validate against a live dashboard (Open questions).
- (2026-06-18) **"Updates applied" is a proxy = `max(0, pending_before − pending_after)`** (reduction in pending updates between
  the period's earliest and latest snapshots). Precise per-item inventory diffing is a future refinement (Open questions).
  Note this implies snapshots are captured at a finer cadence (e.g. daily) so a report period contains ≥2 boundary snapshots.
- (2026-06-18) **GA4 auth is abstracted behind `Ga4TokenProvider`** (default `GoogleServiceAccountTokenProvider` using
  `google/auth`'s `ServiceAccountCredentials`, scope `analytics.readonly`) so tests stub it (no Google network). `fetch()`
  calls the Analytics Data API `runReport` over Http (mockable). GA4 metric values are treated as integer counts.
- (2026-06-18) **The `ga4.*` catalog/metric mapping is connector-defined** (sessions→`sessions`, users→`totalUsers`,
  conversions→`conversions`, page_views→`screenPageViews`; series by `date`; tables by `pagePath`/`sessionDefaultChannelGroup`).
  This set is reasonable but not enumerated in the spec — extend as report needs grow.
- (2026-06-18) **Google auth is shared & scope-parameterized** (`App\Connectors\Google\GoogleTokenProvider`,
  default `ServiceAccountTokenProvider`). GA4 was refactored onto it (was a GA4-specific provider). GSC reuses it with the
  `webmasters.readonly` scope. Tests stub it via `FakeGoogleTokenProvider` (no Google network).
- (2026-06-18) **GSC fetches the four totals (clicks/impressions/CTR/position) in a single no-dimension
  `searchanalytics.query`** (efficient), and one query per table (`gsc.top_queries` by `query`, `gsc.top_pages` by `page`).
  clicks/impressions are ints; ctr/position are floats. The `gsc.*` catalog is connector-defined (not enumerated in spec).
- (2026-06-18) **Block schema is connector-of-the-frontend's contract**: a block is `{id, type, binding?, props, style}`.
  `BlocksValidator` enforces list shape, unique non-empty string ids, known `BlockType`, and a metric binding
  (`source`+`metric`) for data blocks (kpi/chart/table/sales_summary); other types' bindings are optional. The exact
  per-type `props` shape is left flexible for now (validated loosely) — tighten per block as the editor (Phase 2) lands.
- (2026-06-18) **One shared React `BlockRenderer`/`BlockList`** in `resources/js/shared/blocks` (Recharts for charts) is the
  single source of truth for the portal and the Chromium PDF (§11.4). It takes a `Block` + resolved `data` (by block id);
  binding→data resolution is the ReportGenerator's job (Task 9). Frontend gate stays typecheck+lint+build (no JS unit runner yet).
- (2026-06-18) **`recharts` added** to the frontend deps for charts (locked stack §11.4).
- (2026-06-18) **`ir_sites` was created in Task 9** (overdue): `Site` (agency-scoped, belongsTo Client, hasMany DataSource).
  `ir_data_sources.site_id` stays a plain nullable column with **no DB-level FK** (sqlite can't ALTER-ADD a FK; the column
  predates the table). Report definitions target a site; the generator finds a site's data sources by `site_id`.
- (2026-06-18) **Block binding resolution convention:** a binding `{source, metric}` resolves to the metric bag key
  `"{source}.{metric}"` (e.g. source `ga4` + metric `sessions` → `ga4.sessions`). `resolved_blocks` is stored as
  `{blocks: [...visible...], data: {blockId: value}}` — exactly the `BlockList` props.
- (2026-06-18) **`mainwp.updates_applied` is a generator-computed metric** (from `MaintenanceDeltaCalculator`), injected into
  the mainwp bag at GENERATE time; the default template's "updates applied" KPI binds to it. It is NOT in the connector
  catalog (the connector can't fetch it). Needs ≥2 mainwp snapshots in the period, else the KPI hides.
- (2026-06-18) **Health score weights** (re-weighted over present signals): uptime .30, updates .25, security .25,
  performance .20. Heuristics: each pending update −5; expiring SSL → security 60; cloudflare cache ratio ×100. No signals → 100.
- (2026-06-18) **API resources are unwrapped** (`JsonResource::withoutWrapping()` in `AppServiceProvider::boot`) — responses are a
  flat top-level object, which is what the SPAs (axios `response.data`) consume directly. Assert top-level paths in tests.
- (2026-06-18) **PDF is behind a `PdfRenderer` interface** (`BrowsershotPdfRenderer` in prod, `FakePdfRenderer` in tests; bound in
  `AppServiceProvider`). `ReportPdfService` renders the report's own `report.public` URL (single source of truth) and stores to
  `pdf_path`. The public report endpoint bypasses the AgencyScope (`withoutGlobalScopes`) — the signed `public_token` is the capability.
- (2026-06-18) **Frontend now has 3 entries** (admin, portal, **report**) in `vite.config.ts`. The report page sets
  `window.reportReady = true` on data load (success OR error) so Browsershot never hangs on an empty/failed report.
- (2026-06-18) **API built before the admin SPA** (roadmap lists SPA first): the SPA consumes the API, and API-first is locked (§2).
- (2026-06-18) **Middleware priority puts `BindTenant` before `SubstituteBindings`** (`bootstrap/app.php`). Without it, route-model
  binding resolved `{model}` with no tenant bound → cross-agency leak (a test caught it). Now bound models are agency-scoped → 404.
- (2026-06-18) **API conventions:** controllers thin; `FormRequest` validation; ownership of FK targets enforced via scoped
  `findOrFail` (cross-agency → 404); resources never expose `credentials`; `store` returns 201; `reports/generate` enqueues
  (`GenerateReportJob`) and returns 202; unwrapped collections (assert root-level `0.id`, `assertJsonCount`).
- (2026-06-18) **`DataSource` has an in-memory default `status = pending`** (`$attributes`) so a freshly-created (not-yet-reloaded)
  model has a status enum for resources (DB default only applies on reload).
- (2026-06-18) **Admin SPA uses a lightweight Zustand view-switcher for navigation** (no router added — react-router is not in the
  locked stack). Data-source config form is generated from `GET /api/v1/connectors` `config_schema` (secret fields → credentials,
  others → config). Frontend remains gated by typecheck+lint+build (no JS unit runner yet — candidate for Phase 2: add Vitest).
- (2026-06-18) **Block layouts are validated server-side on save** via the `ValidatesBlocks` FormRequest trait (runs `BlocksValidator`,
  surfaces errors under `blocks` → 422). The editor's binding picker is fed by `GET sites/{site}/metric-catalog` (combined
  `MetricCatalog` of the site's sources; binding stores `{source, metric}`, the short name; full key = `{source}.{metric}`).
- (2026-06-18) **`@dnd-kit/*` + `@tiptap/*` added** to the frontend (locked stack §10.2/§11.3). Admin bundle grows (~507 kB) — code-splitting
  the editor/report bundles is a later optimization.
- (2026-06-18) **AI is behind an `AiClient` interface** (`AnthropicAiClient` in prod, `FakeAiClient` in tests; bound in `AppServiceProvider`).
  `AiReportBuilder` always runs the AI's JSON through `BlocksValidator` and **drops blocks bound to metrics absent from the site's catalog**
  — the AI can never invent data (§10.6). Unparseable/invalid output → `AiReportException` → 422 at the endpoint.

---

## Open questions / blockers
- **MainWP v2 REST API contract (validate before production):** `MainWpConnector` assumes
  `GET {dashboard_url}/wp-json/mainwp/v2/sites` returns a list of sites, each with `update_counts.{plugins,themes,wp}`
  (fallback flat `plugin_upgrades`/`theme_upgrades`/`wp_upgrades`), `abandoned_plugins`, and `ssl.expires_at`. Confirm the
  real endpoint paths + field names (and whether dedicated endpoints exist for updates/abandoned/SSL) against a live MainWP
  dashboard, then adjust the parser. Also confirm the precise "updates applied" definition (count reduction vs inventory diff).
- **New connectors' exact API shapes (validate before production, like MainWP):** assumptions baked into each `fetch()` —
  **WooCommerce** `/wp-json/wc/v3/reports/sales` (`[{total_sales,total_orders}]`) + `/reports/top_sellers` (`[{name,quantity}]`),
  basic-auth ck/cs; **Cloudflare** GraphQL `viewer.zones[0].httpRequests1dGroups[].sum.{requests,cachedRequests,threats,bytes}`;
  **CrowdSec** `GET {api_url}/alerts` → list of `{scenario, decisions:[…]}` (Console API vs per-VPS LAPI base/auth TBC);
  **Better Stack** `GET /monitors/{id}/sla` → `data.attributes.{availability,number_of_incidents}`; **Virusdie** via the MainWP
  ext. `GET {dashboard_url}/wp-json/mainwp/v2/virusdie/summary` → `{malware_found,infected_sites,firewall_active}`. Parsing is
  tolerant (missing → 0); confirm real endpoints/fields + auth against live accounts and adjust.
- ~~`gpt.imagina.cloud` contract~~ **RESOLVED (2026-06-18):** owner confirmed that service is NOT used. AI builder
  now uses the **Claude API (Anthropic)** — env `ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL` (default `claude-sonnet-4-6`),
  `config('services.anthropic')`. Implement `AiReportBuilder` behind an `AiClient` interface (Phase 2).
- **Chromium path on the VPS:** verify the real binary path when installing on ServerAvatar/OLS; set
  `BROWSERSHOT_CHROME_PATH` accordingly.
- **Imagina Audit API (Phase 3):** confirm it exposes its 7-module metrics + WPVulnerability data as a
  readable REST API before building the `imagina_audit` connector.
- **GA4/GSC Service Account:** owner must add the SA email as a reader in each GA4 property and GSC property.

---

## Environment notes
- Hosting: Hetzner VPS via ServerAvatar. Stack: OLS, LSPHP 8.3/8.4, MariaDB, Redis.
- Repos on GitHub; assets built in GitHub Actions; releases as self-contained ZIPs (or via Imagina Updater).
- Deploy: atomic releases (`releases/`, `shared/`, `current` symlink); OLS custom webroot → `current/public`.
- Connector credentials stored encrypted in `ir_data_sources.credentials` (Laravel encrypted cast). Never log them.
- Test accounts/keys: _(record here as you obtain them — MainWP dashboard token, GA4 SA JSON, GSC, etc.)_
