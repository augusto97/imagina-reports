=== Imagina Reports Agent ===
Contributors: imaginawp
Tags: reporting, backups, monitoring, maintenance
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
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

= 1.0.0 =
* Primera versión: endpoint de métricas (respaldos + salud del sitio) + página de ajustes con clave.
