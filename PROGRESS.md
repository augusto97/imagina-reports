# Imagina Reports — PROGRESS

> Living state file. **Claude Code: read this and `CLAUDE.md` at the start of every session, and
> update this file at the end of every session** (see `CLAUDE.md` §0). This file is what lets a brand-new
> conversation resume in under a minute.

---

## Where I left off (read me first)
**Phase 1 · Tasks 1 & 2 are DONE.** On top of the Laravel 11 + dual-SPA baseline (Task 1), the
multi-tenancy layer is in place (Task 2): `ir_agencies` (tenant root, `Agency` model), `ir_users`
(`User` now on table `ir_users` with `agency_id` FK + a `role` enum `App\Enums\UserRole`
owner/admin/collaborator, `HasApiTokens` for dual auth), and `ir_clients` (`Client` — the first
agency-scoped domain model). Tenant isolation is enforced by a **global scope**: `TenantContext`
(singleton) holds the active agency; `AgencyScope` filters every query on models using the
`BelongsToAgency` trait (which also auto-stamps `agency_id` on create); the `BindTenant` middleware
(alias `tenant`, applied after `auth:sanctum`) binds the tenant from the authed user. `User` is
deliberately NOT auto-scoped so auth can resolve users before a tenant exists. **9 tests green**
(incl. the §14 isolation test: agency A can't read agency B), **PHPStan max clean, Pint clean**.
**Next action: Phase 1 · Task 3 — snapshot pipeline & connector contracts** (`DataSourceConnector`
interface + `ConnectorRegistry` + `MetricCatalog` + `MetricSet`; `ir_data_sources` + `ir_metric_snapshots`;
`SyncSourceJob` + `SyncService`, idempotent + aggregate-at-source). Note: no MariaDB/Redis in this
env — tests use sqlite in-memory / array / sync.

---

## Current phase
**Phase 1 — Core engine + immediate value**

## Current task
**Phase 1 · Task 3 — Connector contracts** (not started).
`DataSourceConnector` interface + `ConnectorRegistry` (type → connector) + `MetricCatalog` +
`MetricSet` (normalized metric bag), plus the value objects they need (`ConnectionResult`, `Period`,
`MetricSet`). No concrete connectors yet (MainWP/GA4/GSC are later tasks) — this task defines the
contracts and the registry only, with unit tests. See `CLAUDE.md` §7 and §10.1.

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
2. **(current)** `DataSourceConnector` interface + `ConnectorRegistry` + `MetricCatalog` + `MetricSet` (metric bag).
3. Snapshot pipeline: `ir_data_sources`, `ir_metric_snapshots`, `SyncSourceJob`, `SyncService` (idempotent, aggregate-at-source).
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
      `BelongsToAgency` trait + `BindTenant` middleware; §14 isolation test. 9 tests green; PHPStan max + Pint clean. — _commit pending_

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
  catalog — cannot invent data) + per-period narrative, via `gpt.imagina.cloud`. "Create a report in seconds."
- (2026-06-18) **Performance golden rule: aggregate at the source, never pull raw rows.** This is why GA4's
  millions of visits never touch the app — GA4/GSC/Cloudflare/Woo aggregate server-side. The `database`
  connector must `GROUP BY` on the client's DB. NOT a BI engine; do not try to replicate Power BI.
- (2026-06-18) **Atomic releases (symlink) + in-app Update/Rollback** (`UpdateManager`); CI builds a
  self-contained ZIP (vendor + compiled assets). Reuses the **Imagina Updater** mechanism.
- (2026-06-18) **Replaces Modular DS + MainWP Pro Reports.** Maintenance "work done" is computed by diffing
  MainWP snapshots (its REST API exposes current state, not a historical work log).
- (2026-06-18) **VirusDie via the MainWP Virusdie extension**, not VirusDie's partner API (avoids the contract).
- (2026-06-18) **Spec language: English** (for Claude Code). Client-facing report content is localized (ES default).
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

---

## Open questions / blockers
- **`gpt.imagina.cloud` contract:** confirm the request/response shape and auth for the AI endpoint before
  building `AiReportBuilder` (Phase 2). Add env vars `GPT_IMAGINA_ENDPOINT` / `GPT_IMAGINA_KEY`.
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
