# Imagina Reports — PROGRESS

> Living state file. **Claude Code: read this and `CLAUDE.md` at the start of every session, and
> update this file at the end of every session** (see `CLAUDE.md` §0). This file is what lets a brand-new
> conversation resume in under a minute.

---

## Where I left off (read me first)
**🎨 REDISEÑO UX: CLIENTES/SITIOS/FUENTES → UN SOLO PANEL MAESTRO-DETALLE (2026-06-25, rama `claude/github-app-analysis-a7b2bd`,
release v1.13.47, solo frontend):** el owner pidió simplificar el flujo de alta (3 menús separados Clientes/Sitios/Fuentes, cada uno «solo
un formulario», obligaban a saltar entre menús). Elegido (AskUserQuestion): **maestro-detalle + alta rápida**. Implementado: **(1)** nuevo
`screens/WorkspaceScreen.tsx` — panel único «Clientes»: izquierda lista de clientes en árbol (acordeón) desplegable a sus sitios + buscador
+ «+ Nuevo cliente»; al elegir un sitio, derecha muestra su detalle (URL, estado, moneda, horas) + sus fuentes. **(2)** `components/SiteDataSources.tsx`
— extraído de la antigua DataSourcesScreen: lista de fuentes con estado/Probar/Editar/Eliminar + alta de conector plegable (formulario
dinámico desde configSchema, SetupGuide, panel push CrowdSec, descarga del agente). **(3)** `Modal` nuevo en `components/ui.tsx`. **(4)**
Alta exprés: «+ Nuevo cliente» crea cliente + su primer sitio (opcional) en un modal y te deja en el sitio; «+ Sitio» por cliente; editar
cliente/sitio en modal. **(5)** `App.tsx`: NAV pasa de 3 ítems a 1 («Clientes»); las vistas legacy clients/sites/data-sources mapean todas
al WorkspaceScreen (hashes viejos siguen funcionando). `store.selectSite` ahora lleva a la vista 'clients'. Eliminados ClientsScreen/
SitesScreen/DataSourcesScreen. **NO hay cambios de backend** (la API ya era jerárquica) ni de plugin. **typecheck + lint(0 err) + build +
300 tests PHP limpios.** **Pendiente del owner:** desplegar v1.13.47 y validar el nuevo flujo (recuerda: no borrar sitios — no hay endpoint
DELETE de sitios, igual que antes).


**🛠️ FIX CRÍTICO: AUTOUPDATER SE COLGABA EN «Instalando…» (2026-06-25, rama `claude/github-app-analysis-a7b2bd`, release v1.13.46):**
el owner reportó que el botón Actualizar se quedó en «Instalando la versión 1.13.45…» 15+ min. CAUSA RAÍZ: `deploy.sh` (línea 65)
ejecutaba `horizon:terminate` DENTRO del job de actualización (RunUpdateJob corre en un worker de Horizon) → mataba al propio worker
antes de que `UpdateManager::update()` pudiera registrar `success` → estado atascado en `running` para siempre. El deploy en sí SÍ se
completaba (symlink se cambia en línea 57, antes del terminate), así que el sitio quedó realmente en 1.13.45; solo el banner colgado.
FIX (3 partes): (1) `deploy.sh` ahora salta el reinicio de workers si `SKIP_WORKER_RESTART=1`; (2) `SymlinkDeployer` pone esa env al
correr deploy.sh y expone `restartWorkers()` (nuevo método del interface `Deployer`); `UpdateManager::update()` registra `success`
PRIMERO y luego llama `restartWorkers()` (se mata a sí mismo al final, ya con el estado guardado); (3) `UpdateManager::reconcile()`
(self-heal en lastRun/status): si el estado es running/queued y la web YA sirve la versión objetivo → reporta `success`; si lleva
>20 min atascado sin que llegue la versión → `failed`. Tests: FakeDeployer +restartWorkers, +2 tests (self-heal por versión y timeout).
**300 tests + PHPStan + Pint limpios.** **RECOVERY INMEDIATO para el owner (la web ya está en 1.13.45):** limpiar la clave de caché del
banner con `php /home/user/imagina-reports/current/artisan tinker --execute="Illuminate\\Support\\Facades\\Cache::forget('ir:update:last_run');"`
(o desplegar 1.13.46 por Git deploy de ServerAvatar, que auto-sana el banner). Luego actualizar a 1.13.46 ya NO se cuelga.


**🧩 LEADS ELEMENTOR/JET + 2 FIXES DE UX EN EL ADMIN (2026-06-25, rama `claude/github-app-analysis-a7b2bd`, plugin v1.8.0 →
release v1.13.45):** tres cosas a la vez. **(1) Leads Elementor Pro + JetFormBuilder:** reescrito `imagina_reports_agent_leads` para
soportar varias fuentes y elegir la que MÁS envíos tiene (evita falso positivo de plugin instalado-pero-vacío): Bit Form, Fluent Forms,
**Elementor Pro** (`{prefix}e_submissions`), **JetFormBuilder** (`{prefix}jet_fb_records`), CF7/Flamingo. Guarda por existencia de tabla;
degrada a 0 si el esquema difiere. OJO §0: Elementor/Jet usan esquemas DOCUMENTADOS pero NO confirmados con /diagnostics en un sitio real
(imaginawp no los tiene) — añadí `e_submissions`/`jet_fb`/`jetform` a los needles para que el owner confirme en un sitio que los use.
**(2) Fix refresco tras generar informe (`resources/js/admin/api.ts` useGenerateReport):** la generación es un job en cola y el row del
report aparece solo al terminar; el invalidate único a 500ms lo perdía → ahora re-invalida en tanda (600ms/1.5s/3s/5s/8s/12s) hasta que
aparece, sin recargar. **(3) Fix navegación/reload (`resources/js/admin/store.ts` + `App.tsx`):** la SPA navegaba solo por estado Zustand
(`view`) sin tocar la URL, así que recargar volvía a 'clients'. Ahora `view` se refleja en el hash (`#/reports`) vía replaceState + listener
`hashchange`, y se restaura del hash al cargar; `selectedSiteId`/`editingTemplateId` se persisten en localStorage para que data-sources/
editor sobrevivan al reload. **298 tests + typecheck + build OK (2 warns lint preexistentes).** **Pendiente del owner:** desplegar
v1.13.45, reinstalar plugin (v1.8.0); validar que al recargar te quedas en la sección (URL con #/) y que el informe aparece solo tras
generar. Si usáis Elementor Pro/JetFormBuilder en algún sitio, ejecutar /diagnostics allí y pegar la sección `forms` para confirmar.


**✅ AGENTE IMAGINA — 2º LOTE COMPLETO: LEADS DE BIT FORM (2026-06-25, rama `claude/github-app-analysis-a7b2bd`, plugin v1.7.0 →
release v1.13.44):** el owner re-ejecutó /diagnostics (v1.6.0) y reveló la tabla de Bit Form: `{prefix}bitforms_form_entries` (cols
`form_id`,`status`,`created_at`; 9 filas). Implementé **leads** con detección por prioridad (sin adivinar, §0): **Bit Form**
(bitforms_form_entries) → **Fluent Forms** (fluentform_submissions, excl. trashed) → **Contact Form 7** (flamingo_inbound). El filtro de
periodo de las tablas propias usa hora LOCAL (`get_date_from_gmt`, porque esos plugins guardan created_at con current_time('mysql')); CF7
sigue con post_date_gmt. El conector NO cambió (las métricas `site_agent.leads`/`leads_total` ya existían y son agnósticas del plugin).
**Con esto el 2º lote queda COMPLETO: logins ✅ (Wordfence), imágenes ✅ (ShortPixel), leads ✅ (Bit Form/Fluent/CF7).** 298 tests +
PHPStan + Pint limpios. **Pendiente del owner:** desplegar v1.13.44, reinstalar plugin (v1.7.0), validar que leads muestra los envíos de
Bit Form. **Estado «reemplazar MainWP»:** el agente ya cubre updates/inventario/salud/SSL/abandonados + seguridad/rendimiento/contenido/
leads/ecommerce/logins/imágenes — sustituye de sobra los datos por-sitio de MainWP. (Nota: la clave del agente se pegó en el chat 2 veces;
recomendé regenerarla al terminar de validar.)


**🔌 AGENTE IMAGINA — 2º LOTE IMPLEMENTADO (LOGINS + IMÁGENES) DESDE EL /diagnostics REAL (2026-06-25, rama `claude/github-app-analysis-a7b2bd`,
plugin v1.6.0 → release v1.13.43):** el owner ejecutó `/diagnostics` en imaginawp.com y pegó el JSON. Con el esquema REAL (sin adivinar)
implementé: **(1) Logins (Wordfence):** tabla `{prefix}wflogins` (cols `fail`,`ctime` unix) → `site_agent.failed_logins` (fallidos en
periodo); tabla `{prefix}wfblocks7` (col `blockedTime`) → `site_agent.logins_blocked`. Fallback a `limit_login_lockouts_total` si no hay
Wordfence. **(2) Imágenes (ShortPixel):** tabla `{prefix}shortpixel_postmeta` (cols `original_size`/`compressed_size`) →
`site_agent.images_optimized` (cuenta `compressed_size>0`, sin asumir códigos de estado) + `site_agent.images_saved_mb` (ahorro real).
Ambos se ocultan si no se detecta el plugin. **298 tests + PHPStan max + Pint limpios.** **HALLAZGO IMPORTANTE del diagnostics:** el plugin
de formularios REAL del sitio es **Bit Form** (`bit-form`/`bitformpro`), que no estaba en mis needles → no se descubrió su tabla de envíos.
Las tablas `fluentform` existen pero con `submissions:0` (no es el que usan). Por eso **leads sigue pendiente**: añadí `bitform`/`bitapps`
a los needles de /diagnostics → el owner debe **re-ejecutar /diagnostics con v1.6.0** y pegarme el JSON para ver las tablas de Bit Form e
implementar el contador de envíos. **Pendiente del owner:** desplegar v1.13.43, reinstalar plugin (v1.6.0), re-ejecutar /diagnostics para
Bit Form. Notas del sitio: Wordfence activo (wflogins 985 filas, wfhits 1448), ShortPixel (postmeta 1848 filas), WooCommerce activo,
WP Rocket + Cloudflare page cache, UpdraftPlus + WPvivid Pro (backups), really-simple-ssl, wps-hide-login.


**🔌 AGENTE IMAGINA — 2º LOTE (DESCUBRIMIENTO) + DETECTOR DE PLUGINS ABANDONADOS (2026-06-25, rama `claude/github-app-analysis-a7b2bd`,
plugin v1.5.0 → release v1.13.42):** el owner pidió las dos cosas. **(1) /diagnostics ampliado:** nueva `imagina_reports_agent_probe_structure($needles)`
que, para plugins de **formularios** (WPForms/Gravity/Forminator/Ninja/Fluent/Formidable/Flamingo), **seguridad** (Wordfence/Limit Login/
Solid-iThemes/Cerber/Loginizer) e **imágenes** (Smush/ShortPixel/Imagify/EWWW), revela SOLO estructura (nombres de opción + columnas y nº
de filas por tabla), NUNCA valores (las entradas tienen PII y la config de seguridad puede tener secretos). También devuelve `active_plugins`.
Con esto, cuando el owner ejecute `GET /wp-json/imagina-reports/v1/diagnostics?key=…`, sabré dónde leer cada métrica sin adivinar (§0).
**(2) Detector de plugins abandonados (lado Laravel):** el agente ahora incluye `plugins.list` (slug/nombre/versión/activo); nuevo
servicio `App\Connectors\SiteAgent\AbandonedPluginChecker` consulta `api.wordpress.org/plugins/info/1.0/{slug}.json` (cacheado 7 días por
slug, errores transitorios no se cachean) y marca como abandonado solo lo que ESTÁ en el repo y lleva >24 meses sin actualizar — los
plugins premium/custom (no están en el repo) NO se marcan (evita falsos positivos). Conector: +2 métricas `site_agent.abandoned_count`
(escalar) y `site_agent.abandoned_plugins` (tabla Plugin/Última actualización/Motivo); solo llama a wp.org si la métrica se pide (lista
vacía = preview completo = sí). **298 tests (+2: detección y skip-cuando-no-se-pide) + PHPStan max + Pint limpios.** **Pendiente del owner:**
desplegar v1.13.42, reinstalar plugin (v1.5.0), y **ejecutar /diagnostics y pegarme el JSON** para implementar los lectores reales de
logins/formularios/imágenes del 2º lote. El detector de abandonados ya funciona end-to-end (se activa al bindear la métrica o en preview).


**🔐 AGENTE IMAGINA — MONITOR SSL + RUMBO «REEMPLAZAR MAINWP» (2026-06-25, rama `claude/github-app-analysis-a7b2bd`, plugin v1.4.0 →
release v1.13.41):** el owner pidió verificar SSL como MainWP, con la visión de que el agente **reemplace todos los datos por-sitio que
hoy sacamos de MainWP**. Implementado **monitor SSL** en el plugin (`imagina_reports_agent_ssl`): abre una conexión TLS al propio
dominio (`stream_socket_client ssl://`, captura el cert, `openssl_x509_parse`) y devuelve caducidad/`days_until_expiry`/emisor/validez
— una lectura en el origen, sin servicios externos. Conector: +2 métricas (`site_agent.ssl_days_remaining` escalar, `site_agent.ssl_status`
tabla); se ocultan si el sitio no es HTTPS o no se pudo verificar (`checked=false`). **296 tests (+3 asserts SSL) + PHPStan + Pint
limpios.** **Decisión registrada:** el SiteAgent ya cubre lo de MainWP por-sitio (updates core/plugins/themes, inventario de plugins,
salud, y ahora SSL); lo ÚNICO que el agente NO hará es «plugins abandonados» porque requiere consultar wp.org y la regla de oro prohíbe
llamadas externas desde el agente → ese signo se queda en el lado Laravel o en MainWP. **Pendiente del owner:** desplegar v1.13.41,
reinstalar plugin (v1.4.0), validar SSL. Sigue pendiente el **2º lote 🔌** (logins de plugins de seguridad, forms con tabla propia,
imágenes optimizadas — vía /diagnostics).


**📊 AGENTE IMAGINA — LOTE GRANDE DE MÉTRICAS A/B/C/D (2026-06-25, rama `claude/github-app-analysis-a7b2bd`, plugin v1.3.0 →
release v1.13.40):** el owner pidió (AskUserQuestion) los 4 grupos: Seguridad, Captación/leads, Rendimiento/limpieza, E-commerce
operativo. Como el agente corre dentro de WP, casi todo es **dato del core (sin adivinar plugins)**. **Plugin (nuevas secciones del
payload, todo conteos agregados §3.3):** `security` (admins vía count_users, usuarios nuevos del periodo, **spam Akismet** total +
del periodo, banderas hardening: blog_public/DISALLOW_FILE_EDIT/WP_DEBUG/https), `performance` (caché de objetos Redis/Memcached +
caché de página por plugin activo, **cron atrasado** vía _get_cron_array, limpieza de BD: autoload/revisiones/papelera/spam/trash/
transients caducados, disco libre/total), `content` (posts/páginas publicadas, comentarios recibidos/aprobados del periodo, columnas
*_gmt), `leads` (**Contact Form 7 vía Flamingo** = post_type `flamingo_inbound`, total + periodo; otros forms con tabla propia
quedan para /diagnostics), `ecommerce` (si WooCommerce: stock agotado/bajo vía postmeta, pedidos pending/processing vía
`wc_get_orders` compatible HPOS). **Conector:** +18 métricas al catálogo (escalares + 3 tablas: security_audit, performance_status,
db_cleanup); leads/ecommerce → null si no hay plugin/Woo (bloque se oculta). Plantilla nueva «Seguridad, rendimiento y captación
(Agente)». **296 tests (+2 en SiteAgentConnectorTest) + PHPStan max + Pint + typecheck + lint(2 warns preexistentes) + build
limpios.** **Pendiente del owner:** desplegar v1.13.40, reinstalar el plugin (v1.3.0) y validar. **CI:** el release v1.13.40 salió
verde; el CI inicial falló por Pint (lintaba `wp-plugin/` con preset Laravel y chocaba con WordPress Coding Standards: `array()`,
`<?php endif; ?>` en plantillas) → corregido excluyendo `wp-plugin` en `pint.json` (es plugin WP independiente, no app Laravel
autoload). **2º lote 🔌 (requiere /diagnostics
por plugin, NO adivinar §0):** logins bloqueados (Wordfence/Limit Login/Solid), leads de WPForms/Gravity/Forminator (tabla propia),
imágenes optimizadas (Smush/ShortPixel/Imagify).

**✅ AGENTE IMAGINA — WPVIVID EN LA NUBE RESUELTO (2026-06-25, rama `claude/github-app-analysis-a7b2bd`, plugin v1.2.0 → release
v1.13.38):** el owner corrió `/diagnostics` en un sitio real. Estructura confirmada de WPvivid: **`wpvivid_backup_reports`** =
objeto keyed por task id, cada registro con **`backup_time`** (timestamp) — persiste aunque el backup se suba a Google Drive y se
borre el local (`wpvivid_backup_list` estaba vacío, por eso no servía). También `mainwp_lasttime_backup_wpvivid` (última fecha) y
`wpvivid_remote_list`/`wpvivid_new_remote_list` (destinos). UpdraftPlus confirmado: `updraft_backup_history` keyed por timestamp
con subclaves `*-size` (mi lector ya lo cubría; 114 entradas en ese sitio). **Implementado en el plugin (sin adivinar, contra el
shape real):** `imagina_reports_agent_wpvivid_history()` lee `wpvivid_backup_reports` → entradas {mtime, size defensivo, provider
WPvivid, location}; fallback a `mainwp_lasttime_backup_wpvivid`; `imagina_reports_agent_wpvivid_destination()` mapea SOLO el tipo
del remoto a etiqueta (google_drive→Google Drive…), nunca el token, fallback «Remoto». Integrado en `imagina_reports_agent_backups`
con skip de carpeta por proveedor cubierto por historial (evita doble conteo, genérico para UpdraftPlus+WPvivid). Sin cambios de
PHP de la app (el conector ya mapea provider/location); 294 tests siguen verdes; plugin lint OK. **Pendiente del owner:** desplegar
v1.13.38, re-descargar/reinstalar el plugin (v1.2.0) y validar que WPvivid→Drive ahora muestra respaldos (fecha/antigüedad/destino).
El **endpoint `/diagnostics`** queda en el plugin (gateado por clave, útil a futuro). Nota: tamaño de WPvivid puede salir null (no
siempre lo guarda); fecha/conteo/destino sí.

**🔬 AGENTE IMAGINA — DIAGNÓSTICO PARA WPVIVID→GOOGLE DRIVE (2026-06-25, rama `claude/github-app-analysis-a7b2bd`):** el owner
configura **casi todos sus sitios con WPvivid → Google Drive** (sin copia local), así que el escaneo de disco no los ve y mi
lector de UpdraftPlus no aplica. NO voy a adivinar cómo guarda WPvivid su historial (§0). Añadí al plugin (v1.1.0) un endpoint
**`GET /wp-json/imagina-reports/v1/diagnostics`** (gateado por la misma clave) que sondea opciones (`wp_options LIKE %wpvivid%`/
`%updraft%`) + tablas (`SHOW TABLES LIKE`) y devuelve la **ESTRUCTURA** (claves + tipos, con fechas/timestamps visibles por ser
útiles y seguros) de las opciones con pinta de lista de respaldos, **nunca los valores** (helper `imagina_reports_agent_shape`,
salta nombres con remote/setting/token/secret/auth/key → no filtra el token de Google Drive). **Próximo paso (BLOQUEANTE para
WPvivid-nube):** el owner instala el plugin en un sitio WPvivid+GDrive y hace GET a `/diagnostics` con su clave; con la estructura
real implemento `imagina_reports_agent_wpvivid_history()` igual que hice con UpdraftPlus. Hasta entonces, WPvivid solo se ve si
deja copia local. Plugin lint OK; sin cambios de PHP de la app (294 tests siguen verdes). → release v1.13.37.

**⬇️ AGENTE IMAGINA — DESCARGA 1-CLICK + BACKUPS EN LA NUBE (2026-06-25, rama `claude/github-app-analysis-a7b2bd`):** dos
peticiones del owner sobre el agente. **(1) Descarga del plugin a 1 click desde la app:** nuevo `SiteAgentController@download`
(ruta auth `GET /api/v1/system/site-agent/download`) que **zipea al vuelo** `wp-plugin/imagina-reports-agent/` (Finder→ZipArchive,
anidado bajo carpeta para que instale limpio) y lo sirve con `deleteFileAfterSend`; helper TS `downloadSiteAgentPlugin()` (blob →
descarga) + botón «⬇ Descargar plugin del agente» en el panel de guía del conector `site_agent` (`DataSourcesScreen`). Test
`SiteAgentDownloadTest` (sirve zip auth; 401 sin auth). **(2) Backups en la nube (Google Drive/Dropbox/S3) sin copia local:** el
escaneo de disco solo veía copias locales. Ahora el plugin **también lee el historial de UpdraftPlus** (`updraft_backup_history`,
keyed por timestamp) que registra el respaldo y su **destino remoto aunque el archivo local se haya subido y borrado** →
`imagina_reports_agent_updraft_history()` + mapeo de `service` a destino legible (Google Drive, Dropbox, S3…). Para UpdraftPlus el
historial es autoritativo (se omite el escaneo de su carpeta para no contar doble); el resto de plugins (WPvivid, BackWPup, etc.)
siguen por escaneo de disco. El payload gana `last_backup_location` + `location` por entrada; el conector añade fila «Destino» en
`backup_status` y columna «Destino» en `recent_backups`. **294 tests + PHPStan max + Pint + typecheck + lint(2 warns
preexistentes) + build limpios.** **Pendiente del owner:** validar con su UpdraftPlus real (el shape de `*-size` en el historial
varía entre versiones — la fecha/destino son fiables; el tamaño puede salir nulo y el bloque lo tolera). WPvivid solo-nube (sin
copia local) aún no se detecta — si lo usa así, avisar y confirmo el almacenamiento de WPvivid para añadirlo.

**🔌 AGENTE IMAGINA (PLUGIN DE SITIO) — BACKUPS REALES + SALUD DEL SITIO (2026-06-25, rama `claude/github-app-analysis-a7b2bd`):**
cerrado el hilo de backups. Confirmado vía curls (el owner): **MainWP NO expone los backups de WPvivid/UpdraftPlus** —
`/pro-reports/{dom}/backups?action=created` solo cuenta los backups que gestiona el propio MainWP (`[backup.created.count]`,
vacío con 18 meses de rango); no existe sección `wpvivid`/`updraftplus` (404); la API **v1** `/mainwp/v1/pro-reports/backups`
autentica con consumer key/secret pero pide un param `data` que no documenta — callejón. El owner pidió la vía correcta: **un
plugin propio en cada sitio** que recoja los datos y la app los saque (modelo **pull**, elegido por encajar con todos los demás
conectores; el sitio WP ya es alcanzable por HTTPS). Como escribimos AMBOS extremos, no hay shapes que adivinar (desaparece el
riesgo §0). **Construido:**
- **Plugin WordPress** `wp-plugin/imagina-reports-agent/` (PHP plano, sin deps): endpoint `GET /wp-json/imagina-reports/v1/metrics`
  autenticado con clave (cabecera `X-Imagina-Key` o `?key=`, comparada con `hash_equals`), página de Ajustes que genera/muestra/
  regenera la clave. **Backups medidos escaneando las carpetas en disco** (`wp-content/updraft`, `wpvividbackups`, `ai1wm-backups`,
  `backwpup-*`, `backupwordpress-*`, `wp-snapshots`) → último backup (mtime), antigüedad, tamaño, total y conteo en el periodo —
  provider-agnóstico, agrega en origen (§3.3), no abre archivos. Además salud del sitio: versiones WP/PHP/MySQL, tema, plugins
  (activos/inactivos/total) desde `get_plugins`, actualizaciones desde los **transients ya presentes** (sin llamar a WP.org),
  almacenamiento (BD vía `SHOW TABLE STATUS`, subidas vía iterador).
- **Conector** `App\Connectors\SiteAgent\SiteAgentConnector` (tipo enum `site_agent`, registrado, visible en el picker, con
  `ProvidesSetupGuide`). Catálogo: backups (count periodo/total, días desde último, tamaño último/total, tabla estado, tabla
  recientes) + salud (tabla site_health, plugins_*, updates_*, db/uploads_size_mb). URL = config `url` o la del sitio +
  `/wp-json/imagina-reports/v1/metrics`; gateado por métrica pedida (vacío = todo); escalares null→null (bloque se oculta).
- Plantilla de galería **«Sitio y respaldos (Agente Imagina)»**. Tests: `SiteAgentConnectorTest` (13 casos: catálogo, mapeo,
  cabecera+periodo, override de URL, gating, null, fallo HTTP, testConnection ok/clave inválida); +asserts en
  ConnectorRegistration y ConnectorApi. **292 tests + PHPStan max + Pint + typecheck + lint(2 warns preexistentes) + build limpios.**
  **Pendiente del owner:** desplegar; instalar el plugin en un sitio (zipear la carpeta), copiar la clave, añadir la fuente «Agente
  Imagina (sitio)», probar conexión y validar respaldos/salud reales. Nota: solo mide backups con copia **local** en wp-content.

**🗑️ BACKUPS ELIMINADO — LIMITACIÓN DE MAINWP (2026-06-24, rama `claude/github-app-analysis-a7b2bd` → release v1.13.34):** el owner
preguntó cómo elegir WPvivid vs UpdraftPlus en backups. Descubrimiento (curl a `imaginawp.com`, que tiene backups al día):
`/pro-reports/{dom}/backups?action=created` devuelve **`[backup.created.count]:0` con `sections_data` vacío AUN con backups al
día**. → **MainWP solo cuenta los backups que ÉL gestiona/dispara, nunca los de plugins de terceros** (WPvivid/UpdraftPlus) que
respaldan por su cuenta en el sitio. No hay parámetro de proveedor; el dato no existe en la API. Mostrar «0 respaldos» a un
cliente con backups diarios es engañoso → **quitada la métrica `mainwp.backups_count`** (catálogo + bucle de contadores),
eliminada la plantilla «Respaldos y mantenimiento», y la plantilla «Mantenimiento» revertida a KPIs siempre reales
(updates_applied/available, plugins_active/total). Se mantiene `mainwp.maintenance_count` (es la herramienta de mantenimiento
NATIVA de MainWP, sí legítima). 283 tests + PHPStan + Pint + tsc + build limpios. **Si el owner algún día configura un método
de backup gestionado por MainWP, se podría reintroducir.**

**🦠 VIRUSDIE PLEGADO EN MAINWP (2026-06-24, rama `claude/github-app-analysis-a7b2bd` → release v1.13.33):** el owner preguntó si
hacía falta VirusDie como fuente aparte si viene de MainWP. Respuesta: no — usa el mismo `dashboard_url`+token y el endpoint
per-site `/pro-reports/{dom}/virusdie?action=scan` (devuelve `[virusdie.scan.count]`, mismo patrón que backups/maintenance). **Plegado
en `MainWpConnector`** como **`mainwp.malware_found`** (una línea más en el bucle `proReportCount`). Migradas las 3 referencias:
`HealthScoreCalculator` (señal de seguridad), `BlockResolver::securityMetrics` (escudo), y la plantilla «Antimalware (VirusDie)»
→ todas leen `mainwp.malware_found`. **VirusDie oculto como fuente** (añadido a `ConnectorController::HIDDEN=['crowdsec','virusdie']`);
el `VirusdieConnector` queda registrado pero **inerte/deprecado** (reversible, no lo borré). Sync no filtra métricas
(PreviewController despacha con requested=[] → MainWP trae todo), así que el malware siempre se sincroniza → health/escudo OK.
Tests migrados (HealthScore usa bag `mainwp`; ConnectorApi asercia que virusdie no se lista; MainWp cubre malware_found).
283 tests + PHPStan + Pint + tsc + build limpios.

**🧹 PULIDOS POST-VALIDACIÓN (2026-06-24, rama `claude/github-app-analysis-a7b2bd` → release v1.13.32):** el owner reportó 4 cosas
al validar: (1) **CrowdSec fuera por ahora** — quitada la plantilla CrowdSec de la galería (reemplazada por «Respaldos y
mantenimiento») y los bloques CrowdSec de la plantilla «Seguridad» (→ ahora vulnerabilities_count + security_checklist de
MainWP); CrowdSec **oculto como fuente añadible** vía lista `HIDDEN=['crowdsec']` en `ConnectorController` (conector sigue
registrado e inerte, reversible; no toqué BlockResolver/HealthScore). Nota: `app/Reports/Templates/DefaultTemplate.php` aún
tiene un bloque CrowdSec dormido (se auto-oculta sin datos) — no es de galería, lo dejé. (2) **Backups no se veía** → nueva
plantilla dedicada «Respaldos y mantenimiento (MainWP)» (backups_count + maintenance_count + updates_applied + timeline); la
métrica ya existía y se oculta sola si el endpoint da null/sin datos. (3) **SSL días negativos (-553)** = escaneo viejo de
MainWP (certs auto-renuevan); ahora si `ssl_days_remaining`/`domain_days_remaining` < 0 se trata como **dato obsoleto → se
oculta** (no se muestra contador negativo). +1 test. **Owner debe re-escanear SSL Monitor en MainWP** para ver días reales.
(4) **Selector de métricas filtra por fuente** en el inspector (`EditorScreen`/`Inspector.tsx`): nuevo desplegable «Fuente»
(default = fuente del binding) que reduce la lista a esa fuente, además del buscador de texto. 283 tests + PHPStan + Pint + tsc
+ eslint(2 warnings preexistentes) + build limpios.

**📱 EDITOR RESPONSIVE EN MÓVIL (2026-06-24, rama `claude/github-app-analysis-a7b2bd` → release v1.13.31):** el owner reportó que
el editor de plantillas no era responsive en celular: el panel derecho no se podía ocultar y la barra superior cortaba opciones
(el botón **Sincronizar** no aparecía). Causa real: el grupo de acciones `ml-auto` de la barra superior **no envolvía** y era más
ancho que el viewport → se salía por la derecha ocultando Sincronizar + el toggle del panel derecho. Arreglos en
`EditorScreen.tsx`: (1) `ml-auto` ahora `flex-wrap justify-end` (envuelve en varias filas, nada se oculta); (2) control
sitio/periodo encoge en móvil (`min-w-0`, select `max-w-[7.5rem] sm:[10rem]`, mes `w-[7rem] sm:[8.5rem]`); (3) input de nombre
`w-28 min-w-0 sm:w-52`; (4) **backdrop** en móvil (`lg:hidden`) detrás de los paneles overlay → tocar fuera cierra ambos (resuelve
«el derecho no se desaparece»). Los paneles ya arrancaban cerrados en móvil (`wideViewport`=innerWidth≥1024) y seleccionar bloque
no fuerza abrir el inspector — el único bug era el desbordamiento de la barra. tsc + eslint + build limpios. **Pendiente owner:**
validar en celular real tras desplegar.

**🛡️ ESTADO DE SEGURIDAD (bonus) + lote extensiones MainWP (2026-06-24, release v1.13.30):**
cerrado el lote de extensiones MainWP que el owner pidió. Método: descubrir el shape real vía curl (índice de rutas +
endpoints Pro Reports), nunca adivinar (§0). **Aprendizaje clave:** los endpoints `/pro-reports/{id_domain}/{seccion}`
exigen un `action`; el servidor **revela los válidos en el mensaje de error** (`Required valid action parameter: ...`).
Implementado en `MainWpConnector` (mismas credenciales, gateado por métrica pedida):
- **Vulnerability Checker** (`/vulnerable`, sin action): `mainwp.vulnerabilities_count` (= `[vulnerabilities.count]`, p.ej. 37)
  + `mainwp.vulnerabilities_list` (tabla, parsea las líneas `slug: fecha` del blob HTML, ignora las descripciones CVE).
- **Wordfence** (`/wordfence?action=scan`; válidos scan/issue/blocked): `mainwp.wordfence_scans_count` + `mainwp.wordfence_scans`
  (tabla Fecha/Detalle desde `sections_data`, tokens `[wordfence.scan.date/time/details]`). issue/blocked NO implementados
  (blocked vino vacío, issue sin probar).
- **Backups** (`/backups?action=created`): `mainwp.backups_count` (= `[backup.created.count]`). Cubre UpdraftPlus + WPvivid.
- **Maintenance** (`/maintenance?action=process`): `mainwp.maintenance_count` (= `[maintenance.process.count]`).
- Helper `proReportCount()` compartido para los counters tipo `other_tokens_data`. Plantillas nuevas: «Vulnerabilidades»,
  «Seguridad Wordfence»; «Mantenimiento» ahora muestra Respaldos/Mantenimientos.
**🦠 VIRUSDIE ARREGLADO:** el conector apuntaba a `/virusdie/summary` (NO existe en el índice de rutas). Reescrito al endpoint
real per-site `/pro-reports/{dom}/virusdie?action=scan` → `virusdie.malware_found` (= `[virusdie.scan.count]`). Catálogo
adelgazado a esa métrica honesta (las viejas eran del endpoint falso); plantilla VirusDie y test actualizados.
**Database Updater: SIN datos** — esa extensión (buscar/reemplazar BD) no expone reporte Pro Reports (confirmado). Excluida.
283 tests + PHPStan max + Pint + tsc + build limpios. **Pendiente del owner:** (1) los counts salieron 0 en omicron (sin
backups/mantenimientos/malware en el rango — normal); validar con un sitio/rango que sí tenga actividad. (2) las tablas de
detalle (sections_data) de backups/maintenance/virusdie NO se ven con count 0 — si al validar aparece detalle, pasar el JSON
y lo añado. (3) **regenerar el token MainWP** (quedó en el chat). **Bonus HECHO:** «Estado de seguridad» — `/sites/{id}/security` (GET, sin action) → `mainwp.security_issues_count`
(nº de flags `N`) + `mainwp.security_checklist` (tabla Comprobación/Estado con semáforo ✓ Seguro / ⚠ Revisar / —).
**Polaridad verificada contra datos reales** de omicron (Y=seguro, N=issue; `Y_UNABLE`→no verificable): `sec_inactive_plugins:N`
casa con sus muchos plugins inactivos, `sec_outdated_themes:Y` con theme_upgrades vacío, etc. Plantilla «Estado de seguridad
(MainWP)». 282 tests verdes.

**🔐 MAINWP — SSL + DOMINIOS (extensiones) (2026-06-24, rama `claude/github-app-analysis-a7b2bd`):** el owner quería sacar más
datos de sus extensiones MainWP. Empezamos por **SSL Monitor + Domain Monitor** (su elección). Descubrimiento vía el índice
de rutas real (`/wp-json/mainwp/v2`, dado por el owner) + comando nuevo `mainwp:probe`: existen endpoints dedicados
**`GET /ssl-monitor/info`** y **`GET /domain-monitor/profiles`** (datos NO vienen en `/sites`). Forma real validada con su
panel: ambos devuelven `{success,data:{<site_id>:{...}}}`; SSL trae `valid_to`/`valid_from`/`issuer_o` (fechas `d/m/Y`),
dominio trae `expiry_date`/`registrar`/`creation_date`. **Implementado en `MainWpConnector`** (mismas credenciales, no es
fuente nueva): 3 métricas — `mainwp.ssl_days_remaining`, `mainwp.domain_days_remaining` (escalares, días) y `mainwp.ssl_domain`
(tabla Concepto/Proveedor/Caduca/Días). `lookupExtensionEntry()` busca por id de sitio→URL; `daysUntil()` parsea `d/m/Y` con
timestamps (robusto entre versiones de Carbon) y trata el centinela `31/12/1969` (sin datos) como null → bloque se oculta.
Llamadas **gateadas** (solo si el reporte pide esas métricas). Plantilla nueva **«SSL y dominios (MainWP)»**. +2 tests (deriva
días con tiempo congelado; ignora el centinela). 277 tests + PHPStan max + Pint + tsc + eslint + build limpios. **Pendiente
del owner:** (1) **OJO FRESCURA** — los `valid_to` reales de SSL salían de oct-2024/ene-2025 (MainWP no re-escanea seguido y
los certs Let's Encrypt/Google renuevan cada 90 días) → asegurar que la extensión SSL Monitor escanee con frecuencia o los
días saldrán negativos/erróneos. (2) **regenerar el token MainWP** (quedó en el historial del chat). **Backlog descubierto:**
MainWP tiene `/monitors` (uptime/heartbeat/incidents propios — posible reemplazo de Better Stack) y `/updates/{dominio}`
(pendientes por sitio más limpio). **Próximas extensiones a pedir shapes (curl):** Wordfence (`/pro-reports/{dom}/wordfence`),
Vulnerability Checker (`/pro-reports/{dom}/vulnerable`), backups (UpdraftPlus/WPvivid → `/pro-reports/{dom}/backups`).


**💡 PANTALLA DE OPORTUNIDADES DE UPSELL (2026-06-24, rama `claude/github-app-analysis-a7b2bd`):** el owner pidió una
pantalla en el admin para ver las oportunidades de upsell que hasta ahora solo salían en log + webhook `upsell.detected`.
**Construido (mismo patrón read-only que Tendencias, sin tabla nueva):** (1) `App\Reports\AgencyUpsell` — agregador que,
por cada sitio con reporte generado, toma el **último reporte**, carga los metric bags actual/anterior (`MetricBagLoader`,
mismo `forSite($period->previous())` que el listener para reflejar lo que disparó el webhook) + fuentes conectadas, corre
`UpsellDetector` y agrupa las oportunidades por sitio (más oportunidades primero); summary {sites_count,
sites_with_opportunities, opportunities_count}. (2) `UpsellController` + ruta auth `GET /api/v1/upsell`. (3) Frontend:
`UpsellScreen.tsx` (resumen + tarjeta por sitio con presentación **localizada en español** por tipo — crecimiento de
tráfico/ventas, presión de seguridad, huecos de cobertura uptime/seguridad — con frase explicativa + acción sugerida),
entrada de nav «Oportunidades» (icono `Lightbulb`), `AdminView` + `useUpsell()` + tipos. Internal-only (el cliente nunca
lo ve). **274 tests (+3 `UpsellApiTest`: traffic-growth, aislamiento de tenant, auth) + PHPStan max + Pint + tsc + eslint +
build limpios.** **Pendiente del owner:** tras release, validar en vivo (necesita reportes generados para poblar).


**🛡️ CROWDSEC — MODELO PUSH (2026-06-24, rama `claude/github-app-analysis-a7b2bd`):** la API de la CrowdSec Console (nube) es
**de pago**; la LAPI local es gratis pero CrowdSec corre en el VPS de **cada cliente**. El owner eligió (AskUserQuestion)
el **modelo push**: cada VPS ENVÍA sus datos de forma saliente (sin abrir puertos entrantes — lo más seguro). **Construido:**
(1) migración `push_token` (string único) en `ir_data_sources`; (2) interfaz `Connectors\Contracts\ReceivesPushedData`
(`fromPushedPayload(array): MetricSet`), implementada por `CrowdSecConnector` (extraje `normalizeAlerts()` compartido por
`fetch()` y push; acepta `{alerts:[...]}` o array crudo); (3) `SyncService::record()` público (store+outcome) reusado por
poll e ingesta; (4) **`IngestController`** + ruta pública throttled `POST /api/v1/ingest/{token}` (sin Sanctum; busca la
fuente por `push_token` — AgencyScope es no-op sin tenant; normaliza vía conector y guarda snapshot del mes actual, o el
período que mande el body); (5) `DataSourceController::decoratePush()` genera el token perezosamente y expone
`is_push`/`push_token`/`ingest_url` (resource); (6) `scripts/crowdsec-push.sh` (cron: `cscli alerts list -o json` →
POST saliente); (7) UI `PushInstallPanel` en DataSourcesScreen (URL + cron copiables, oculta «Probar» en push); setupGuide
reescrita. 271 tests + PHPStan max + Pint + tsc + eslint + vitest limpios. **Pendiente del owner:** desplegar, instalar el
script en un VPS de cliente con el cron, y verificar que llega el snapshot (estado pasa a «ok»). **Nota:** la vía bouncer
key + `/v1/decisions` (LAPI directa) NO se implementó — el push usa alerts; si más adelante quiere LAPI directa por red
privada (Tailscale), hay que adaptar auth `X-Api-Key`.

**🟧 CLOUDFLARE 401 — TOKEN TRIM + MENSAJE ACCIONABLE (2026-06-24, rama `claude/github-app-analysis-a7b2bd`):** el owner
probando Cloudflare ahora recibe `HTTP 401`. Causa: auth (token inválido/caducado o **pegado con espacio/salto de línea**
→ `Http::withToken` lo manda tal cual → Bearer malformado → 401). **Fix en `CloudflareConnector`:** (1) `trim()` del
`api_token` y `zone_id` en `query()` (cubre el whitespace al pegar). (2) helper `httpFailureMessage()`: 401/403 → mensaje
claro («token inválido, caducado o sin permiso… crea/renueva un API token con permiso Zone Analytics:Read y pégalo sin
espacios») en vez del crudo «HTTP 401»; usado en `testConnection()` y `fetch()`. Tests nuevos (401 accionable + trim).
265 tests + PHPStan + Pint limpios. **Pendiente del owner:** re-pegar/renovar el token (el merge de `update()` mantiene
el secreto si lo dejas en blanco, así que para cambiarlo hay que escribir el nuevo) y re-sincronizar.

**📱 APP RESPONSIVE (2026-06-24, rama `claude/github-app-analysis-a7b2bd`):** el owner reportó que el admin, los
reportes y en general toda la app no eran responsive. **Cambios (frontend only):** (1) **Shell admin** (`App.tsx`):
sidebar fijo → en `<lg` es un **drawer off-canvas** con backdrop + barra superior móvil con hamburguesa (`Menu`/`X`);
hook `useMediaQuery('(min-width:1024px)')`; el modo colapsado (icon-only) ahora es solo desktop (`iconOnly = isDesktop &&
collapsed`) y el drawer móvil siempre muestra labels; padding del contenido `ir-p-8` → `ir-p-4 sm:ir-p-6 lg:ir-p-8`.
(2) **Reportes** (portal + report page + PDF, single source of truth = `BlockRenderer.BlockList`): el grid de
coordenadas fijas (12 col, filas en px) se **apila a 1 columna en móvil** vía CSS `@media screen and (max-width:640px)`
sobre clases nuevas `.ir-report-grid` / `.ir-report-cell` (override de los inline `gridColumn/gridRow` con `!important`,
`height:auto`). **Clave:** scoping a `screen` → el PDF de Browsershot (print media, ~A4) **NO se ve afectado**, mantiene
el layout desktop pixel-perfect. Padding del reporte `ir-p-8` → `ir-p-4 sm:ir-p-8`; header del portal con `flex-wrap`.
(3) **Editor**: paneles laterales (palette/inspector, ya colapsables) ahora **overlay absoluto en móvil** (`ir-absolute
… lg:ir-static`) para no aplastar el lienzo; arrancan cerrados en móvil (`window.innerWidth>=1024`); toolbar con
`flex-wrap`. (4) **Pantallas**: grids de formulario `grid-cols-2/3` → `grid-cols-1 sm:grid-cols-N` (System, WorkLogs,
Trends); `Card` header con `flex-wrap`. `DataTable` ya tenía `overflow-x-auto`. typecheck + lint + 11 vitest + build
limpios. **Pendiente:** tras release, el owner valida en móvil real; el editor drag-drop sigue siendo desktop-first por
naturaleza (en móvil los paneles flotan, pero la edición táctil de bloques no es el caso de uso principal).


**🟩 CLOUDFLARE — CAMPO ROTO IDENTIFICADO Y RETIRADO (2026-06-24, rama `claude/github-app-analysis-a7b2bd`):** el owner corrió
la query COMPLETA y devolvió **un solo** error: `unknown field "pathingSource"`. Es decir, el único campo no válido del
schema `httpRequests1dGroups` era **`threatPathingMap { pathingSource }`** (el resto — `uniq.uniques`, `pageViews`,
`encryptedRequests`, `countryMap` — sí son válidos). **Fix:** retiré `threatPathingMap`/`pathingSource` del set completo y
eliminé la métrica `cloudflare.top_threat_sources` del catálogo + del procesado en `fetch()` + del bloque de la plantilla
«Cloudflare» (su lugar lo ocupa ahora `requests_by_country` a 12 col). Así la query completa **valida** y trae todos los
demás extras: visitantes únicos, páginas vistas, peticiones cifradas y los mapas de país (amenazas/peticiones por país).
El fallback a core sigue como red de seguridad. 263 tests + PHPStan max + Pint limpios. **Pendiente del owner:**
re-sincronizar Cloudflare → ya deben salir KPIs principales + únicos + por país. (Tras release, bump a la siguiente versión.)

**🟧 CLOUDFLARE — SUPERFICIE LOS ERRORES GRAPHQL (2026-06-24, rama `claude/github-app-analysis-a7b2bd`):** el owner reportó
Cloudflare todo en 0 pese a años conectado. Causa raíz del **silencio**: la API GraphQL devuelve **HTTP 200 aun con
`errors`** (campo inválido / falta permiso / token no ve la zona) y `data:null` → el conector parseaba grupos vacíos →
ceros, y `testConnection` decía «reachable» falsamente. **Fix:** helper `graphqlError()` que extrae `errors[].message`;
`fetch()` devuelve **`failed`** con el motivo real si no hay grupos y hay error; `testConnection()` ahora falla si hay
errores GraphQL o si **`data.viewer.zones` está vacío** (token sin acceso a la zona / Zone ID malo). Test nuevo. 262
tests + PHPStan + Pint limpios. **Pendiente del owner:** re-sincronizar → el panel de sync mostrará el error real de
Cloudflare (o correr la query GraphQL) para arreglar la causa concreta (permiso Analytics:Read, retención de plan, o
campo deprecado como `pageViews`). NO cambié la query todavía — espero el error real para no adivinar (§0).

**📊 GA4 — MÉTRICAS DE CIUDADES/GÉNERO/EDAD/HORA (2026-06-24, rama `claude/github-app-analysis-a7b2bd`):** el owner pidió
más métricas útiles de Analytics. Añadidas al catálogo GA4 (vía `specs()`): **`top_cities`** (ciudades), **`by_region`**,
**`by_language`**, **`by_gender`** y **`by_age`** (demografía — requieren Google Signals; si no, vienen vacías y el bloque
se oculta), **`sessions_by_hour`** (visitas por hora, serie 0–23) y **`sessions_by_weekday`**. Además, `body()` ahora
manda **`orderBys`**: tablas = top-N por la métrica desc; series = orden cronológico por su dimensión (hora/día) — mejora
todas las tablas/series existentes. Plantilla «Analítica web (GA4)» suma tabla de **Ciudades** + gráfica de barras
**«Visitas por hora del día»**. Test de catálogo ampliado. 261 tests + PHPStan + Pint + TS+build limpios.

**🔑 GA4/GSC — BUG DE CREDENCIALES (cuenta de servicio) ARREGLADO (2026-06-24, rama `claude/github-app-analysis-a7b2bd`):**
el owner no podía conectar GA4: «GA4 authentication failed: json key is missing the client_email field». Causa: el
formulario guarda el JSON pegado como **string** bajo `credentials['service_account']`, pero el conector pasaba **todo
el bag de credenciales** (`['service_account' => '<string>']`) al proveedor de token, que espera el **JSON de la cuenta
de servicio ya decodificado** (con `client_email`/`private_key`). Por eso nunca encontraba `client_email` (los tests
pasaban porque ponían los campos SA al nivel raíz de credentials). **Fix:** helper `serviceAccount()` en Ga4 y Gsc que
extrae `credentials['service_account']` y lo **json_decodifica** si es string (acepta también el formato ya-decodificado).
Se amplió el tipo a `array<array-key,mixed>` en interfaz/impl/fake. Test nuevo (decodifica string → entrega SA con
client_email). 261 tests + PHPStan + Pint limpios. **Nota al owner:** asegúrate de pegar el JSON de **cuenta de
servicio** (tiene `client_email`), no el de cliente OAuth.

**🟩 BETTER STACK — BARRA VERDE/ROJA DE DISPONIBILIDAD POR DÍA (2026-06-24, rama `claude/github-app-analysis-a7b2bd`):**
el owner pidió la barra verde/roja de uptime del status page. La derivo de los **incidentes** (sin llamada API extra,
1 sola al endpoint `/incidents` ya usado): nueva serie **`betteruptime.uptime_by_date`** = disponibilidad diaria
(100 − solape de downtime/86400) por cada día del periodo. El `ChartBlock` del renderer ahora colorea **cada barra por
umbral** (`style.threshold`: ≥ umbral → verde `#16a34a`, debajo → rojo `#dc2626`) y fija el eje Y a 0–100 — réplica de
la barra del status page. Template «Disponibilidad y SLA»: barra «Disponibilidad por día (%)» (umbral 100) arriba, luego
la gráfica de respuesta, media e incidentes. Refactoricé incidentes para parsear una sola vez (tabla + barra comparten
el fetch). Tests +1 (serie diaria: 30 puntos, día con 36 min caído → 97.5%). 260 tests + PHPStan + Pint + TS+build
limpios.

**❤️ HEALTH SCORE — SEÑAL DE SEGURIDAD VIVA (2026-06-24, rama `claude/github-app-analysis-a7b2bd`):** el owner preguntó
por qué el gauge de salud daba siempre 100. Explicación: combina uptime(30%)+updates(25%)+seguridad(25%)+
rendimiento(20%) re-pesando lo que falta; para un sitio sano con solo Better Stack (99.9%) + 0 updates pendientes da
~100. **Bug encontrado:** la señal de **seguridad** apuntaba a **`mainwp.ssl_expiring`**, métrica que **eliminé** en la
reescritura por-sitio de MainWP → estaba muerta (siempre null/ignorada). La reemplacé por **`virusdie.malware_found`**
(malware detectado → 40; limpio → 100; sin VirusDie → no cuenta). Ahora el score reacciona a seguridad real. Tests
actualizados (calculator + ReportGenerator: el caso mixto pasa de 93 a 85, más honesto al no regalar 100 de seguridad).
259 tests + PHPStan + Pint limpios.

**🌎 ZONA HORARIA POR CLIENTE → INCIDENTES EN HORA LOCAL (2026-06-24, rama `claude/github-app-analysis-a7b2bd`):** las
fechas de incidentes salían en UTC; el owner las quiere en la hora del cliente (depende de su país). Añadido campo
**`timezone`** (IANA) al **cliente**: migración `ir_clients.timezone` (nullable), fillable/resource/validación
(`'nullable','timezone'` en Store+Update). El **BetterUptimeConnector** resuelve `data_get($source,'site.client.timezone')`
(default UTC) y formatea «Inicio» en esa zona → «10/06/2026 05:00 GMT-05:00». Frontend: `timezones.ts` (lista
LATAM-first), selector en el **formulario de edición de cliente** + columna «Zona horaria» en la tabla. Tipo `Client`
y hook `useUpdateClient` actualizados. Tests +2 (UTC y America/Bogota). 259 tests + PHPStan + Pint + TS+build limpios.
Nota: la hora se hornea en el snapshot al sincronizar (re-sincroniza si cambias la zona).

**🚨 BETTER STACK — TABLA DE INCIDENTES (2026-06-23, rama `claude/github-app-analysis-a7b2bd`):** el endpoint correcto es
**`/api/v2/incidents?monitor_id=`** (no `/monitors/{id}/incidents`). Con el JSON real (`data[].attributes`:
`started_at`, `resolved_at`, `cause`, `status`) añadí **`betteruptime.incidents_list`** (tabla Inicio/Duración/Causa/
Estado; duración humanizada «32 min»/«1 h 5 min» calculada de started→resolved; «En curso» si sin resolver; fechas en
UTC; filtrada al periodo por `started_at`; `per_page=50`, newest-first). Template «Disponibilidad y SLA» ahora incluye
la **tabla «Incidentes del periodo»** bajo la gráfica. Llamada gateada; fallo → tabla vacía, no tumba el SLA. Test
nuevo (filtro de periodo + duración). 258 tests + PHPStan + Pint + TS+build limpios. **Better Stack queda completo**:
SLA + duraciones min/h + gráfica de respuesta + incidentes.

**📈 BETTER STACK — GRÁFICA DE TIEMPOS DE RESPUESTA (2026-06-23, rama `claude/github-app-analysis-a7b2bd`):** con el JSON
real de `/monitors/{id}/response-times` (regiones → `response_times[]` con `at` + `response_time` en **segundos**, ej.
0.028 = 28 ms) añadí: **`betteruptime.response_times`** (serie, promedio **diario** en **ms**, agregada en el
conector sobre todas las regiones/puntos → ≤31 puntos) y **`betteruptime.avg_response_time`** (ms, media global). El
template «Disponibilidad y SLA» ahora incluye la **gráfica de área «Tiempo de respuesta (ms)»** + KPI de respuesta
media, al estilo de las status pages de Better Stack. Solo se piden esas métricas si el reporte las usa (llamada
extra gateada); un fallo ahí no tumba el SLA. **Incidentes (tabla):** el endpoint `/monitors/{id}/incidents` **no
existe** en su plan (404 confirmado), así que NO se implementó — quedaría para cuando exista el endpoint correcto.
Test nuevo (serie diaria + media). 257 tests + PHPStan + Pint + TS+build limpios.

**⏱️ BETTER STACK — DURACIONES EN MIN/H (2026-06-23, rama `claude/github-app-analysis-a7b2bd`):** el owner notó que el
tiempo caído salía en **segundos** (ilegible para un cliente). Nuevo formato **`duration`** en el `BlockRenderer`
(segundos → «30 s» / «45 min» / «1 h 30 min»), añadido al selector de formato del Inspector. Las KPIs de tiempo
caído/incidente más largo del template «Disponibilidad y SLA» ahora usan `format:'duration'`; el catálogo de Better
Stack relabela `total_downtime`/`longest_incident`/`average_incident` (sin «(s)») con unidad `duration`. Los valores
siguen en segundos (precisos); solo cambia la presentación. **Pendiente (esperando muestras del owner):** enriquecer
Better Stack con **serie de tiempos de respuesta** (gráfico) e **incidentes** (tabla) al estilo de sus status pages —
pedí el JSON de `/api/v2/monitors/{id}/response-times` y `/api/v2/incidents?monitor_id=` para no inventar la forma.
TS+build+PHPStan+Pint limpios.

**📖 GUÍAS «CÓMO CONECTAR» POR CONECTOR (2026-06-23, rama `claude/github-app-analysis-a7b2bd`):** el owner no encontraba
el tutorial de cómo conectar GA4 (ni los demás). Nueva capability opcional **`ProvidesSetupGuide`** + value object
**`SetupGuide`** (intro + pasos numerados + `docs_url`). Implementada en **los 8 conectores** (GA4 con el flujo completo
de cuenta de servicio de Google Cloud paso a paso; GSC, Cloudflare, Better Stack, CrowdSec, VirusDie, WooCommerce,
MainWP). `ConnectorController` expone `guide` (null si el conector no la trae). Frontend: `DataSourcesScreen` muestra un
panel desplegable **«Cómo conectar {conector}»** (intro + lista ordenada + link a docs oficiales) tanto en el formulario
de **alta** como en el de **edición**. Tipo `ConnectorGuide` añadido. Test del endpoint (+1). 256 tests + PHPStan + Pint
+ TS+ESLint+build limpios.

**✏️ EDITAR/ELIMINAR FUENTES DE DATOS Y CLIENTES (2026-06-23, rama `claude/github-app-analysis-a7b2bd`):** faltaba poder
**editar una fuente** (cambiar URL/clave/token caducado) o **eliminarla**, y **editar/eliminar clientes** — solo
existían crear/listar. (Sitios ya tenían edición de URL.) **Backend:** `PUT/DELETE /data-sources/{ds}` y
`PUT/DELETE /clients/{client}` + FormRequests. La actualización de fuente **mezcla credenciales**: un campo secreto en
blanco **conserva** el actual (ojo: `ConvertEmptyStringsToNull` convierte `""`→`null`, así que se ignoran ambos) y al
cambiar config/credenciales resetea `status` a `pending` para re-test. Borrar fuente **cascadea** sus snapshots (FK).
Borrar cliente se **rechaza (422)** si aún tiene sitios (evita borrado masivo en cascada). **Frontend:** `DataSourcesScreen`
gana formulario **Editar** (precarga la config no-secreta; los secretos van con placeholder «déjalo vacío para
conservar») + **Eliminar** (con confirm) y muestra etiqueta del conector + `last_error`; `ClientsScreen` gana
**Editar** (nombre/email/idioma/notas) + **Eliminar**. Hooks `useUpdate/DeleteDataSource`, `useUpdate/DeleteClient`.
255 tests (+8) + PHPStan + Pint + TS+ESLint+Vitest(11)+build limpios.

**🧷 EDITOR — PLANTILLAS: AÑADIR DEBAJO vs REEMPLAZAR (2026-06-23, rama `claude/github-app-analysis-a7b2bd`):** el
owner quería componer un **informe unificado** tomando partes de varias plantillas, pero al hacer clic en una de la
galería **siempre reemplazaba** el lienzo. Ahora: si el lienzo está esencialmente vacío (≤1 bloque) la plantilla se
carga directo; si ya hay contenido, aparece un **diálogo** con 3 opciones — **«Añadir debajo»** (apila los bloques de
la plantilla bajo lo existente en la página actual, desplazando su `y` por el alto acumulado y reasignando página;
ids ya únicos vía `makeBlock`), **«Reemplazar todo»** (comportamiento anterior) y **«Cancelar»**. «Añadir» usa
`commit` (entra en el historial undo/redo); «Reemplazar» usa `resetBlocks`. TS+ESLint+Vitest(11)+build limpios.

**🧩 MAINWP CHILD REPORTS — DETECCIÓN + AVISOS (2026-06-23, rama `claude/github-app-analysis-a7b2bd`):** el owner
preguntó por qué imaginawp marcaba 0 actualizaciones aplicadas si actualiza cada semana. Diagnóstico: el historial
fechado lo registra el plugin **MainWP Child Reports** en el sitio hijo (lo confirma que comercializadoraomicron —con
166 updates— tiene ese plugin entre sus filas, e imaginawp no). Sin Child Reports, el endpoint Pro Reports
`action=updated` viene vacío aunque el sitio lleve años conectado y se actualice. Implementado: (1) el conector
detecta **`mainwp.child_reports_active`** (1/0) buscando ese plugin activo en el inventario `plugins` del sitio, y lo
expone en el catálogo; (2) `DataSourceController@index` adjunta el flag desde el último snapshot y `DataSourceResource`
lo publica (`child_reports_active`, null para no-MainWP); (3) el **panel de sincronización** muestra bajo la fuente
MainWP un aviso ámbar «Instala MainWP Child Reports…» si está inactivo, o verde «Child Reports activo» si lo está;
(4) el **bloque vacío** de `work_log` (en `CanvasBlock`) muestra el aviso específico de Child Reports en vez del
genérico «Sin datos». Nota: Child Reports **no rellena hacia atrás** — registra desde que se instala; el KPID de
conteo tiene además el respaldo del diff de snapshots (`MaintenanceDeltaCalculator`). 247 tests + PHPStan + Pint +
TS+ESLint+Vitest(11)+build limpios.

**🚫 EDITOR — FIN DE DATOS FALSOS EN MODO REAL + ESTADO «SIN DATOS» (2026-06-23, rama `claude/github-app-analysis-a7b2bd`):**
el owner mostró una incoherencia: con un sitio real (imaginawp, 3 fuentes) los **KPIs** salían en 0 pero las **tablas**
mostraban filas (WooCommerce/Astra/… = mis placeholders de muestra). Causa: el fallback por-bloque a `sampleData`
(del fix anterior) inyectaba datos de muestra en bloques vacíos **incluso en modo datos-reales**, contradiciendo los
KPIs reales. **Arreglo:** el fallback a muestra ahora **solo** aplica en modo diseño (sin sitio/sin preview); en modo
real se muestran los datos reales tal cual. Para que un bloque vacío no desaparezca ni mienta, `CanvasBlock` pinta un
**estado honesto «Sin datos para este periodo»** (con el título del bloque, recuadro punteado) para bloques de datos
(`DATA_BLOCKS`) sin valor — visible y seleccionable, pero sin inventar filas. Así imaginawp (sin historial) muestra
0/0 + tablas «Sin datos» coherentes; comercializadoraomicron (con 166 updates) mostrará las filas reales al
sincronizar. Además, la 4ª KPI del template «Mantenimiento» pasa de **`health_score`** (MainWP devolvía un confuso
**-12**, escala sin verificar) a **`plugins_total`** («Plugins instalados»), inequívoca; `health_score` sigue en el
catálogo para quien lo quiera. TS+ESLint+Vitest(11)+build limpios.

**🔄 EDITOR — PANEL DE SINCRONIZACIÓN CON ESTADO REAL (2026-06-23, rama `claude/github-app-analysis-a7b2bd`):** el
owner señaló que el botón «Sincronizar» era opaco (no decía qué fuentes, cuándo fue la última sync, si terminó, si
fue en tiempo real, ni si alguna falló). Los datos ya existían en `ir_data_sources` (`status`/`last_synced_at`/
`last_error`, expuestos por `DataSourceResource` y `GET /sites/{site}/data-sources`) — solo no se mostraban. Nuevo
componente **`SyncStatus.tsx`**: botón + panel desplegable que lista **cada fuente** del sitio con su **estado**
(✓ ok / ✗ error / ⏳ pendiente / spinner en curso), **última sincronización** en relativo ("hace 2 min"), el
**mensaje de error** si falló, y un resumen "N/total al día". Al sincronizar captura un **baseline** de
`last_synced_at` por fuente y **detecta fin por avance** de ese sello (robusto a desfase de reloj; `SyncService`
siempre lo estampa, ok o error), con timeout de 45 s; muestra progreso `done/total` en vivo (poll cada 2 s) y al
terminar refresca la vista previa (`onSynced`). Se quitó el `triggerSync` con delays fijos y el `useSyncSite`/
`RefreshCw` huérfanos de `EditorScreen`; `useSiteDataSources` admite `refetchInterval`. TS+ESLint+Vitest(11)+build
limpios. Responde directo a las dudas del owner: qué fuente, hasta qué fecha, en tiempo real, y si solo una sincronizó.

**🖼️ EDITOR — LIENZO YA NO SE VE VACÍO (2026-06-23, rama `claude/github-app-analysis-a7b2bd`):** el owner reportó que
en el editor la plantilla «Mantenimiento» y las tablas enlazadas (p. ej. `mainwp.work_log`) salían **vacías**. Causa:
el lienzo entra en **modo datos-reales** apenas se elige un sitio (`hasRealData = siteId && preview_`), y un bloque
cuyo métrica aún no está en el snapshot del sitio renderiza en blanco (el renderer de tabla se oculta con 0 filas).
Dos arreglos **solo del editor** (no tocan portal/PDF, que siguen ocultando con gracia): (1) en `EditorScreen` el
`renderData` ahora hace **fallback a `sampleData(block)`** por-bloque cuando el dato real falta/está vacío, así un
bloque recién enlazado o sin snapshot sigue visible para diseñar; (2) `sampleData` para `table` es **consciente del
binding**: `work_log` → filas Fecha/Tipo/Elemento/Versión de muestra, `pending_updates` → Tipo/Elemento/Actual/Nueva,
resto → label/value. TS+ESLint+Vitest(11) limpios. **Nota:** para ver datos REALES de work_log hay que **sincronizar**
un sitio que actualice por MainWP (con logging), tras desplegar ≥v1.13.8.

**🗓️ MAINWP — "LO QUE HICIMOS ESTE MES" REAL CON FECHAS (2026-06-23, rama `claude/github-app-analysis-a7b2bd`):**
descubrimos vía el listado de rutas `/wp-json/mainwp/v2` que MainWP expone el namespace **`pro-reports`** (lo que
este producto reemplaza). El endpoint **`/pro-reports/{id|dominio}/{plugins|themes|wordpress}?action=updated&start&end`**
devuelve el **historial real de actualizaciones aplicadas** con fecha y versión vieja→nueva (validado con
comercializadoraomicron.com: 166 updates). `action` válido = `updated` (no `update`); el log lo alimenta
**Dashboard Insights** (`enable_insights_logging`, ya activo). Forma confirmada: `data.sections_data[0]` = filas con
claves tipo token `[plugin.name]`, `[plugin.old.version]`, `[plugin.current.version]`, `[plugin.updated.utime]`
(`Y-m-d H:i:s`), `[plugin.updated.date]` (humana); `data.other_tokens_data["[plugin.updated.count]"]` = total.
**Implementado:** el conector ahora, además del estado por-sitio, llama esos 3 endpoints (solo si el reporte pide
`mainwp.work_log`/`mainwp.updates_applied`) y construye **`mainwp.work_log`** (tabla Fecha/Tipo/Elemento/Versión,
orden desc por utime, filtrada al periodo por la propia marca de tiempo) — parser por **sufijo de clave** así que
sirve para plugin/tema/núcleo sin hardcodear cada token. **`mainwp.updates_applied`** pasa a ser el **conteo real**
de esas filas (ya no el proxy de snapshots); `MetricBagLoader` solo usa el diff de snapshots como **fallback** si el
log viene vacío/0. Plantilla «Mantenimiento» reenfocada: KPIs + resumen + **«Lo que hicimos este mes»** (work_log) +
pendientes (detalle) + CTA. Descubrimos también **`/updates/{dominio}`** (pendientes por-sitio, más limpio que parsear
`/sites`) — anotado para migrar luego; por ahora seguimos con el decode de `/sites` que ya funciona. Tests: +1
(work_log con filtrado de periodo y orden); 247 verde, PHPStan max + Pint + TS + ESLint + Vitest(11) limpios.
**Pendiente del owner:** validar en vivo el bloque del historial (sitios con logging traen datos; los que no, caen al
fallback). **Open Question CERRADA:** el historial estilo Modular DS sí existe y ya está integrado.

**🔧 MAINWP REESCRITO A POR-SITIO + UPDATES REALES (2026-06-23, rama `claude/github-app-analysis-a7b2bd`):**
validando con credenciales reales, el owner detectó dos fallos de fondo en MainWP: (1) traía datos **agregados de
los 28 sitios** del dashboard, pero los reportes son **por cliente/sitio**; (2) mostraba **0 actualizaciones
pendientes** y nada en "lo que hicimos". Con el JSON crudo de `/wp-json/mainwp/v2/sites` confirmé la causa: los
campos `plugin_upgrades`/`theme_upgrades`/`wp_upgrades`/`plugins` llegan como **STRINGS JSON** (no arrays), y
`plugin_upgrades`/`theme_upgrades` decodifican a un **objeto indexado por slug** (1 clave = 1 update pendiente);
el conector anterior hacía `toInt()` sobre el string → 0. **Reescritura:** (a) **scope por sitio** — `DataSource`
ahora tiene relación `site()`; el conector busca el único sitio gestionado cuya `url` coincide con `ir_sites.url`
(normalizando esquema/www/slash) y si no hay match devuelve `failed` con mensaje claro; (b) **`decode()`**
json-decodifica cada campo y cuenta correctamente (plugins/temas = nº de claves; core = 1 si `wp_upgrades` no
vacío); (c) nueva tabla **`mainwp.pending_updates`** estilo Modular DS (columnas Tipo/Elemento/Actual/Nueva, una
fila por plugin/tema/core con versión actual→nueva); (d) nuevas métricas `plugins_total`, `plugins_active`,
`health_score`; (e) eliminadas `sites_total`/`sites_with_updates`/`abandoned_plugins`/`ssl_expiring`/`sites`/
`ssl_expiring_sites` (eran agregadas o no existen en el payload). `updates_applied` (delta de snapshots) sigue y
ahora es **por-sitio** = más útil. Plantilla **«Mantenimiento (MainWP)»** reenfocada a un sitio (updates aplicadas/
pendientes + plugins activos + salud + timeline del mes + tabla de detalle). Test reescrito (9 casos: scope,
decode, match tolerante, fallos). PHPStan max + Pint limpios; 9/9 MainWP + suite de conectores/reportes verde.
**Pendiente del owner:** validar en vivo el nuevo por-sitio. **Open Question abierta:** la **historia de updates
aplicados / "lo que hiciste"** NO está en `/sites` (solo estado actual) — se aproxima con el diff de snapshots
(`MaintenanceDeltaCalculator`); para paridad real con Modular DS hace falta o el **listado de rutas
`/wp-json/mainwp/v2`** (por si hay endpoint de historial) o confirmar que el proxy por snapshots basta.

**🦠 VIRUSDIE AMPLIADO — ¡CONECTORES 2.x COMPLETOS! (2026-06-23, rama `claude/virusdie-full-metrics` → release
v1.13.6):** séptimo y último conector. **Conexión:** vía la **extensión VirusDie de MainWP** (mismo `dashboard_url`
+ token MainWP), endpoint `/wp-json/mainwp/v2/virusdie/summary`. **Catálogo de 3 a 7 métricas:** malware detectado,
**amenazas eliminadas**, sitios infectados, **sitios limpios**, **sitios analizados**, firewall activo (1/0) y una
**tabla de sitios con malware** (solo infectados, label=sitio, value=nº malware). Nueva plantilla **«Antimalware
(VirusDie)»** (icono `Bug`): KPIs + escudo + sitios con malware + timeline. Test ampliado (+3 aserciones). 56 tests
de conectores + PHPStan max + TS+ESLint+Vitest(11)+build limpios.

**🎉 LOS 7 CONECTORES 2.x AMPLIADOS:** GA4 (~27), GSC (10), Cloudflare (14), Better Stack (5), CrowdSec (7), MainWP
(11+`updates_applied`), VirusDie (7). Todos comparten patrón (escalares + series + tablas {label,value}, ratios
escalados a %, agregación en origen). **11 plantillas** en la galería. **Pendiente del owner: VALIDAR EN VIVO** todos
con credenciales reales — los nombres de campo/endpoint son los documentados/asumidos; conviene una corrida real por
conector y ajustar lo que no cuadre. Después: Fase 3 (Imagina Audit + WPVulnerability, conector database/CSV/endpoint,
detección de anomalías).

**🔧 MAINWP AMPLIADO (2026-06-23, rama `claude/mainwp-full-metrics` → release v1.13.5):** sexto conector — el
**corazón de la retención**. **Conexión:** Bearer token v2 + `dashboard_url`; lee `/sites` (agrega en origen). El
número estrella **`mainwp.updates_applied`** ("actualizaciones aplicadas este mes") **ya estaba** calculado por
`MaintenanceDeltaCalculator`+`MetricBagLoader` (diff de snapshots del periodo) — ahora se **expone en el catálogo**
(con `description` de que es calculada) para que el editor lo ofrezca. Añadidas además **`sites_with_updates`**
(sitios con updates pendientes) y la tabla **`ssl_expiring_sites`** (sitios con SSL por vencer + días restantes); el
helper SSL pasa de bool a **días restantes** (negativo=caducado). Etiquetas del catálogo en español. Nueva plantilla
**«Mantenimiento (MainWP)»** (icono `Wrench`): updates aplicadas/pendientes + sitios + **timeline del mes** + estado
por sitio + SSL. Test ampliado (+3 aserciones). PHPStan max + TS+ESLint+Vitest(11)+build limpios. **Pendiente del
owner:** validar en vivo (paths/campos v2 asumidos). **Último conector 2.x: VirusDie** (malware, vía MainWP).

**🛡️ CROWDSEC AMPLIADO (2026-06-23, rama `claude/crowdsec-full-metrics` → release v1.13.4):** quinto conector.
**Conexión:** token de la CrowdSec Console (o LAPI) + `api_url`; lee `/alerts?since&until` (1 sola llamada).
**Catálogo de 3 a 7 métricas** desde los campos documentados de cada alerta: alertas, **ataques bloqueados**
(nº de `decisions`), **eventos maliciosos** (suma `events_count`), **IPs atacantes** (distintos `source.value`), y
tablas **tipos de ataque** (`scenario`), **por país** (`source.cn`) e **IPs más activas** (`source.value`).
**Cambio:** `attack_types` migra de `{scenario,count}` a `{label,value}` (consistente con el resto; ahora es
graficable en donut) — test y plantilla de seguridad siguen OK (tabla). Nueva plantilla **«Seguridad de red
(CrowdSec)»** (icono `ShieldAlert`): KPIs + donut de tipos + IPs/país + timeline. Test ampliado (+4 aserciones).
PHPStan max + TS+ESLint+Vitest(11)+build limpios. **Pendiente del owner:** validar en vivo (forma de `/alerts`
asumida; Console vs LAPI pueden diferir). Siguiente: MainWP / VirusDie.

**⏱️ BETTER STACK AMPLIADO (2026-06-23, rama `claude/betterstack-full-metrics` → release v1.13.3):** cuarto conector.
**Conexión:** Bearer token + `monitor_id`; usa `GET /monitors/{id}/sla?from&to`. El endpoint SLA expone pocos campos
documentados, así que la ampliación es honesta: de 2 a **5 métricas** desde la **misma llamada** — disponibilidad
(`availability`), incidentes (`number_of_incidents`), **tiempo caído** (`total_downtime`, s), **incidente más largo**
(`longest_incident`, s) e **incidente medio** (`average_incident`, s). No hay serie diaria en la API sin 30 llamadas,
así que no se inventó. Nueva plantilla **«Disponibilidad y SLA»** (icono `Activity`): healthscore + KPIs de uptime +
timeline del mes. Test ampliado (+3 aserciones). PHPStan max + TS+ESLint+Vitest(11)+build limpios. **Pendiente del
owner:** validar en vivo (nombres de campo SLA asumidos). Siguiente: CrowdSec / MainWP / VirusDie.

**🟧 CLOUDFLARE AMPLIADO (2026-06-23, rama `claude/cloudflare-full-metrics` → release v1.13.2):** tercer conector real.
**Conexión:** API token con permiso **Zone Analytics:Read** + el **Zone ID**; usa el **GraphQL Analytics API**
(`httpRequests1dGroups`, agrega en el servidor). **Catálogo de 4 a 14 métricas:** escalares (peticiones, en caché,
amenazas, páginas vistas, peticiones cifradas, visitantes únicos, ratio de caché, ancho de banda) + **series** por
día (peticiones/amenazas/ancho de banda) + **tablas** amenazas/peticiones **por país** (`countryMap`) y **tipos de
amenaza** (`threatPathingMap`). La query GraphQL ahora pide `dimensions{date}`, `uniq{uniques}` y los mapas. **Fix:**
`cache_ratio` pasa de 0–1 a **0–100%** (consistente con el CTR de GSC). Nueva plantilla **«Cloudflare (CDN y
seguridad)»** (icono `Zap`). Test ampliado (2 grupos diarios → series, país, uniques, cache 80%); PHPStan max +
TS+ESLint+Vitest(11)+build limpios. **Pendiente del owner:** validar en vivo (la forma del GraphQL es la documentada;
`countryMap`/`threatPathingMap` son las claves asumidas). Siguiente: Better Stack / CrowdSec / MainWP / VirusDie.

**🔎 SEARCH CONSOLE AMPLIADO (2026-06-23, rama `claude/gsc-full-metrics` → release v1.13.1):** segundo conector real
tras GA4 (el owner validará GA4+GSC juntos al terminar). **Conexión:** mismo Service Account JSON que GA4 + la
propiedad `site_url` (el email del SA se añade como usuario en Search Console); usa `searchanalytics.query` (agrega en
el servidor). **Catálogo de 6 a 10 métricas:** se mantienen clics/impresiones/CTR/posición + top búsquedas/páginas, y
se añaden **series** (clics/impresiones por día, dimensión `date`, en 1 sola query reusada) y **tablas por país y por
dispositivo**. **Fix importante:** el CTR ahora se **escala 0–1 → 0–100%** (antes salía «0.05%») y la posición se
redondea a 1 decimal. Etiquetas del catálogo traducidas al español. **Plantilla SEO enriquecida** con gráfica de
«Clics en Google por día» (GSC) + tabla «Por dispositivo». +1 test GSC (serie de clics) y CTR del test ajustado a
3.5%. 56 tests de conectores + PHPStan max verdes; TS+ESLint+Vitest(11)+build limpios. **Pendiente del owner:**
validar GA4 **y** GSC en vivo con el Service Account real (basta uno con acceso a ambas propiedades). Luego seguir
con Cloudflare/Better Stack/CrowdSec/VirusDie/Woo.

**📊 GA4 COMPLETO + PLANTILLAS NORMAL/ECOMMERCE (2026-06-23, rama `claude/ga4-full-metrics` → release v1.13.0):**
arranca el desarrollo de conectores reales (2.x), empezando por **Google Analytics 4**. **Conexión:** Service
Account JSON + `property_id` (el SA se añade como Lector en la propiedad GA4); usa el Analytics Data API `runReport`
(agrega en el servidor, §3.3). **Catálogo ampliado de 7 a ~27 métricas** con nombres documentados del Data API,
cubriendo **contenido** (usuarios/nuevos/activos, sesiones, páginas vistas, sesiones con interacción, tasa de
interacción y rebote, duración media, páginas/sesión, conversiones, eventos) y **ecommerce** (ingresos totales/por
compras, transacciones, compras, ticket medio, artículos comprados/vistos, conversión a compra), + series
(sesiones/usuarios/ingresos/compras por día) y tablas (top pages, landing, fuentes, países, dispositivos, top
products). El parser ahora soporta **decimales** (moneda) y **escala 0–1 → 0–100** para porcentajes (`cast`/`scale`
por métrica). **Dos plantillas nuevas y separadas** en la galería: **«Analítica web (GA4)»** (sitios de contenido) y
**«E-commerce (GA4)»** (tienda en Analytics), con iconos `Globe`/`TrendingUp`. +2 tests GA4 (moneda + porcentaje
escalado, catálogo ecommerce) → 9 en total. PHPStan max + 55 tests de conectores verdes; TS+ESLint+Vitest(11)+build
limpios. **Pendiente:** validar en vivo con el Service Account real del owner (nombres de API son los documentados);
luego seguir con los demás conectores 2.x (GSC ya existe; Cloudflare/CrowdSec/BetterUptime/VirusDie/Woo a validar).

**🔘 BLOQUE CTA REPARADO (2026-06-22, rama `claude/cta-block-styling` → release v1.12.1):** el owner reportó que el
bloque «Llamada a la acción» se veía feo y **no tomaba los estilos** que se le aplicaban. Causa: `CtaBlock` no usaba
`styleCss` ni los mapas de estilo (tenía clases hardcodeadas `ir-bg-muted ir-p-6 ir-text-center`), así que ignoraba
fondo/color/esquinas/borde/alineación/relleno del inspector. Reescrito para **honrar `block.style`** (igual que el
resto de bloques): fondo y color por `styleCss`, `PAD`/`RADIUS`/`ALIGN`, toggle de borde — con un **diseño por
defecto premium** (tinte de acento `ir-bg-primary/[0.06]`, titular `ir-text-xl` en acento, botón con sombra y hover).
Si se fija color/fondo custom, el titular hereda el color y el texto secundario usa opacidad. Sin backend. TS+ESLint+
Vitest(11)+build limpios. **Siguiente:** validar conectores 2.x con credenciales reales del owner.

**🧾 CLARIDAD DE LA LISTA DE REPORTES (2026-06-22, rama `claude/reports-list-clarity` → release v1.12.0):** el owner
no entendía qué reporte era cada fila (solo salía el periodo) ni qué hacían los botones (varios abrían paneles
**al final de la página**, fuera de vista → «no hacen nada»). Hecho: **backend** `ReportSummaryResource` ahora expone
`created_at` (momento de generación). **Lista**: nueva columna **«Reporte»** (nombre de la definición + sitio) y
**«Generado»** (fecha+hora formateada); el estado se muestra en español (Borrador/Aprobado/Enviado). **Acciones**:
Resumen/Comentarios/Insights ahora **hacen scroll** al panel al abrirlo (el clic se ve), los botones llevan
**tooltips** explicativos + una línea-leyenda, y se añadió banner de éxito al **Aprobar**. 89 tests backend +
PHPStan + TS + ESLint + Vitest(11) + build limpios. **Siguiente:** validar conectores 2.x con credenciales reales.

**🎨 PLANTILLAS + LIENZO (2026-06-22, rama `claude/templates-and-canvas` → release v1.11.0):** tras desplegar
v1.10.0, el owner aclaró que el redondeado que le molestaba era el del **lienzo** (el artboard), no un bloque, y
pidió **mejorar las plantillas** y **un reporte específico de WooCommerce**. Hecho: **(1) Lienzo como hoja de
documento:** se quitó `ir-rounded-xl` del artboard en `EditorScreen` → esquinas rectas estilo Looker/Power BI.
**(2) Cabecera premium:** `HeaderBlock` ahora es portada de marca (eyebrow `{{agency}}` + título grande + subtítulo
`{{client}} · {{site}} · {{period}}` con borde inferior de acento); +caso `header` en `sampleData` para una vista
realista en el editor. **(3) Galería reescrita** (`templateGallery.ts`) con **layouts de grid explícitos** (abren
como dashboards intencionales, no auto-fluidos): **Tienda WooCommerce** (NUEVA, completa: ingresos brutos/netos,
pedidos, clientes, artículos, venta media, impuestos/envíos/descuentos/reembolsos, series ingresos/pedidos por día,
productos top), **SEO y tráfico** (GA4+GSC con CTR/posición/fuentes/top), **Soporte por horas** (horas vs plan +
donut por categoría + timeline), **Seguridad** (healthscore+shield+KPIs+tipos de ataque). **(4) Plantilla por
defecto (PHP) enriquecida:** 19 bloques con layout de grid + formato correcto (`style.format` percent/currency/
number — antes los KPIs salían sin formato) + métricas nuevas de Woo. Bindings verificados contra catálogos reales.
92 tests verdes, PHPStan+TS+ESLint+Vitest(11)+build limpios. **Siguiente:** validar conectores 2.x con credenciales
reales del owner; feedback del editor/plantillas en uso.

**🛠️ REFINAMIENTOS POST-OVERHAUL (2026-06-22, rama `claude/editor-refinements-woo` → release v1.10.0):** tres
mejoras pedidas por el owner tras desplegar v1.9.4. **(1) Borde del lienzo controlable:** el wrapper de
`CanvasBlock` tenía `ir-rounded-lg` fijo que ignoraba «Esquinas» del inspector; ahora deriva el radio de
`block.style.radius` (mismo mapa `RADIUS` que el renderer), así «Rectas» da esquinas rectas también en el área de
trabajo. **(2) WooCommerce ampliado de 3 a 13 métricas:** `metricCatalog` ahora expone ingresos brutos/netos,
pedidos, artículos, venta media diaria, impuestos, envíos, descuentos, reembolsos, clientes nuevos, **series
ingresos/pedidos por día** (de `totals` de `/reports/sales`) y productos top — todo de los **mismos 2 endpoints
ya llamados** (`/reports/sales` + `/reports/top_sellers`, campos documentados; sigue pendiente validación con
tienda real). **(3) Picker visual de campo en filtros:** el bloque `control` ya no pide teclear la clave de fila;
es un `<select>` poblado con las dimensiones del catálogo + `name`/`category` + el valor actual. Backend: +6
aserciones en `RemainingConnectorsTest`. PHPStan + TS + ESLint + Vitest(11) + build limpios. **Siguiente:** validar
conectores 2.x con credenciales reales del owner; recoger feedback del editor en uso.

**🎨 EDITOR PREMIUM · FASE E — capas + presets + galería + empty-state (2026-06-22, rama `claude/editor-premium-phase-e`):**
cierra el overhaul del editor (A–E completas). **Lista de «Capas»**: nueva `Section` que lista los bloques de la
página con icono (`BLOCK_META`) + métrica vinculada, seleccionar/duplicar/eliminar (paridad Power BI, ayuda con
bloques solapados). **Presets de acento**: el input de color del Tema pasa a `ColorSwatch` (presets + custom +
quitar=marca de agencia). **Galería de plantillas** con icono por vertical (e-commerce/SEO/soporte/seguridad).
**Empty-state** del lienzo rediseñado (icono + CTA «arrastra o haz clic»). Sin backend. TS+ESLint+Vitest(11)+build
limpios. → release **v1.9.4**. **🎉 OVERHAUL DEL EDITOR COMPLETO** (A shell · B paleta visual+drag · C inspector
rico · D fx calc · E capas/presets/galería). Listo para que el owner despliegue (Sistema→Actualizar) y lo evalúe.

**🎨 EDITOR PREMIUM · FASE D — editor fx de métricas calculadas (2026-06-22, rama `claude/editor-premium-phase-d`):**
sustituye los 3 inputs pelados por un **editor fx**: nuevo `editor/CalcMetricsEditor.tsx` con, por métrica,
clave (prefijo `calc.`) + etiqueta + fórmula, **botones de operador** (`+ - * / ( )`), **insertar métrica** del
catálogo, y **validación en vivo** (badge ✓ válida / ⚠ con el error) vía nuevo `editor/calcFormula.ts`
(`validateFormula`, refleja el `FormulaEvaluator` PHP: tokeniza, paréntesis balanceados, identificadores del
catálogo). La validación se omite si no hay sitio (sin catálogo) — el server sigue siendo la verdad. **+4 Vitest**
(`calcFormula.test.ts` → 11 en total). Sin backend. TS+ESLint+build limpios. → release **v1.9.3**. **Siguiente y
último: Fase E** (presets de tema + galería de plantillas con miniaturas + lista de capas + empty-states).

**🎨 EDITOR PREMIUM · FASE C — inspector rico (2026-06-22, rama `claude/editor-premium-phase-c`):** el mayor salto
de «ajustes básicos» a premium. El `Inspector` se reconstruye: **cabecera con icono+nombre del bloque**, pestañas
**Datos/Estilo** como `SegmentedControl`, y el **tab Estilo en acordeones** (`Section`: Apariencia · Color ·
Disposición · Formato de número). Controles nuevos: **`Toggle`** (switch en vez de checkbox), **`ColorSwatch`**
(rejilla de presets + custom + quitar), y **`SegmentedControl`** para alineación (iconos), relleno, esquinas y
formato numérico. La comparación KPI pasa de checkbox a segmented (Solo valor / vs. anterior); la métrica vinculada
muestra un **chip de fuente**. Se quita el wrapper `Card "Bloque"` (el inspector ya trae su cabecera) para que los
acordeones vayan a todo el ancho. Primitivas nuevas en `controls.tsx` (`Toggle`, `ColorSwatch`). Sin backend.
TS+ESLint+Vitest(7)+build limpios. → release **v1.9.2**. **Siguiente: Fase D** (editor fx de métricas calculadas:
validación en vivo + insertar métrica + operadores).

**🎨 EDITOR PREMIUM · FASE B — paleta visual de bloques (2026-06-22, rama `claude/editor-premium-phase-b`):**
sustituye la lista de botones-texto por una **paleta de tiles con icono, agrupada** por categoría (KPIs & datos ·
Gráficos & tablas · Texto & marca · Seguridad & soporte · Interacción & layout). Nuevo `editor/BlockPalette.tsx`
con `BLOCK_META` (label+icono por tipo, reutilizable en la futura lista de capas). **Click añade** el bloque a la
página actual y **arrastrar suelta** el bloque en la posición del lienzo (react-grid-layout `isDroppable`+`onDrop`+
`onDropDragOver`, dimensionado por `defaultSize`; nuevo estado `draggingType` + handler `dropBlock`). El
click-to-add es 100% fiable; el drag es el extra premium. Sin backend. TS+ESLint+Vitest(7)+build limpios. → release
**v1.9.1**. **Siguiente: Fase C** (inspector rico: Datos/Estilo, acordeones, segmented, swatches, combobox métrica).

**🎨 EDITOR PREMIUM · FASE A — shell + primitivas + reubicar selector (2026-06-22, rama `claude/editor-premium-phase-a`):**
arranca el overhaul del editor (owner: «se ve básico y pobre, lo quiero premium tipo Power BI/Looker»). Plan
acordado: 5 fases (A shell+primitivas · B paleta visual de tiles con drag · C inspector rico · D editor fx de
métricas calculadas · E presets de tema + galería con miniaturas). Estética: **mezcla Looker-limpio + Power-BI-rico**.
**Fase A hecha:** nuevas **primitivas reutilizables** (`editor/controls.tsx`: `Section` acordeón, `SegmentedControl`,
`ToolbarButton`, `ToolbarDivider`). **Toolbar reconstruida**: título de plantilla estilo documento, chips de estado,
y un **control de vista previa compacto** `[🌐 Sitio ▾][📅 Mes ▾]` que **saca el `<select>` gigante** del panel
izquierdo (era solo para preview). **Panel izquierdo** pasa de 5 Cards apiladas a **Sections en acordeón**
(Insertar/Plantillas e IA/Métricas calculadas/Tema), con la paleta en **rejilla 2-col** y densidad como
`SegmentedControl`. Sin cambios de backend ni de lógica (preview/IA/sync/undo/save intactos). TS+ESLint+Vitest(7)+
build limpios. → release **v1.9.0**. **Siguiente: Fase B** (paleta visual con iconos + arrastrar al lienzo).

**📖 OPENAPI (SCRAMBLE) — v1.8.0 (2026-06-22, rama `claude/openapi-docs`):** backlog 5.3 — cierra el §8
("documentar la API con OpenAPI, generado desde rutas/resources"). Añadido **dedoc/scramble** que **auto-genera**
el spec OpenAPI 3.1 desde los controladores/FormRequests/Resources tipados (47 paths cubriendo las 63 rutas, sin
anotar a mano). Servido en **`/docs/api`** (UI Stoplight Elements) y **`/docs/api.json`** (spec). Config publicada
(`config/scramble.php`): título «Imagina Reports API», versión `env(API_VERSION, v1)`, descripción (auth Sanctum +
tenant). **Acceso restringido** a entorno local o al gate `viewApiDocs` (definirlo para exponer en producción) —
seguro por defecto. **+2 tests** (genera el spec con las rutas v1 reales con el gate abierto; 403 fuera de local).
**240 tests verdes, PHPStan max + Pint limpios.** `API_VERSION` en `.env.example`. **Nota:** Scramble introspecciona
la BD al generar; en local/CI usa sqlite, en prod la BD real (solo cuando se piden los docs, gateados). → release
**v1.8.0**. **🎉 BACKLOG SEGURO SIN CONECTORES: COMPLETO.** Lo único pendiente es **2.x conectores reales**
(requiere credenciales de prueba del owner).

**📦 CODE-SPLITTING DEL BUNDLE — v1.7.1 (2026-06-22, rama `claude/build-code-splitting`):** backlog 5.2. El build
avisaba de chunks >500 KB (el `BlockRenderer` compartido pesaba ~690 KB con recharts inline). Añadido
`build.rollupOptions.output.manualChunks` en `vite.config.ts` que separa vendor pesado en chunks propios:
`charts` (recharts/d3, 112 KB), `editor-richtext` (tiptap/prosemirror, 93 KB), `editor-grid` (dnd-kit/grid, 65 KB),
`tanstack` (28 KB), `motion`. **Resultado: `BlockRenderer` 690 KB → 30,7 KB y el warning desaparece.** **Solo chunks
estáticos (sin import dinámico)** → el portal/PDF cargan su código eager, `window.reportReady` (§10.7) intacto; las
libs solo-editor no entran en los entries de report/portal. TSC + ESLint + Vitest (7) + build limpios; manifest
válido. → release **v1.7.1**. **Restante del backlog seguro: 5.3 OpenAPI** (último).

**🧪 VITEST + RTL — v1.7.0 (2026-06-22, rama `claude/frontend-vitest`):** backlog 5.1 — cierra el hueco histórico
«sin tests de frontend» (el gate front era solo typecheck+lint+build). Añadido **Vitest 2 + React Testing Library +
jsdom**: `vitest.config.ts` (separado de `vite.config.ts` para que el build de assets no arrastre tooling de test;
comparte los alias `@/@admin/@portal/@shared`), `resources/js/test/setup.ts` (jest-dom + cleanup), scripts
`npm test`/`test:watch`. **7 tests** iniciales: `color.test.ts` (hex→HSL), `utils.test.ts` (`cn`/twMerge), y un
**smoke de componente** `BlockRenderer.test.tsx` (renderiza un bloque narrative desde data y desde props.text). CI
ejecuta `npm test` entre lint y build. TSC+ESLint limpios (los `.test` entran en sus globs). Nuevas devDeps:
vitest, jsdom, @testing-library/{react,jest-dom,dom}. → release **v1.7.0**. Restante del backlog seguro: 5.2
code-splitting, 5.3 OpenAPI (en curso esta misma tanda).

**📸 SCREENSHOTS EN WORK LOGS — v1.6.6 (2026-06-22, rama `claude/worklog-screenshots`):** backlog 4.2 — prueba
visual del trabajo (alinea con "hacer visible el trabajo invisible"). El modelo ya tenía `screenshot_path` pero no
había forma de subir ni mostrar. **Backend:** `StoreWorkLogRequest` acepta un archivo `screenshot`
(png/jpeg/webp ≤4MB); `SiteWorkLogController::store` lo sube al disco `public` (carpeta `worklogs/`) — nunca acepta
un path del cliente; `WorkLog::screenshotUrl()` (helper) + `screenshot_url` expuesto en `WorkLogResource` y en el
overlay del `worklog_timeline` del `ReportResource` (portal/PDF). **Frontend:** input de archivo en «Registrar
trabajo» (se limpia tras enviar; `useCreateSiteWorkLog` manda **multipart** solo si hay imagen, JSON si no);
miniatura clicable en la lista de Trabajo y en el bloque timeline del reporte. **+2 tests** (sube imagen y la
guarda; rechaza no-imagen 422). **238 tests verdes, PHPStan max + Pint + TS + ESLint + build limpios.** **Ojo
producción:** requiere el symlink `public/storage` (lo crea `deploy.sh` con `storage:link`). → release **v1.6.6**.
**Backlog seguro restante (solo infra/tooling, requiere tu OK):** 5.1 Vitest, 5.2 code-splitting del bundle, 5.3 OpenAPI.

**🔍 BUSCADOR EN EL SELECTOR DE MÉTRICAS — v1.6.5 (2026-06-22, rama `claude/editor-metric-search`):** backlog 4.1,
solo frontend. El binding picker del Inspector era un `<select>` plano que no escalaba con catálogos de muchas
fuentes×métricas. Ahora, cuando el catálogo tiene >6 entradas, aparece un **input «Buscar métrica…»** que acota las
opciones por etiqueta/fuente/métrica; la **métrica ya vinculada siempre se mantiene en la lista** (no desaparece al
filtrar) y se muestra «Sin métricas que coincidan» si el filtro no devuelve nada. Cero backend. **TS + ESLint +
build limpios.** → release **v1.6.5**. Pendientes seguros restantes: 4.2 (screenshots de work logs), 5.x
(Vitest/code-split/OpenAPI).

**🤖 FEEDBACK DE BLOQUES DESCARTADOS POR LA IA — v1.6.4 (2026-06-22, rama `claude/ai-dropped-blocks-feedback`):**
backlog 3.3. Al «Generar con IA», `AiReportBuilder::assembleTemplate` ya descartaba bloques cuya métrica no existe
en el catálogo del sitio (no puede inventar datos, §10.6) **pero en silencio**. Ahora devuelve también
`dropped: [{type, metric}]`; el `AiTemplateController` lo expone tal cual; el **editor muestra un banner ámbar**
(«La IA propuso N bloque(s)… y se omitieron: …») con botón Cerrar, en vez de encoger el lienzo sin explicación.
`AiTemplateResult` (TS) gana `dropped`. **+1 assert** en `AiReportBuilderTest` (verifica `dropped`). **236 tests
verdes, PHPStan max + Pint + TS + ESLint + build limpios.** → release **v1.6.4**. Pendientes seguros restantes:
4.1 (buscador en el selector de métricas del editor), 4.2 (screenshots de work logs), 5.x (Vitest/code-split/OpenAPI).

**🧭 UX DEL FLUJO DE REPORTES — v1.6.3 (2026-06-22, rama `claude/reports-workflow-ux`):** dos huecos del backlog,
solo frontend (reusa endpoints existentes). **1.3 — métricas ocultas accionables:** la celda «⚠ N sin datos» ahora
trae un botón **«Sincronizar periodo»** (encola `POST /sites/{site}/sync` para el periodo del reporte, vía
`useSyncSiteById` nuevo) y cada fila gana **«Regenerar»** (re-corre `reports/generate` con la definición+periodo del
reporte) — cierra el lazo sincronizar→regenerar sin volver a teclear el formulario. **4.4 — destinatarios
editables:** antes eran write-only; la lista «Definiciones existentes» ahora muestra y **edita los destinatarios**
inline (guarda al perder foco vía `useUpdateReportDefinition`, que ya aceptaba `recipients`); `recipients` añadido
al tipo `ReportDefinitionDto`. **TS + ESLint + build limpios; sin cambios de backend** (228… los tests PHP no
cambian). → release **v1.6.3**. Pendientes seguros restantes: 4.1 (buscador en el selector de métricas del editor),
3.3 (feedback de bloques que la IA descarta), 4.2 (screenshots de work logs), 5.x (Vitest, code-split, OpenAPI).

**🔎 OBSERVABILIDAD DE FÓRMULAS — v1.6.2 (2026-06-22, rama `claude/observability-hardening`):** quita fallos
silenciosos en las métricas calculadas (backlog 3.1/3.2). **`FormulaEvaluator`** ahora rechaza resultados **no
finitos** (overflow `huge*huge`→INF, o NaN) con `FormulaException` — antes podían guardarse en el reporte y romper
el render. **`CalculatedMetrics`** ahora **loguea un warning** (key+fórmula+error) cuando una fórmula no se puede
computar, en vez de tragar la excepción en silencio (el bloque se sigue ocultando con elegancia). **+3 tests**
(overflow rechazado; computa válidas; loguea+omite la mala). **236 tests verdes, PHPStan max + Pint limpios.** Solo
backend. → release **v1.6.2**. **Backlog restante de observabilidad (no incluido):** feedback de bloques
descartados por la IA (3.3, cambia shape+UI) y thresholds de config mal formados en anomalías/upsell (3.4).

**✏️ EDICIÓN/REGENERACIÓN DE LA NARRATIVA — v1.6.1 (2026-06-22, rama `claude/narrative-editing`):** completa el
follow-up de §10.6 ("always editable"). Antes el resumen ejecutivo se congelaba en la generación; ahora la agencia
puede **editarlo a mano o regenerarlo con IA por reporte** antes de enviar. **Backend:** `PUT /reports/{id}/narrative`
(guarda texto) y `POST /reports/{id}/narrative/regenerate` (re-corre la IA sobre las cifras FROZEN, nunca una API de
datos en vivo §3.1; 502 si la IA falla). Ambos inyectan el texto en `resolved_blocks.data` de los bloques
`variant=executive_summary` vía helper compartido nuevo **`ExecutiveSummary`** (refactor: el generador deja de
duplicar esa lógica). `executive_summary` ahora se expone en `ReportSummaryResource`. **Frontend:** panel «Resumen
ejecutivo (IA)» en `ReportsScreen` (textarea + Guardar + Regenerar con IA), botón «Resumen» por fila. **+5 tests**
(guardar+inyectar, validación, regenerar con IA, no-op sin facts, aislamiento de tenant). **233 tests verdes,
PHPStan max + Pint + TS + ESLint + build limpios.** → release **v1.6.1**.

**🤖 NARRATIVA IA EN LA GENERACIÓN — v1.6.0 (2026-06-22, rama `claude/ai-narrative-generation`):** cableada la
función estrella que faltaba (§10.6): cada reporte nace con **resumen ejecutivo auto-generado** desde las cifras
resueltas. Antes `ReportGenerator` fijaba `executive_summary = null` ("Phase 2") y `AiReportBuilder::narrative()`
nunca se llamaba. Ahora: tras resolver bloques, el generador arma un mapa label→valor (`ReportFacts`, helper nuevo
compartido con Insights), llama a `narrative()` en el locale del reporte (definición → agencia → 'es'), guarda el
texto en `ir_reports.executive_summary` y lo **inyecta en `resolved_blocks.data[id]`** de los bloques `narrative`
con `props.variant='executive_summary'` (el `NarrativeBlock` ya pinta `data` sobre `props.text`, así que sale en
portal/PDF/editor **sin tocar el renderer**). **Resiliencia (clave):** la llamada IA — única excepción a "sin APIs
externas en GENERATE", §3.1/§10.6 — va en try/catch; si falla (sin key, API caída, JSON vacío) el reporte se
genera igual con el resumen vacío y se loguea un warning. Solo se llama si hay bloque resumen Y hay datos. Corre
dentro de `GenerateReportJob` (cola, tenant atado → resuelve la key de la agencia). `TestCase` ahora ata un
`FakeAiClient` global (ningún test golpea la red, §14). **+2 tests** (inyecta narrativa; sobrevive a IA que lanza).
**228 tests verdes, PHPStan max + Pint limpios.** **Pendiente (follow-up):** edición/regeneración del resumen por
reporte desde la UI ("always editable", §10.6) — hoy se congela en la generación. → release **v1.6.0**.

**🩹 FIX v1.5.1 — Browsershot necesita `puppeteer`, no `puppeteer-core` (2026-06-22):** tras desplegar v1.5.0 el
PDF fallaba con `node ... browser.cjs` exit 1. Causa: `vendor/spatie/browsershot/bin/browser.cjs:75` hace
`require('puppeteer')` (el paquete COMPLETO), y yo había cableado `puppeteer-core`. Corregido: `deploy.sh` instala
`puppeteer` con `PUPPETEER_SKIP_DOWNLOAD=true` (usa el Chrome del sistema, no baja Chromium); `.env.example` y el
docblock de `BrowsershotPdfRenderer` actualizados. **Fix manual en el VPS:**
`cd /home/<appuser> && PUPPETEER_SKIP_DOWNLOAD=true npm install puppeteer` y reintentar (el job lanza un node
nuevo, no hace falta reiniciar). → release **v1.5.1**.

**🖨️ PDF VUELVE A BROWSERSHOT (2026-06-22, v1.5.0):** el owner confirmó que su
instancia OLS de ServerAvatar **sí permite instalar Node**, así que se revierte la desviación a "Chromium directo"
(v1.4.2–v1.4.4, forzada por la regla "sin Node") y se vuelve a **Spatie Browsershot** — que es lo que el spec
original (`CLAUDE.md` §10.7 y la tabla §2) siempre pidió. **Por qué:** el renderer directo esperaba con
`--virtual-time-budget=20000` (un reloj fijo de 20 s, imprime incompleto si los datos tardan), y sufría el
calvario del binario (snap → v1.4.3, open_basedir → v1.4.4). Browsershot usa
`waitForFunction('window.reportReady === true')` — espera **determinista** sobre la señal que la página de reporte
ya emite — y Puppeteer gestiona el handshake con Chrome (adiós al probing de rutas). **Cambios:** nuevo
`BrowsershotPdfRenderer` (apunta a Node/Chrome/node_modules vía `config('services.browsershot.*')`, A4, márgenes,
`noSandbox`, timeout 120s, espera 30s a `window.reportReady`); binding `PdfRenderer` → Browsershot en
`AppServiceProvider`; el viejo `HeadlessChromiumPdfRenderer` **se elimina** (junto con su test, que era frágil:
caía a `/usr/bin/google-chrome` del runner `ubuntu-latest` y no lanzaba la excepción esperada).
`config/services.php` + `.env.example` ganan `BROWSERSHOT_NODE_PATH`/`NPM_PATH`/`NODE_MODULE_PATH`. `deploy.sh`
aprovisiona `puppeteer-core` en `shared/node_modules` (idempotente, best-effort — **no toca el lockfile del build
de CI**, así `npm ci` sigue intacto). **Verde:** 227 tests (incluye binding test nuevo), PHPStan max, TS, ESLint,
build. **Ojo producción:** instalar Node + Google Chrome no-snap en el VPS, `npm install puppeteer-core` en
`shared/`, y apuntar `BROWSERSHOT_CHROME_PATH`/`BROWSERSHOT_NODE_MODULE_PATH` en `shared/.env`. Sin desplegar aún
(rama) → candidato a v1.5.0.

**🖥️ EDITOR FULL-BLEED + PANELES COLAPSABLES (2026-06-20, rama, post v1.3.0):** feedback del owner — el editor se
veía "de juguete" por estar metido en el shell `max-w-6xl` con 3 columnas fijas (el lienzo solo recibía la franja
central). Rehecho como **app shell de editor real (Figma/Looker)**: `App.tsx` saca la vista `editor` a **pantalla
completa** (h-screen, sin max-width; las demás vistas mantienen su layout centrado). `EditorScreen` ahora es
barra superior a todo el ancho (nombre + periodo + undo/redo + sincronizar + **Guardar** + toggles de panel) →
cuerpo de 3 paneles: izquierdo (config/bloques/tema) y derecho (inspector) **colapsables**, y el centro es un
**lienzo tipo lámina centrada (max-w-5xl) sobre fondo gris con scroll propio**. typecheck+eslint+build limpios,
Prettier aplicado. **Sin desplegar aún** (candidato a v1.3.1). 214 PHP sin cambios.

**🎨 TEMA/BRANDING + v1.3.0 (2026-06-20):** cerrado el milestone del editor con **tema por reporte (956547b)**:
columna `theme` (json nullable) en templates+definitions (acento hex + densidad normal|compact), validada y
expuesta; el generador congela el tema (definición→plantilla) en el reporte; el render comparte el acento como
**variable CSS scoped** (`--ir-primary`, sobreescribe la marca de agencia) y la densidad ajusta el padding —
idéntico en portal/PDF/editor; panel "Tema del reporte" en el editor. **214 PHP verde.** **Milestone editor
COMPLETO:** rejilla, galería+pestañas+drill-down, multipágina, fill-tile, control de filtro, tema. → cortando
**v1.3.0** (PR a main + release workflow_dispatch, porque el entorno bloquea push de tags).

**🎛️ EDITOR PRO TIPO LOOKER — milestone (casi) completo (2026-06-20, rama, post v1.2.0):** además de A/B/C-multipágina/
fill-tile (ver entrada siguiente), se añadió **controles de página honestos (0a61663)**: bloque `control` = un
desplegable de los valores de su métrica que **acota las filas** de tablas/gráficos/timeline de la misma página
(ReportFilterContext compartido). Es un **filtro de filas client-side** sobre snapshots agregados, NO un cross-filter
sobre datos crudos (el modelo no los guarda, §3.3); por defecto vacío → sin control = render idéntico (cero
regresión). El **selector de periodo ya existía** en el portal (cambia entre el reporte de cada periodo). **Estado
del milestone:** ✅ rejilla, ✅ galería+pestañas+drill-down, ✅ multipágina, ✅ controles de página, ✅ fill-tile.
**Único pendiente (opcional):** tema/branding por reporte (acento+densidad) — requiere columna `theme` en
templates/definitions; el acento de marca de la agencia ya fluye. **211 PHP verde, gates limpios.** Decisión del
owner: **un solo v1.3.0 al final** (en rama, sin desplegar aún).

**🎛️ EDITOR PRO TIPO LOOKER — rediseño (2026-06-20, rama, post v1.2.0):** el owner exigió paridad con Looker
Studio/Power BI (el editor anterior era una lista apilada de 1 columna, "parecía de juguete"). Rediseño por fases,
todo verde y commiteado: **Fase A (6732aa1)** lienzo de **rejilla responsive 12-col** con react-grid-layout —
arrastrar, **redimensionar** por tiradores, reflujo, snap; coordenadas `layout{x,y,w,h}` en el modelo (TS+PHP+
validador, retrocompatible: sin coords = width-flow legacy); el render compartido pinta la **misma rejilla CSS**
en portal/PDF. **Fase B (9484ab5)** Inspector con **pestañas Configuración/Estilo**, **galería visual de tipos**
(línea/barras/barras-horiz/área/dona/pastel, +hbar en el renderer) y **drill-down** (dimensión del catálogo en
binding.dimension); quitado el control "Ancho" (lo gobierna el resize). **Fase C/D (711bb95)** **multipágina**:
cada bloque lleva `page`; navegador de páginas en el editor (añadir/eliminar/cambiar); el render agrupa por página
con salto de página para el PDF. **Polish (40a0e8d)** los widgets **llenan su tile** (secciones flex a altura
completa, gráficos al 100%) para que parezca un dashboard real. **211 PHP verde, PHPStan max + Pint + TS + ESLint +
build limpios.** Nueva dependencia: `react-grid-layout`. **PENDIENTE del milestone:** controles de página
(periodo/filtros/desplegables — ojo: el filtrado interactivo encaja mal con el modelo de snapshots agregados, hay
que diseñarlo con honestidad) y tema/branding por reporte (acento+densidad). Sin desplegar aún (en rama) → será
**v1.3.0**.


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
**Next action:** Phases 1–3 are functionally COMPLETE (Imagina Audit removed from scope 2026-06-24, owner —
not applicable to these reports). Remaining work is owner-gated / polish:
(1) ⚠️ Confirm the `upsell.detected` webhook event name (extends §8's three named events — Open Questions).
(2) Validate every connector's real API shapes against live accounts (Open Questions). (3) Low-priority FE
polish: admin "System → Updates" screen (§11.1) consuming the update API; surface anomaly/upsell signals in
the admin UI. (4) Owner deploy steps (Chromium path, GA4/GSC service-account readers).

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
- ~~Imagina Audit + WPVulnerability connector~~ — **REMOVED (2026-06-24, owner): out of scope for these reports.** Enum case `imagina_audit` + all doc references deleted; **Phase 3 is now fully complete.**

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

- (2026-06-25) **El Agente Imagina reemplaza los datos por-sitio de MainWP en los reportes.** El agente corre dentro de cada sitio y
  lee directamente updates (core/plugins/themes), inventario de plugins, salud y SSL — todo lo que MainWP aporta por sitio. MainWP es
  un agregador multi-sitio, pero Imagina Reports ya centraliza por su multi-tenancy + el agente por sitio. Excepción: «plugins
  abandonados» NO lo hará el agente (requiere consultar wp.org y la regla de oro §3.3 prohíbe llamadas externas desde el agente); ese
  signo se mantiene en MainWP o se calcula en el lado Laravel.
- (2026-06-22) **PDF engine = Spatie Browsershot again** (re-aligns with the original spec §10.7). Owner confirmed
  their ServerAvatar OLS instance allows installing Node, so the v1.4.2 "drive Chromium directly (no Node)"
  workaround is reverted. Rationale: the direct-CLI renderer waited with a fixed `--virtual-time-budget` (prints
  too early/late) and had to hunt for a non-snap binary across `open_basedir` (v1.4.3/v1.4.4 churn). Browsershot's
  `waitForFunction('window.reportReady === true')` is a deterministic wait on the signal the report SPA already
  emits, and Puppeteer manages the Chrome handshake. The old `HeadlessChromiumPdfRenderer` (and its
  environment-fragile test) was removed — Node is confirmed on the VPS, so the no-Node escape hatch is dead code.
  Runtime needs: Node + `puppeteer-core` (provisioned into `shared/node_modules` by `deploy.sh`, NOT in
  the CI build ZIP) + a non-snap Chrome (`BROWSERSHOT_CHROME_PATH`). This narrows the locked "no Node on server"
  decision to "no asset *build* on server" — assets are still CI-built.
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
- **Imagina Audit connector: REMOVED FROM SCOPE (2026-06-24, owner).** Not applicable to these reports.
  The `imagina_audit` enum case and all CLAUDE.md/README/PROGRESS references were deleted. Phase 3 is
  complete without it. (If ever needed, it slots back in as a new enum case + connector — no schema change.)
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
