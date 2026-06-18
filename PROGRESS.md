# Imagina Reports — PROGRESS

> Living state file. **Claude Code: read this and `CLAUDE.md` at the start of every session, and
> update this file at the end of every session** (see `CLAUDE.md` §0). This file is what lets a brand-new
> conversation resume in under a minute.

---

## Where I left off (read me first)
**Nothing has been built yet — this is the kickoff.** The full spec lives in `CLAUDE.md`. The next
action is **Phase 1 · Task 1**: scaffold the Laravel 11 project and the tooling baseline. Do not skip
ahead; build Phase 1 in order. After each task, check it off here, log decisions, and update this note.

---

## Current phase
**Phase 1 — Core engine + immediate value**

## Current task
**Phase 1 · Task 1 — Project skeleton & tooling baseline**

Sub-steps:
- [ ] `composer create-project laravel/laravel` (Laravel 11, PHP 8.3, `strict_types`).
- [ ] Install & configure: Sanctum, Larastan/PHPStan (max level), Pint, Horizon, Spatie Browsershot,
      Spatie laravel-permission, google/apiclient.
- [ ] Configure `.env` for MariaDB + Redis (queue/cache/sessions = redis).
- [ ] Set up `composer run stan` + Pint scripts and a passing CI lint/test baseline.
- [ ] Scaffold the two Vite + React 18 + TS SPAs (`resources/js/admin`, `resources/js/portal`) with the
      locked frontend stack (TanStack Query/Table, Zustand, RHF+Zod, shadcn/ui local, Tailwind prefix `ir-`,
      Lucide, Framer Motion, Inter local).
- [ ] Add a GitHub Actions workflow that builds both SPAs (so the server never needs Node).
- [ ] Commit. Update this file.

## Next up (Phase 1, in order)
1. Multi-tenant scaffolding: `ir_agencies`, agency global scope, `ir_users` + Sanctum auth, base migrations.
2. `DataSourceConnector` interface + `ConnectorRegistry` + `MetricCatalog` + `MetricSet` (metric bag).
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
- _(none yet)_

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
