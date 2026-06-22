# HANDOFF — Imagina Reports (traspaso para continuar en otro chat)

> Documento de continuidad. Léelo junto con `CLAUDE.md` (la especificación cerrada) y `PROGRESS.md`
> (estado vivo). Aquí está: **dónde estamos, el problema del PDF con sus opciones reales, y el flujo
> exacto para publicar releases en GitHub y actualizar la app.** Fecha: 2026-06-22.

---

## 1. Estado actual

- **Rama de trabajo:** `claude/trusting-johnson-ecank6` (se mergea a `main` vía PR).
- **Última versión publicada:** **v1.4.4** (release ZIP en GitHub Releases).
- **Gates en verde:** 226 tests PHP, PHPStan max, Pint, TypeScript, ESLint, build de las SPAs.

### Qué se hizo en esta sesión (v1.4.0 → v1.4.4)
- **v1.4.0** — Primera pasada de sistema visual (tokens nuevos: acento, semánticos, sombras,
  scrollbars; componentes Button/Card/Input/Select/Badge; tablas; barra lateral con acento).
  Pulido de bloques del reporte (KPIs, secciones, tabla, lámina más ancha y blanca).
  **Botón "PDF"** + endpoint. **Eliminar reportes y definiciones** (endpoints + botones).
  **Fuentes**: selector de sitio inline (antes obligaba a ir a Sitios).
- **v1.4.1** — Bajados los redondeos (radio `0.7rem → 0.375rem`, estilo más "Cloudflare").
  **Barra lateral colapsable** (persistida en localStorage).
- **v1.4.2/3/4** — Cadena de arreglos del PDF (ver §3). Hoy el PDF **aún no funciona en el VPS**
  por una dependencia de sistema (no es un bug de la app).

### Feedback del owner pendiente de atender (visual)
- Le gusta el **nuevo dashboard de Cloudflare** como referencia de admin (denso, plano, enterprise).
- Quiere el **reporte al nivel del PDF de Modular DS** (referencia que envió): gauges circulares,
  sparklines bajo KPIs, donut de distribución, tablas densas con estados y fechas, badges verdes de
  estado. Eso es la **2.ª pasada** sobre los bloques del reporte y el editor (no empezada).
- Confirmó que quiere **varios estilos/temas** (dashboard interactivo + PDF "premium").

---

## 2. Arquitectura mínima para ubicarte (detalle en CLAUDE.md)

- **Laravel 11 / PHP 8.4**, API-first (`/api/v1`), multi-tenant por `agency_id`, Sanctum.
- **3 SPAs React 18 + Vite** (prefijo Tailwind `ir-`): `resources/js/admin`, `.../portal`, `.../report`.
  El `BlockRenderer` compartido (`resources/js/shared/blocks/BlockRenderer.tsx`) es la **única fuente
  de render**: lo usan editor, portal y el PDF.
- **Pipeline:** SYNC (conectores → snapshots) → GENERATE (`ReportGenerator` → `resolved_blocks`) →
  DELIVER (portal + PDF + email). Nunca se llama a APIs externas al generar.
- **Tokens de diseño:** `resources/css/app.css` (`:root`) + `tailwind.config.js`.
- **Componentes UI admin:** `resources/js/admin/components/ui.tsx` (Button, Card, Input, Select, Badge,
  Field) y `DataTable.tsx`.
- **Shell admin / barra lateral:** `resources/js/admin/App.tsx`.

---

## 3. EL PROBLEMA DEL PDF (lo importante) — causa raíz y opciones

### Por qué duele
La decisión **cerrada** del proyecto (CLAUDE.md §2 y §10.7) es: el PDF se produce **imprimiendo la
misma página React con un navegador headless** (Chromium) para que sea idéntico al portal. Eso da
fidelidad perfecta, pero **exige un navegador ejecutable en el servidor**. Ese es el quid.

### La cadena de bloqueos que encontramos (todos reales, en orden)
1. El endpoint daba 500 y el front se lo tragaba → ahora **muestra el error**.
2. **Browsershot necesita Node + Puppeteer**, y Node **no está** en el servidor (decisión §2:
   las SPAs se compilan en CI). → Reescrito a **Chromium directo** (`--headless --print-to-pdf`,
   sin Node): `app/Services/Pdf/HeadlessChromiumPdfRenderer.php`.
3. El Chromium del VPS es **snap**, y snapd **prohíbe** ejecutarlo desde el servicio web
   (`... is not a snap cgroup`). No hay flag que lo sortee.
4. **`open_basedir`** (ServerAvatar) impedía `is_executable()` sobre `/usr/bin/*` → quitado el stat;
   ahora solo intenta lanzar el binario (open_basedir no bloquea `proc_open`).
5. **Google Chrome no está instalado** (`/usr/bin/google-chrome-stable: not found`). El `.deb` no
   llegó a instalarse. → Aquí estamos.

**Conclusión honesta:** con la arquitectura actual, el PDF necesita un **navegador no-snap en el VPS**.
La app corre como usuario web **sin root**, así que **no puede instalarlo sola** (igual que no puede
instalar una extensión PHP). El renderer ya hace lo correcto (prueba varias rutas, da errores claros).

### Opciones reales (hay que elegir una en el próximo chat)

**A) Navegador headless en el servidor (la decisión original, máxima fidelidad).**
Instalar **una vez** Google Chrome no-snap (root). Trae también las librerías que necesita:
```bash
cd /tmp
wget https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
sudo apt install -y ./google-chrome-stable_current_amd64.deb   # si falla deps: sudo apt -f install -y
google-chrome-stable --version   # debe imprimir la versión
```
El código ya detecta `/usr/bin/google-chrome-stable`. Pro: PDF idéntico al portal. Contra: dependencia
de sistema + root una vez. (No requiere cambiar la app.)

**B) Servicio externo de render PDF (sin instalar NADA en el servidor) — RECOMENDADA si no quieres
tocar el VPS.** browserless.io / Doppio / PDFShift / api2pdf, etc. La app manda la **URL pública** del
reporte (ya existe con `public_token`: `/api/v1/public/reports/{token}` y la página `report.public`) y
recibe el PDF. Encaja **perfecto** en la arquitectura: solo hay que crear otro `PdfRenderer` (la
interfaz `app/Services/Pdf/PdfRenderer.php` ya existe) y elegirlo por config (como ya se hace con
`AiClient`). Pro: cero navegador local, mantiene la fidelidad. Contra: dependencia externa + coste +
necesitas una API key. Esto es un **override legítimo** de la decisión de §12 (como ya se hizo con la IA).

**C) PDF en PHP puro (dompdf/mPDF), sin navegador.** Pro: cero dependencias. Contra: **no ejecuta JS**
→ los gráficos (Recharts) y el render React **no salen**; habría que construir una plantilla HTML/CSS
server-side aparte y se **pierde el "single source of truth"**. Calidad muy por debajo de Modular DS.
Solo recomendable si se renuncia a los gráficos/fidelidad.

> Mi recomendación: **Opción B**. Resuelve tu queja ("no quiero instalar un navegador en mi server"),
> no toca el VPS y conserva la calidad. Se implementa detrás de la interfaz `PdfRenderer` y se activa por
> `.env`. Lo único que tú aportas es la cuenta/API key del servicio elegido.

---

## 4. Flujo EXACTO para publicar un release en GitHub (reproducible)

El workflow `.github/workflows/release.yml` se dispara **al hacer push de un tag `vX.Y.Z`**. No hay
botón manual: **el tag es el disparador**.

```bash
# 1) Trabaja en la rama, commitea (Conventional Commits) y deja los gates en verde (ver §5).
# 2) Mergea la rama a main (PR o merge directo).
# 3) Desde main actualizado, crea y empuja el tag:
git checkout main && git pull origin main
git tag v1.4.5
git push origin v1.4.5
```
Eso lanza el workflow, que:
1. `composer install --no-dev --optimize-autoloader`
2. `npm ci && npm run build:assets` (Node solo en CI, nunca en el servidor)
3. escribe `VERSION` (sin la `v`) dentro del bundle,
4. crea **`dist/imagina-reports-<version>.zip`** (autocontenido: incluye `vendor/` y `public/build`) + `.sha256`,
5. publica un **GitHub Release** con esos dos ficheros (`softprops/action-gh-release`).

Verificar que salió:
```bash
git ls-remote --tags origin v1.4.5          # el tag existe
# y en GitHub → Releases → v1.4.5 tiene el .zip y el .sha256
```

> Nota: en esta sesión los tags se dispararon vía la herramienta MCP de GitHub, pero **el método
> canónico y reproducible es `git tag` + `git push origin <tag>`** (arriba). El workflow es el mismo.

---

## 5. Calidad (correr SIEMPRE antes de commitear)

```bash
# PHP
vendor/bin/pint               # estilo
vendor/bin/phpstan analyse --memory-limit=512M   # nivel max
php artisan test              # 226 tests

# Frontend
npm run typecheck
npm run lint
npm run build                 # build:assets de admin + portal + report
```

Convenciones: PHP `declare(strict_types=1)`, clases `final`, capas Controller→Service→Model.
Commits: Conventional Commits (`feat:`, `fix:`, `refactor:`...). Cada commit termina con el trailer
`Co-Authored-By` y `Claude-Session` que ya usamos.

---

## 6. Cómo actualizar / revertir la app en el servidor (self-updater, §12)

En la app: **Sistema → Actualizar a vX.Y.Z** (descarga el ZIP del release, extrae a
`releases/<timestamp>_<version>`, symlinka `shared/` (.env, storage), `migrate --force`,
`config/route/view:cache`, **flip del symlink `current`**, `queue:restart`, health-check `/health`
con auto-rollback). Luego **Sistema → Reiniciar trabajadores** (importante para que el worker cargue
el código nuevo; el PDF y los envíos corren en el worker).

Rollback: **Sistema → Rollback** (apunta `current` al release anterior y restaura el dump). Se guardan
los últimos N releases.

---

## 7. Pendientes (roadmap inmediato cuando se desbloquee el PDF)

1. **Decidir y montar la estrategia de PDF** (§3, recomiendo Opción B).
2. **2.ª pasada visual del reporte** hacia Modular DS: bloque `healthscore`/gauges circulares para más
   métricas, **sparklines** en KPIs, **donut** de distribución, **tablas densas** con estado/fechas,
   bloque de estado (Actualizaciones/Uptime/Backups) con checks verdes.
3. **Admin estilo Cloudflare**: pedir al owner la pantalla concreta de referencia y replicar densidad,
   cabeceras de sección y jerarquía de tablas.
4. Mantener `PROGRESS.md` actualizado al cierre de cada sesión (CLAUDE.md §0).

---

## 8. Archivos clave tocados esta sesión (para orientarte)

- PDF: `app/Services/Pdf/HeadlessChromiumPdfRenderer.php`, `app/Services/Pdf/PdfRenderer.php`,
  `app/Services/ReportPdfService.php`, `app/Http/Controllers/Api/V1/ReportController.php` (método `pdf`).
- Borrados: `ReportController::destroy`, `ReportDefinitionController::destroy`, `routes/api.php`.
- Visual: `resources/css/app.css`, `tailwind.config.js`, `resources/js/admin/components/ui.tsx`,
  `resources/js/admin/components/DataTable.tsx`, `resources/js/admin/App.tsx`,
  `resources/js/shared/blocks/BlockRenderer.tsx`.
- Fuentes/Reportes (frontend): `resources/js/admin/screens/DataSourcesScreen.tsx`,
  `resources/js/admin/screens/ReportsScreen.tsx`, `resources/js/admin/api.ts`.
- Tests: `tests/Feature/Api/ReportApiTest.php`, `tests/Unit/HeadlessChromiumPdfRendererTest.php`.
