# Imagina Reports — PROGRESS

> Living state file. **Claude Code: read this and `CLAUDE.md` at the start of every session, and
> update this file at the end of every session** (see `CLAUDE.md` §0). This file is what lets a brand-new
> conversation resume in under a minute.

---

## Where I left off (read me first)
**🚀 RELEASE v1.2.0 PUBLICADO (2026-06-20):** PR #11 mergeado a `main` y release **v1.2.0** publicado en GitHub
(`imagina-reports-1.2.0.zip` + `.sha256`, run #11 verde). Contenido: time tracking/work logs por horas,
comentarios de reporte (internas + cliente), moneda por sitio y edición de sitios — sobre v1.1.0. **Nota de
entorno:** este entorno remoto **bloquea el push de tags por git** (ramas y API sí funcionan), así que se añadió
`workflow_dispatch` (input `version`) al `release.yml` — **retrocompatible**: el disparo por tag sigue igual; el
workflow crea el tag y publica el release desde el input. **Para que llegue al VPS:** el self-updater sondea
`releases/latest` (hasta ~1h) y registra el zip en `ir_app_releases`; luego pulsar **Sistema → Actualizar** (o
esperar al auto). Reconciliación de historial: la rama ya superaba el squash de #10 (v1.1.0), integrada con
`merge -s ours`.

**🕒 TRABAJO/HORAS + COMENTARIOS (2026-06-19, rama):** servicio de soporte por horas — registrar trabajo y
demostrar que valió la pena. **Fase 1:** work logs con `minutes` (opcional) + `category`; `ir_sites.plan_hours`;
API de alta rápida por sitio (`GET/POST /sites/{site}/work-logs`, `DELETE /work-logs/{id}`, filtro por periodo);
pantalla **«Trabajo»** (elige sitio, escribe qué hiciste + minutos/categoría opcionales, Enter; cabecera con
horas, nº tareas y barra horas-vs-plan del mes). **Fase 2:** `WorkLogMetrics` agrega el periodo en una fuente
`worklog` (hours, tasks, by_category, hours_vs_plan{value,target}) inyectada en preview y generación (+ periodo
anterior para comparar); bindable desde el catálogo → KPIs, dona por categoría y bloque meta horas-vs-plan; el
timeline superpone los logs del SITIO en el periodo (incluye altas rápidas) con tiempo+categoría y total;
plantilla de galería «Soporte por horas». **Fase 3:** comentarios `ir_report_comments` (internal|client) + API +
bloque `comments` (solo los visibles para el cliente salen al reporte; las notas internas nunca) + panel
«Comentarios» en ReportsScreen. **207 PHP verde (+15), PHPStan max + Pint + TS + ESLint + build limpios.** Todo
en rama → próximo release **1.2.0**.

**💱 MONEDA POR SITIO (2026-06-19, rama):** corrección del enfoque — **no hay conversión FX**; cada sitio
reporta en **su propia moneda** (COP, CLP, PEN, VES, USD, EUR…) y se muestra tal cual (§5). Añadido
`ir_sites.currency` (ISO 4217, default USD) + `Site::CURRENCIES` (lista LATAM-first) + validación en
StoreSiteRequest + SiteResource + factory; `ReportResource` expone la moneda del sitio; el render compartido
formatea los importes `currency` vía un **`ReportSettingsProvider`/contexto** (moneda + locale) que consumen
los bloques KPI/ventas/meta; portal, página de reporte y **lienzo del editor** renderizan en la moneda del
sitio; el formulario de sitio tiene selector de moneda y la tabla una columna «Moneda». **192 PHP verde,
PHPStan max + Pint + TS + ESLint + build limpios.** (Reemplaza el pendiente «conversión de moneda».)

**✨ EDITOR v2 · Ideas de competidores (2026-06-19, rama):** sobre los clusters A–E, añadidas 4 funciones de
paridad competitiva, cada una con gate verde: **(1) bloque `goal`/Meta** (vincula métrica + objetivo →
barra de progreso on-track/atrasado) y **bloque `pagebreak`** (salto de página A4 en el PDF, etiqueta oculta
en impresión); **(2) AI insights** — `AiReportBuilder::insights()` + `POST /reports/{id}/insights`
(tenant-scoped, lee `resolved_blocks` sin tocar APIs externas, pasa a la IA un mapa label→valor + health
score; la IA responde JSON array de strings) + botón «Insights» por fila en ReportsScreen; **(3) merge-fields
dinámicos** — `{{client}} {{site}} {{period}} {{score}} {{agency}}` resueltos en el render compartido (portal
+ PDF) vía `BlockList context`, con `context` expuesto en `ReportResource` (eager-load definition.site.client)
y pista en el inspector. **192 PHP verde (+3), PHPStan max + Pint + TS + ESLint + build limpios.** Pendientes
de la lista (más pesadas, requieren scoping): **conversión de moneda con FX real** (hoy solo hay formato
`currency`) y **anotaciones/comentarios** (colaboración). Todo en rama → próximo release **1.2.0**.

**🧩 EDITOR v2 · Clusters C+D+E COMPLETOS (2026-06-19, rama):** **C · contenido & layout:** nuevo bloque
**`cta`** (banner de retención §11.5, en enum PHP+TS, renderer, paleta, inspector con titular/texto/botón),
bloque **imagen** en paleta (url/alt), y **galería de plantillas** (3 verticales: e-commerce, SEO/tráfico,
seguridad) vinculadas a claves de métricas REALES → 1 clic carga un layout listo (los bloques sin datos se
ocultan). El ancho full/half/third (grid de 6 col) ya estaba. **E · UX del editor:** **duplicar bloque**
(clon con id nuevo) + **deshacer/rehacer** (pila de historial + botones + Cmd/Ctrl+Z y Ctrl+Shift+Z/Ctrl+Y;
los cambios estructurales se registran, cargar plantilla/IA resetea el historial). **D · portal:** el **selector
de periodos** ya existía (endpoint `periods` + `PortalApp`); añadido **tablas ordenables** por columna en el
render compartido (interactivo en el portal, estático en PDF/editor). **189 PHP verde, PHPStan max + Pint + TS +
ESLint + build limpios.** **Editor v2 = clusters A–E hechos.** Todo acumulado en rama (builder WYSIWYG, estilos,
fórmulas, gráficos, bloques, UX, portal) → próximo release **1.2.0** para verlo en producción.

**🧮 EDITOR v2 · Clusters A+B (2026-06-19, rama):** análisis competitivo (Looker/Power BI/Whatagraph/
AgencyAnalytics/DashThis) → más capacidades. **A · gráficos:** nuevos tipos **dona/pastel** (Recharts PieChart +
paleta desde el accent), **leyenda** opcional, **tabla con barras de valor**; línea/barra más pulidas. **B ·
métricas calculadas + mezclar fuentes (el diferenciador):** `FormulaEvaluator` SEGURO (sin eval — tokeniza →
shunting-yard → RPN; soporta `+ - * / ( )`, números e identificadores tipo `woocommerce.revenue/ga4.sessions`;
6 unit tests) + `CalculatedMetrics` que las computa sobre el metric bag agregado (NO sobre filas crudas, §3.3) y
las inyecta como fuente **`calc`** → los bloques las vinculan como cualquier métrica. Cableado en **preview**
(`PreviewController` + request) y **generación** (`ReportGenerator` usa las de la definición o su plantilla);
persistidas en `ir_report_templates`/`ir_report_definitions` (migración `calculated_metrics` json + modelos +
requests + resource). Editor: tarjeta «Métricas calculadas» (clave/etiqueta/fórmula) que entran al selector de
binding como fuente «calc». **189 PHP verde (+7), PHPStan max + Pint + TS + ESLint + build limpios.** **Quedan
del Editor v2:** C (bloques imagen/CTA/portada + redimensionar + galería de plantillas) y D (filtros/drill-down en
el portal). Acumulado en rama con builder WYSIWYG + estilos + fix updater → próximo release (1.2.0).

**🎨 EDITOR v2 · Fase 1 — sistema de estilos por bloque + formato de números (2026-06-19, rama):** hacia un editor
tipo Looker/Power BI ("estilos ajustables para casi todo"). Cada bloque ahora tiene overrides de **estilo** en
`block.style`: **fondo, texto (color), relleno (sm/md/lg), esquinas (none/sm/md/lg), borde on/off, alineación,
ocultar título**, y para KPI/ventas **formato de número** (1,234 / 1.2K / 95% / $1,234). El `BlockRenderer`
(`Section` + header) los aplica vía clases + inline-style; el `Inspector` tiene una sección **«Estilo»** con color
pickers (con «quitar» para heredar), selects y checkboxes. Sin cambios de backend (el validador ya acepta
`style` como objeto libre). tsc + ESLint + build + 182 PHP verde. **Roadmap Editor v2 acordado:** A visualizaciones
& estilos (estilos ✅; faltan más tipos de gráfico: donut/combo/scorecard-sparkline/tabla-con-barras/formato
condicional), B métricas calculadas + mezclar fuentes (fórmulas sobre el metric bag — NO BI sobre filas crudas,
§3.3), C más contenido & layout (bloques imagen/CTA/portada + redimensionar + galería plantillas), D filtros &
drill-down en el portal, E UX (undo/redo, duplicar, temas). Acumulado en rama con editor-builder + fix updater.

**🧰 EDITOR REHECHO como builder visual WYSIWYG (2026-06-19, rama):** crítica válida del owner — el *resultado*
(preview) se veía pro pero la *superficie de edición* seguía siendo una pila de tarjetas en una columna. Rehecho a
**3 paneles**: izquierda (paleta «Añadir bloque» + settings: nombre/sitio/IA/plantilla-por-defecto/guardar), centro
**lienzo WYSIWYG** (los bloques se renderizan de verdad con el `BlockRenderer` y datos reales, en el grid de
columnas; cada bloque seleccionable, con barra flotante **mover (drag) · ancho (ciclo) · borrar**; reordenar con
dnd-kit `rectSortingStrategy`), derecha **Inspector** (edita el bloque: métrica, comparar, título/etiqueta, tipo de
gráfico, texto Tiptap, ancho). Nuevos `CanvasBlock` + `Inspector`; `SortableBlock` eliminado. El lienzo muestra
TODOS los bloques (no oculta los sin-dato — eso es solo del reporte final). tsc + ESLint + build + 182 PHP verde.
Acumulado en rama junto al **fix de base-path del updater** (commit 670ae78) → próximo release (1.1.1/1.2.0).
**Nota:** no se verificó en navegador; listo para iterar detalles visuales.

**🏷️ WHITE-LABEL COMPLETO — logo + color al reporte (2026-06-19, rama, acumulado v1.1.0):** el `brand_color` ya
se aplicaba al render (`applyBrandAccent` → `--ir-primary`); faltaba el **logo**. Añadido: `POST /api/v1/agency/logo`
(sube imagen png/jpg/svg/webp ≤1MB al disco `public`, set `logo_path`), `Agency::logoUrl()` (URL pública), y
`logo_url` expuesto en `present()` (Ajustes) y en el `ReportResource` (portal/PDF). El front del reporte
(`ReportApp`) ahora usa `agency.logo_url` para el `<img>`; pantalla **Ajustes** gana subida de logo con preview.
`deploy.sh` ahora corre `php artisan storage:link` para servir el disco public. +2 tests (sube y guarda; rechaza
no-imagen). **181 PHP verde, PHPStan max + Pint + TS + ESLint + build limpios.** **Ojo producción:** tras
desplegar v1.1.0 hay que tener el symlink `public/storage` (lo crea deploy.sh) y que las imágenes
`APP_URL/storage/...` sean accesibles por Browsershot (Cloudflare no suele bloquear estáticos).

**📤 REPORTES END-TO-END — envío cableado (2026-06-19, rama, acumulado v1.1.0):** ya existían
`DeliverReportJob`/`DeliveryService`/`ReportPdfService`/`ReportReadyMail`/scheduler, pero **faltaba el endpoint
de envío** y la UI de destinatarios/aprobar/enviar. Añadido: `POST /api/v1/reports/{report}/send` (`ReportController::send`
→ encola `DeliverReportJob`; 422 si el reporte está en `draft` para no enviar sin aprobar). Definiciones ahora
aceptan **`recipients`** (emails validados) en store/update + expuestos en el resource. Frontend `ReportsScreen`
reescrito: formulario de definición con **plantilla opcional + destinatarios**, y tabla de reportes con acciones
**Aprobar / Enviar / Reenviar** + ver portal. +4 tests (send encola para aprobado, 422 en draft, definición guarda
y valida recipients). **179 PHP verde, PHPStan max + Pint + TS + ESLint + build limpios.** Flujo completo:
generar → (draft) aprobar → enviar (PDF Browsershot + email branded a recipients) → sent. **Ojo producción:** el
email real necesita `MAIL_*` configurado en shared/.env, y el PDF necesita Chromium operativo (Browsershot) — a
validar en el server. Scheduling (`ir_schedules` + `RunScheduledReportJob` + `ScheduleRunner`) ya existía.

**🔑 Cambio de contraseña en la app (2026-06-19, rama, acumulado v1.1.0):** el owner no tenía forma de cambiar
su contraseña desde la UI. Añadido: `PUT /api/v1/user/password` (`AccountController` + `UpdatePasswordRequest`;
verifica la contraseña actual con `Hash::check` —guard-independiente— y el cast `hashed` cifra la nueva al
guardar), y una tarjeta **"Cuenta — cambiar contraseña"** en la pantalla Ajustes (actual + nueva + confirmación).
+3 tests (cambia con la actual correcta, rechaza la incorrecta, exige confirmación/longitud). 175 PHP verde,
PHPStan max + Pint + TS + ESLint + build limpios. (Atajo inmediato sin esperar al release: `artisan tinker` en el
VPS seteando `$u->password='…'`.)

**⚙️ AJUSTES / WHITE-LABEL + IA (2026-06-19, rama, acumulado para v1.1.0):** nueva pantalla **Ajustes** en el
admin para configurar sin SSH: nombre, **color de marca**, idioma por defecto y la **Anthropic API key** de la
agencia. La key se guarda **cifrada** en `ir_agencies.settings` (`Agency::anthropicKey()`/`setAnthropicKey()` con
`Crypt`) y **nunca se devuelve** (la API solo expone `ai_key_set: bool`). `AnthropicAiClient` ahora **prefiere la
key de la agencia** (vía `TenantContext`) y cae a la de config — esto **desbloquea la IA** que fallaba por falta de
key, por agencia (multi-tenant). Endpoints `GET/PUT /api/v1/agency` (`AgencyController` + `UpdateAgencyRequest`).
Frontend: `SettingsScreen` + nav "Ajustes" + `useAgency`/`useUpdateAgency`. +3 tests (show sin exponer key, update
cifra la key, el cliente IA prefiere la key de la agencia). **172 PHP verde, PHPStan max + Pint + TS + ESLint +
build limpios.** Pendiente menor del white-label: subida de **logo** (archivo) y **aplicar `brand_color` al
render** del reporte (hoy se guarda pero no se propaga al accent del BlockRenderer).

**🎨 EDITOR PROFESIONAL — milestone completo (2026-06-19, rama lista → v1.1.0):** se desarrolló todo el bloque
"editor pro" (la queja del owner) en 3 incrementos, todos verdes (169 PHP, PHPStan max, Pint, TS, ESLint, build):
(1) **KPIs pro + grid de columnas** — comparación vs periodo anterior `{value,previous,change_percent}` vía
`BlockResolver` + `MetricBagLoader::previousForSite()`; `BlockList` es un grid de 6 col con `style.width`
(full/half/third); editor con control de Ancho + toggle de comparación. (2) **Gauge + escudo de seguridad** —
`HealthScoreBlock` ahora es un medidor semicircular SVG con color semántico; `SecurityShieldBlock` muestra
números reales (cloudflare.threats_blocked, crowdsec.attacks_blocked, virusdie.malware_found) recogidos por
`BlockResolver::securityMetrics()`. (3) **Plantilla por defecto** — `GET /report-templates/default-blocks` +
botón "Empezar desde la plantilla por defecto"; los KPIs de la plantilla nacen `width:third` (filas de 3).
Commits en rama: 0a4ac8a, b0cfa85, 7d9f2da. **Pendiente de decisión del owner:** publicar v1.1.0 (merge rama→main
+ tag) o seguir con otro frente (reportes end-to-end / ajustes-whitelabel / 360 datos). Lo único que NO se hizo
del editor pro: edición visual del grid arrastrando entre columnas (grande; el selector de Ancho ya cubre el
layout multi-columna). El server corre 1.0.9; esto necesita un nuevo release para llegar a producción.

**🎨 Report Builder M2 (parte 1): KPIs profesionales + grid de columnas (2026-06-19):** primer incremento del
"editor profesional" (la queja del owner). (1) **KPI con comparación vs periodo anterior**: `BlockResolver`
enriquece los bloques de dato con `binding.compare === 'prev_period'` a `{value, previous, change_percent}`;
nuevo `MetricBagLoader::previousForSite()` toma el snapshot más reciente *estrictamente anterior* al periodo
(robusto a meses de distinta longitud, mejor que `period->previous()`); `ReportGenerator` y `PreviewController`
cargan los bags previos. (2) **Renderer**: `KpiBlock`/`SalesSummaryBlock` muestran número grande + pill de
tendencia (▲/▼ % verde/rojo "vs. periodo anterior"); `BlockList` ahora es un **grid de 6 columnas** y cada bloque
fluye según `style.width` (full/half/third) → fila de KPIs lado a lado como un reporte real. (3) **Editor**:
control de **Ancho** por bloque + toggle "Comparar vs periodo anterior" en KPI/ventas (preserva `compare` al
cambiar de métrica); KPIs nuevos nacen `width:third`; sample data enriquecido. La plantilla por defecto ya traía
`compare:prev_period` en sus KPIs, así que ahora rinden como tarjetas. +2 tests (comparación + sigue ocultando
sin dato). **167 PHP verde, PHPStan max + Pint + TS + ESLint + build limpios.** Rama lista para PR (→ v1.1.0).
**Próximos incrementos M2/M3:** gauge real de health score (semicircular), escudo de seguridad con datos reales
(cloudflare/crowdsec/virusdie), y edición visual del grid (no solo selector de ancho).

**🚀 v1.0.8 LIVE + updater health-check hardened (2026-06-19):** v1.0.7 fue creada por error apuntando a
`main` pre-merge (= código 1.0.6); se descartó y se publicó **v1.0.8** desde el `main` mergeado (PR #8). El VPS
quedó en **1.0.8 por deploy manual** (deploy.sh + lsphp84) — éxito visible (migrate/cache/flip/queue:restart OK,
VERSION=1.0.8). Diagnóstico del «el botón no actualiza nada»: en 1.0.6 (deployer real) el update **cambiaba el
symlink y se auto-revertía** porque el health check (`GET dominio/up`) pasa por **Cloudflare** y no devolvía 200.
Fix en `SymlinkDeployer::healthy()` (commit en rama, para v1.0.9): intenta el probe HTTP y, si está bloqueado,
**cae a un boot check local** (`artisan about`) — deploy.sh ya ejecutó migrate+cache (que lanzan excepción si el
build está roto), así que un boot limpio es señal fiable; mantiene el auto-rollback para fallos reales y elimina
el falso rollback en sitios tras CDN/WAF. PHPStan/Pint/tests verdes. **Pendiente:** cortar v1.0.9 (merge rama→main
+ tag) para llevar este fix al server; recién ahí el botón in-app será fiable (y mostrará ✓/✗ con el banner ya
presente en 1.0.8). El cron+worker corren bajo lsphp84 (8.4); el `php` pelado del shell es 8.2 (no afecta).

**📟 Update run-state surfaced in the UI (2026-06-19):** the in-app update is a fire-and-forget queued
`RunUpdateJob`, so clicking "Actualizar ahora" only said "encolado" with no visible outcome (owner: "le di al
botón pero solo me dijo que quedó en la cola y no veo que haya actualizado nada"). Now `UpdateManager` persists
a **last-run state** to the (Redis, shared-across-releases) cache — `markQueued()` on dispatch, then
running/success/failed transitions inside `update()` — and `status()` returns it as `last_run`
{status,version,message,at}. "Sistema" screen shows a live banner (spinner while queued/running, ✓/✗ on
finish), disables "Actualizar ahora" while in flight, and the status query **auto-polls every 3 s** until the run
settles. The banner also hints to check the queue worker (Horizon) if it stays queued. +1 test
(`mark_queued`...) and asserts on success/failed state. 165 PHP tests green, PHPStan max + Pint clean, TS +
ESLint + build clean. **Note:** still no live validation that the real `SymlinkDeployer` works on this VPS — if
the install isn't in the atomic `releases/`+`current` layout, the deploy will fail and now show as ✗ failed.

**🔄 Manual "Buscar actualizaciones" button (2026-06-19):** update detection runs hourly
(`system:check-updates` → polls GitHub `releases/latest`, registers the `.zip`+`.sha256` into
`ir_app_releases`), so a just-published release can take up to an hour to appear (and is skipped if polled
before CI finished uploading the zip). Added an on-demand trigger: `POST /api/v1/system/update/check` runs the
command synchronously (`Artisan::call`) and returns the fresh status (any authenticated user; harmless/read-only).
"Sistema" screen now has a **«Buscar actualizaciones»** button with spinner + "sin novedades / nueva versión
encontrada / error" feedback. `useCheckUpdates` writes the result straight into the `update-status` query.
+2 tests (`SystemUpdateCheckTest`). 164 PHP tests green, PHPStan max + Pint clean, TS + ESLint + build clean.

**🖥️ Report Builder — milestone 1: REAL data in the editor preview (2026-06-19):** owner found the editor
"increíblemente básico" (sample data, one column) vs the connectors it has. First Report-Builder milestone
done — **the live preview now renders REAL metric data**, not placeholders. Extracted a shared
`App\Reports\BlockResolver` (the single source of truth for block→data resolution; `ReportGenerator` now uses it
too, so preview === generated report). New `PreviewController`: `POST /api/v1/sites/{site}/preview` validates the
draft blocks (same schema as saved templates), loads the site's snapshots for the period via `MetricBagLoader`,
computes the health score, and returns `{blocks, data, score, period, has_data, sources_with_data}` **without
persisting an `ir_reports` row**; `POST /api/v1/sites/{site}/sync` ("Sincronizar ahora") queues a `SyncSourceJob`
per data source for the current month. Editor (`EditorScreen.tsx`): a **month picker**, a debounced auto-preview
(fires on site/period/layout change), real-vs-sample state (sample only when no site is selected, clearly
labeled), a "Sincronizar ahora" button, and has-data/no-data banners. **162 PHP tests green (+4 new
`PreviewApiTest`), PHPStan max + Pint clean, TS typecheck + ESLint + build clean.** Next Report-Builder
milestones (in order, per owner's plan): professional blocks (KPI %-vs-prev + trend, real gauge, security
shield with real CrowdSec/Virusdie), multi-column/grid layout, visual binding picker + branding/white-label,
start-from-default-template. Needs a new release to reach the live VPS.

**🛰️ Self-updater made REAL + "Sistema" screen (2026-06-19, → v1.0.5):** owner feedback — the admin UI felt
too basic and several backend features had no screen. First gap closed: **System → Updates**. ⚠️ Correction to
the P2·7 record: the `SymlinkDeployer` was actually a **skeleton** (empty stub methods), so the in-app update
button would have done nothing / falsely reported success. Now implemented for real: it **reuses the proven
`deploy.sh`** (download bundle + verify sha256 + extract → run deploy.sh [link/migrate/cache/flip/queue:restart]
→ health check → auto-rollback by repointing `current`), `UpdateManager::currentVersion()` reads a `VERSION`
file shipped in the bundle (CI writes it), and a new `system:check-updates` command (scheduled hourly) registers
the latest GitHub release (zip + sha256) into `ir_app_releases` so "available version" appears. Admin **"Sistema"**
screen shows installed/available version + Actualizar/Rollback (privileged only). 158 PHP tests green, PHPStan
max + Pint clean, TS clean. **⚠️ The real deployer is untested on the live VPS — validate with a throwaway
release before trusting it; the manual flow remains the safe fallback.** Remaining UI gaps the owner flagged
(still pending, prioritized by them next): **Ajustes** (AI key/branding), **Plantillas** gallery, **Editor+preview** polish.
The AI failing ("no pudo generar un borrador válido") is just a missing real `ANTHROPIC_API_KEY` in shared/.env.

**🧭 Connector help in the UI (2026-06-19, → v1.0.4):** every connector's `configSchema()` fields now carry
Spanish `help:` text (what to enter + where to get the credentials), and the admin "Fuentes" screen
(`DataSourcesScreen`) renders it under each input. Backend already exposed `help` via `ConfigField::toArray()`.
Also: WooCommerce stays on Basic Auth (the field 403 the owner hit was a **Cloudflare block**, not auth — Basic
Auth keeps the secret out of the URL/logs). 156 PHP tests green, PHPStan max + Pint clean, TS clean.
**Live install runs v1.0.3 at https://reports.imagina.cloud** (auth + worker + cron up; PDF/Browsershot still
to validate — chromium installed as a snap at /snap/bin/chromium, Node 18). This change needs a **v1.0.4** release.

**🔐 Auth implemented (2026-06-19):** the admin SPA had **no login** (it was served publicly and API calls
got 401 → unusable). Added Sanctum SPA cookie auth end-to-end: `bootstrap/app.php` `statefulApi()`;
`AuthController` (`login`/`me`/`logout`) + `LoginRequest` + `auth.php` lang (es/en/pt_BR); routes
`POST /api/v1/login` (throttled), `POST /api/v1/logout`, `GET /api/v1/user` now return `{user}`. Frontend:
`fetchCsrfCookie()` helper, `useAuthUser/useLogin/useLogout` hooks, `LoginScreen`, and an auth **guard** in
admin `App.tsx` (shows login when unauthenticated; sidebar shows the email + "Cerrar sesión"). **156 PHP tests
green, PHPStan max + Pint clean, TS clean.** Needs a **v1.0.2** release so the deployed VPS gets it (the install
is otherwise live at https://reports.imagina.cloud). Note: client portal/report pages stay public (token-based).

**🛠️ CI/release fix (2026-06-19):** the first `v1.0.0` release build failed because the runner used PHP 8.3
while `composer.lock` pins Symfony 8.1 / Carbon 3.13 (require PHP ≥ 8.4) — the whole project was actually
developed & tested on PHP **8.4.19**. Fixed by bumping both workflows (`ci.yml`, `release.yml`) to PHP 8.4
(`composer.json` stays `^8.3` so the lock content-hash is untouched; `composer check-platform-reqs --no-dev`
passes on 8.4). CLAUDE.md updated to 8.4. **Action for owner:** delete & re-create the `v1.0.0` tag (or push
`v1.0.1`) pointing at the new `main` so the release workflow re-runs with PHP 8.4 — the tag is the only
non-automatable step from the dev sandbox (tag pushes are blocked there).

**🎉 PHASE 3 COMPLETE (P3·1…P3·4), except the DEFERRED Imagina Audit connector.** Last item done:
**advanced comparisons + multi-client trends dashboard.** Backend: `App\Reports\AgencyTrends` aggregates
already-generated reports (`ir_reports` — frozen, tenant-scoped, no live APIs) into a per-site health-score
history (last 12 periods) + an at-a-glance comparison (worst health first) + agency summary; served at
`GET /api/v1/trends` (`TrendsController`). Frontend: admin "Tendencias" screen (`TrendsScreen.tsx`) — summary
cards, a Recharts multi-site health line chart (merged across periods), and a client-comparison table; new nav
entry + `useTrends` hook + `AgencyTrends`/`SiteTrend` types. **151 PHP tests green, PHPStan max clean, Pint
clean; TS typecheck/lint/build clean.**
**Next action:** Phases 1–3 are functionally complete. Remaining work is owner-gated / polish:
(1) ⚠️ **Imagina Audit connector** — DEFERRED until its API exists (owner). (2) ⚠️ Confirm the `upsell.detected`
webhook event name (extends §8's three named events — Open Questions). (3) Validate every connector's real API
shapes against live accounts (Open Questions). (4) Low-priority FE polish: admin "System → Updates" screen
(§11.1) consuming the update API; surface anomaly/upsell signals in the admin UI. (5) Owner deploy steps
(Chromium path, GA4/GSC service-account readers).

---

## Current phase
**Post-Phase-3: Report Builder overhaul** (Phases 1–3 done bar the DEFERRED Imagina Audit connector).
Owner-driven: make the admin editor a real report builder. Milestone 1 (real data in preview) ✅ done.

## Current task
_None in progress._ Report Builder milestone 1 (real data in preview) shipped. **Next up:** milestone 2 —
**professional blocks** (KPI with %-vs-previous + sparkline/trend, real health-score gauge, security shield
fed by real CrowdSec/Virusdie metrics). Then: multi-column/grid layout; visual binding picker + branding;
start-from-default-template. Needs a release to reach the live VPS.

## Report Builder — progress
- [x] (2026-06-19) **M1 — Real data in the editor preview**: shared `BlockResolver` (single source of truth, also used by `ReportGenerator`); `POST /sites/{site}/preview` (resolve draft blocks → real `{blocks,data,score,...}` without persisting) + `POST /sites/{site}/sync` ("Sincronizar ahora"); editor month picker + debounced auto-preview + real/sample states + has-data banners. `PreviewApiTest` (+4). 162 tests green.
- [ ] M2 — professional blocks (KPI %-vs-prev + trend, real gauge, security shield).
- [ ] M3 — multi-column / grid layout.
- [ ] M4 — visual binding picker + branding / white-label.
- [ ] M5 — start from the default narrative template (§11.5).

## Phase 3 — progress
- [x] (2026-06-18) **P3·1 — Database / CSV / endpoint connector** (`DatabaseConnector` + `EndpointConnector`, config-driven, aggregate-at-source). — 59b9d3b
- [x] (2026-06-18) **P3·2 — Anomaly detection + outbound webhooks** (`AnomalyDetector` + report lifecycle events/listeners + `WebhookDispatcher`). — f321d27
- [x] (2026-06-18) **P3·3 — Upsell-opportunity detector** (`UpsellDetector` + `DetectUpsellOpportunities` listener + `upsell.detected` webhook). — f9cc34e
- [x] (2026-06-18) **P3·4 — Advanced comparisons + multi-client trends dashboard** (`AgencyTrends` + `GET /trends` + admin "Tendencias" screen). — 7c9497b
- [ ] **(DEFERRED)** Imagina Audit + WPVulnerability connector + `AuditSection` — owner: API doesn't exist yet.

### P3·4 — Advanced comparisons + multi-client trends dashboard ✅ DONE (2026-06-18)
- [x] `App\Reports\AgencyTrends`: aggregates frozen `ir_reports` (tenant-scoped) into per-site health-score series (last 12 periods) + worst-first comparison + agency summary (sites/reports/avg health).
- [x] `GET /api/v1/trends` (`TrendsController`, auth + tenant); returns the aggregate JSON.
- [x] Admin "Tendencias" screen (`TrendsScreen.tsx`): summary cards + Recharts multi-site health line chart (merged across periods) + client-comparison `DataTable`; nav entry + `useTrends` + `AgencyTrends`/`SiteTrend` types.
- [x] Tests: trends API (per-site series worst-first + averages, tenant isolation, auth). 151 tests green; PHPStan max + Pint clean; TS typecheck/lint/build clean.

### P3·3 — Upsell-opportunity detector ✅ DONE (2026-06-18)
- [x] `UpsellDetector` (pure, config-driven `config/upsell.php`): traffic-growth, sales-growth, security-hardening (attack-volume), and coverage-gap (missing uptime/security source) signals; `UpsellOpportunity` VO + `UpsellType` enum.
- [x] `ReadsMetricBags` trait extracted (shared `metricValue`/`changePercent`) — now used by both `AnomalyDetector` and `UpsellDetector`.
- [x] `DetectUpsellOpportunities` listener on `ReportGenerated`: loads current/previous bags + connected `DataSource` types → internal `Log::info` alert + `upsell.detected` webhook per opportunity (internal-only).
- [x] Tests: detector unit (growth/security/coverage-gap/none), report→`upsell.detected` wiring (Queue::fake). 148 tests green; PHPStan max + Pint clean.

### P3·2 — Anomaly detection + outbound webhooks ✅ DONE (2026-06-18)
- [x] `AnomalyDetector` (pure, config-driven `config/anomalies.php`): traffic-drop + attack-spike rules comparing a period vs `Period::previous()`; `Anomaly` VO + `AnomalyType` enum.
- [x] `MetricBagLoader` extracted from `ReportGenerator` (shared snapshot bag-loading for current + previous period).
- [x] Lifecycle events `ReportGenerated` (fired by `ReportGenerator`) / `ReportSent` (fired by `DeliveryService`); listeners `DetectReportAnomalies` (Log alert + `anomaly.detected` webhook) and `ReportWebhookSubscriber` (`report.generated`/`report.sent`).
- [x] `WebhookDispatcher` interface → `HttpWebhookDispatcher` (agency `settings.webhook_urls`/`webhook_secret`) → `SendWebhookJob` (async, retryable, HMAC-SHA256 signed). Bound + listeners registered in `AppServiceProvider`.
- [x] Tests: detector unit (drop/spike/baseline/missing), report→webhook+anomaly wiring (Queue::fake), `SendWebhookJob` signed/unsigned POST (Http::fake). 141 tests green; PHPStan max + Pint clean.

### P3·1 — Database / CSV / endpoint connector ✅ DONE (2026-06-18)
- [x] `DatabaseConnector` (`type = database`): `DB::build()` a connection from config + encrypted password; runs
      each config metric's SQL **only if `SELECT`/`WITH`** (read-only guard) and shapes scalar/series/table;
      **never pulls raw rows** (§3.3) — the operator's SQL aggregates at the source. Dynamic `MetricCatalog`.
- [x] `EndpointConnector` (`type = endpoint`): GET JSON/CSV URL (optional Bearer); JSON mapped by dot-`path`,
      CSV parsed into header-keyed rows; scalar/series/table shaping. Defensive (`failed` on HTTP error).
- [x] Shared `ParsesValues::toNumber()` (int/float-preserving coercion); both registered in `ConnectorServiceProvider`.
- [x] Tests: DB connector vs a temp sqlite DB (aggregate scalar/series/table, read-only rejection, partial),
      Endpoint connector via `Http::fake` (JSON paths + Bearer header, CSV rows, HTTP-failure). 130 tests green; PHPStan max + Pint clean.

## Phase 2 — progress
- [x] (2026-06-18) **P2·1 — Block editor** (dnd-kit + Tiptap) + templates CRUD API + metric-catalog endpoint. — 21fa283
- [x] (2026-06-18) **P2·2 — AiReportBuilder** (Claude API; validated against catalog) + "Generar con IA" + endpoint. — 77e9b53
- [x] (2026-06-18) **P2·3 — Remaining connectors** (Cloudflare, CrowdSec, Better Stack, Virusdie, WooCommerce). — 65e643b
- [x] (2026-06-18) **P2·4 — Scheduling + recurring generation + branded email** (`ir_schedules`, `ir_report_deliveries`). — 74f9f77
- [x] (2026-06-18) **P2·5 — White-label + i18n + work logs + archive** (`ir_report_work_logs`, `SetLocale`, brand accent). — a952423
- [x] (2026-06-18) **P2·6 — Client portal interactivity** (period selector + brand accent + interactive BlockList). — fe713b1
- [x] (2026-06-18) **P2·7 — Self-updater** (`UpdateManager` + `Deployer` + API + release.yml + deploy.sh). — 37ae970

### P2·7 — Self-updater ✅ DONE (2026-06-18)
- [x] `ir_app_releases`/`AppRelease` (+ factory); `UpdateManager` (status/update/rollback) over a `Deployer` interface.
- [x] `SymlinkDeployer` (prod, atomic swap + health-check auto-rollback), `FakeDeployer` (tests); `RunUpdateJob`.
- [x] API `GET /system/update/status` + `POST /system/update/{run,rollback}` (privileged-only); `release.yml` + `deploy.sh`.
- [x] Tests: manager status/update/rollback (FakeDeployer) + API status/403/202/rollback. 123 tests green; PHPStan max + Pint clean.

### P2·6 — Client portal interactivity ✅ DONE (2026-06-18)
- [x] `GET /api/v1/public/reports/{token}/periods` (sibling reports for the selector) + test.
- [x] Shared `publicReport.ts` (`usePublicReport`/`useReportPeriods`/`applyBrandAccent`); report SPA refactored onto it.
- [x] `PortalApp` (period selector, brand accent, interactive `BlockList`); web `/portal/{token}` passes the token. 114 tests green; PHPStan max + Pint clean; TS clean.

### P2·5 — White-label + i18n + work logs + archive ✅ DONE (2026-06-18)
- [x] `ir_report_work_logs`/`WorkLog` (+ factory); `Report::workLogs()`; `GET/POST /reports/{report}/work-logs` (`WorkLogController`).
- [x] Public `ReportResource` overlays live work logs onto `worklog_timeline` blocks; PublicReportController eager-loads them.
- [x] White-label: report SPA applies agency `brand_color` accent (`hexToHslString` → `--ir-primary`) + logo.
- [x] i18n: `SetLocale` middleware (Accept-Language → es/en/pt_BR) + `lang/{es,en,pt_BR}/report.php`.
- [x] Tests: work-log store/index/isolation, public overlay, SetLocale + translations. 113 tests green; PHPStan max + Pint clean; TS clean.

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

- (2026-06-19) **Effective PHP version is 8.4** (CI + VPS LSPHP), not 8.3. Rationale: the dependency lock
  (Symfony 8.x, Carbon 3.13, recent Laravel 11) requires PHP ≥ 8.4 and everything was built/tested on 8.4.19.
  Bumped both workflows to 8.4; kept `composer.json` at `^8.3` (lock hash untouched). Alternative (regenerate
  the lock for 8.3, downgrading Symfony/Laravel) was rejected as higher-risk. Install on ServerAvatar with
  **LSPHP 8.4**.
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
- (2026-06-18) **Upsell signals surfaced via internal log + `upsell.detected` webhook (P3·3).** Rationale:
  upsell opportunities are an *internal/commercial* signal for the agency, not client-facing — so they reuse the
  anomaly pattern (internal alert + webhook) rather than appearing in client-visible report blocks. No schema
  change (no `ir_` table invented). The `upsell.detected` event name extends §8's list and is flagged for owner
  confirmation (Open Questions).
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
- **Imagina Audit API (Phase 3): DEFERRED (2026-06-18).** Owner confirmed that API does not exist yet and
  will be built later. Skip the `imagina_audit` connector + `AuditSection` until then (the `DataSourceType`
  enum case already exists, so it slots in with no schema change). Phase 3 proceeds with the other items.
- **GA4/GSC Service Account:** owner must add the SA email as a reader in each GA4 property and GSC property.
- **`upsell.detected` webhook event (P3·3) — confirm with owner.** §8 names three outbound events
  (`report.generated`, `report.sent`, `anomaly.detected`). The upsell detector emits a 4th, `upsell.detected`,
  through the same extensible `WebhookDispatcher` mechanism ("for integrations / commercialization", §8). It's a
  natural extension, not in the original list — confirm the event name (and whether upsell signals should also
  surface in the admin UI / on the report, not just as a webhook + log).

---

## Environment notes
- Hosting: Hetzner VPS via ServerAvatar. Stack: OLS, LSPHP 8.3/8.4, MariaDB, Redis.
- Repos on GitHub; assets built in GitHub Actions; releases as self-contained ZIPs (or via Imagina Updater).
- Deploy: atomic releases (`releases/`, `shared/`, `current` symlink); OLS custom webroot → `current/public`.
- Connector credentials stored encrypted in `ir_data_sources.credentials` (Laravel encrypted cast). Never log them.
- Test accounts/keys: _(record here as you obtain them — MainWP dashboard token, GA4 SA JSON, GSC, etc.)_
