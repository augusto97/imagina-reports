# Imagina Reports

Multi-tenant platform that unifies an agency's tooling (MainWP, Google Analytics, Search Console,
Cloudflare, CrowdSec, VirusDie, Better Stack, WooCommerce — and later Imagina Audit) into a single
**branded, narrated client report**. Its purpose is client **retention**: making invisible technical
work visible so clients understand the value of their support plan.

By Imagina WP.

---

## For Claude Code — start here
1. Read **`CLAUDE.md`** — the full spec and the working rules (§0 is mandatory).
2. Read **`PROGRESS.md`** — current state, current task, what's next, and the decisions log.
3. Build **phase by phase**, updating `PROGRESS.md` as you go.

> Golden rule: if a new session started with zero memory, `CLAUDE.md` + `PROGRESS.md` must be enough to
> resume seamlessly. Keep `PROGRESS.md` detailed.

---

## Stack
- **Backend:** Laravel 11, PHP 8.3, API-first (REST `/api/v1` + Sanctum), multi-tenant.
- **Frontend:** two React 18 + TypeScript SPAs (admin panel + interactive client portal), Vite, built in CI.
- **Data:** MariaDB + Redis (queue/cache/sessions), Horizon, persistent worker.
- **Reports:** block-based templates (dnd-kit + Tiptap editor), AI builder, single `BlockRenderer`;
  PDF via headless Chromium (Browsershot).
- **Host:** Hetzner VPS via ServerAvatar (OLS). Atomic releases + in-app Update/Rollback.

## Local development (high level)
```bash
composer install
cp .env.example .env && php artisan key:generate
# configure MariaDB + Redis in .env
php artisan migrate
npm install && npm run dev        # local only; production assets are built in CI
php artisan queue:work            # or Horizon
php artisan serve
```

## Deployment
Atomic releases via GitHub Actions (build → ZIP → symlink swap). In-app **Update** button +
**Rollback** managed by `UpdateManager`. See `CLAUDE.md` §12.

## Key docs
- `CLAUDE.md` — architecture, conventions, connectors, API, report engine, deployment, roadmap.
- `PROGRESS.md` — living state, decisions log, open questions.
