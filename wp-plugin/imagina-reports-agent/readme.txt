=== Imagina Reports Agent ===
Contributors: imaginawp
Tags: reporting, backups, monitoring, maintenance
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expone, de forma segura, el estado de respaldos y la salud del sitio para Imagina Reports.

== Description ==

Imagina Reports Agent es un plugin ligero que se instala en el WordPress de cada
cliente. Permite que **Imagina Reports** (la plataforma de informes de Imagina WP)
consulte por HTTPS, al sincronizar, datos que MainWP no expone:

* **Respaldos reales**: escanea las carpetas de backup en disco (WPvivid, UpdraftPlus,
  All-in-One WP Migration, BackWPup, BackUpWordPress, Duplicator) y reporta el último
  respaldo, su antigüedad, tamaño y el total — sin abrir los archivos (agrega en origen).
* **Salud del sitio**: versiones de WordPress/PHP/MySQL, tema activo, plugins
  (activos/inactivos/total), actualizaciones pendientes y almacenamiento (BD + subidas).

No abre puertos entrantes ni almacena datos crudos: solo responde a una petición de
lectura autenticada con una clave secreta.

== Instalación ==

1. Comprime la carpeta `imagina-reports-agent` en un ZIP.
2. En el sitio del cliente: Plugins → Añadir nuevo → Subir plugin → elige el ZIP → Instalar → Activar.
3. Ve a Ajustes → Imagina Reports y copia la «Clave del agente».
4. En Imagina Reports, añade una fuente «Agente Imagina (sitio)» en el sitio y pega la clave.
5. Pulsa «Probar conexión». Listo.

== Privacidad y seguridad ==

* El endpoint `GET /wp-json/imagina-reports/v1/metrics` exige la clave (cabecera
  `X-Imagina-Key` o `?key=`), comparada en tiempo constante.
* Solo devuelve agregados (conteos, tamaños, fechas). No expone contenido, usuarios ni credenciales.
* Regenera la clave cuando quieras desde Ajustes → Imagina Reports (recuerda actualizarla en Imagina Reports).

== Notas sobre respaldos ==

El agente mide los respaldos que tienen **copia local** en `wp-content` (lo habitual en
WPvivid y UpdraftPlus). Los respaldos que se suben a la nube y se borran del servidor no
dejan archivo local que medir; configura tu plugin de backup para conservar una copia
local si quieres verlos en el informe.

== Changelog ==

= 1.9.0 =
* **Historial de actualizaciones local**: el agente registra cada actualización de plugin, tema o núcleo en el momento en que ocurre (hook `upgrader_process_complete`), con fecha y cambio de versión «de→a», y lo guarda en el propio sitio. Así, basta tener el plugin instalado para acumular historial — aunque el sitio se conecte a Imagina Reports meses después y a mitad de mes. Nuevo bloque `activity` en el payload (applied_in_period + entries). Al activar se siembra el mapa de versiones para que la primera actualización ya tenga «de→a».

= 1.8.0 =
* Leads: añadido soporte para Elementor Pro (e_submissions) y JetFormBuilder (jet_fb_records). Si hay varios plugins de formularios instalados, elige el que más envíos tiene (el realmente usado).

= 1.7.0 =
* Leads: contador de envíos de formularios con detección por prioridad — Bit Form (tabla bitforms_form_entries), Fluent Forms (fluentform_submissions) y Contact Form 7 (Flamingo). Total y del periodo.

= 1.6.0 =
* Logins: intentos fallidos y bloqueos por periodo vía Wordfence (tablas wflogins/wfblocks7), con fallback a Limit Login Attempts.
* Imágenes optimizadas y espacio ahorrado vía ShortPixel (tabla shortpixel_postmeta).
* /diagnostics ahora también sondea Bit Form (bitforms) para descubrir su almacenamiento de envíos.

= 1.5.0 =
* Inventario de plugins (slug/nombre/versión) en el payload, para que Imagina Reports detecte plugins abandonados consultando wp.org desde el lado servidor (no desde el sitio).
* /diagnostics ampliado: descubre el almacenamiento (opciones + columnas y nº de filas, nunca valores) de plugins de formularios, seguridad e imágenes, para programar sus lectores sin adivinar.

= 1.4.0 =
* Monitor SSL: lee el certificado del propio dominio por TLS (caducidad, días restantes, emisor, validez), como el monitor SSL de MainWP. Permite que el agente sustituya los datos por-sitio que se extraían de MainWP.

= 1.3.0 =
* Nuevas métricas: seguridad activa (spam bloqueado, administradores, usuarios nuevos, auditoría de endurecimiento), rendimiento (caché, cron, limpieza de BD, disco), contenido del periodo, leads de Contact Form 7 (Flamingo) y operativa de WooCommerce (stock agotado/bajo, pedidos por atender).

= 1.2.1 =
* Escaneo de disco de WPvivid: añadidos los nombres de carpeta reales `wpvivid_uploads` y `WPvivid_Uploads` (además de `wpvividbackups`).

= 1.2.0 =
* Detección de respaldos de WPvivid en la nube (Google Drive/Dropbox/S3…) vía `wpvivid_backup_reports`, incluso sin copia local; destino legible desde la lista de remotos (sin exponer tokens).

= 1.1.0 =
* Detección de respaldos en la nube de UpdraftPlus (historial `updraft_backup_history`) incluso sin copia local; destino visible (Google Drive, Dropbox, S3…).
* Endpoint de diagnóstico `/diagnostics` (gateado por clave) para descubrir el almacenamiento de WPvivid/UpdraftPlus sin exponer valores.

= 1.0.0 =
* Primera versión: endpoint de métricas (respaldos + salud del sitio) + página de ajustes con clave.
