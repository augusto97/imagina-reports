<?php

declare(strict_types=1);

namespace App\Connectors\SiteAgent;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\Contracts\ProvidesSetupGuide;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\SetupGuide;
use App\Connectors\Support\ParsesValues;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Imagina Reports Site Agent connector (CLAUDE.md §9 — own-built source). Pulls a
 * normalized, already-aggregated JSON payload from the companion WordPress plugin
 * (`wp-plugin/imagina-reports-agent`) installed on the client's site, exposed at
 * `GET {site}/wp-json/imagina-reports/v1/metrics`. We own both ends, so the payload
 * shape is fixed by us (no third-party API to reverse-engineer).
 *
 * It surfaces what MainWP cannot: **real backup status** (the plugin scans the
 * backup directories on disk — WPvivid/UpdraftPlus/etc. — aggregating at the source,
 * §3.3) plus general site health (versions, plugin/update counts, storage). Reusable
 * for any future per-site metric: add a field to the plugin payload + a catalog entry.
 */
final class SiteAgentConnector implements DataSourceConnector, ProvidesSetupGuide
{
    use ParsesValues;

    private const PATH = '/wp-json/imagina-reports/v1/metrics';

    public function __construct(private ?AbandonedPluginChecker $abandonedChecker = null) {}

    private function abandonedChecker(): AbandonedPluginChecker
    {
        return $this->abandonedChecker ??= new AbandonedPluginChecker;
    }

    public function key(): string
    {
        return DataSourceType::SiteAgent->value;
    }

    public function label(): string
    {
        return DataSourceType::SiteAgent->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('url', 'URL del sitio (opcional)', ConfigFieldType::Url, required: false, help: 'Déjalo vacío para usar la URL del sitio. Solo rellénalo si el WordPress vive en otra URL (p. ej. con/sin www).'),
            new ConfigField('api_key', 'Clave del agente', ConfigFieldType::Password, secret: true, help: 'La clave que muestra el plugin «Imagina Reports Agent» en Ajustes → Imagina Reports del sitio.'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            // Backups (lo que MainWP no ve).
            new MetricDefinition('site_agent.backups_count', 'Respaldos en el periodo', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.backups_total', 'Respaldos totales', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.last_backup_days', 'Días desde el último respaldo', MetricType::Scalar, 'days'),
            new MetricDefinition('site_agent.last_backup_size_mb', 'Tamaño del último respaldo', MetricType::Scalar, 'MB'),
            new MetricDefinition('site_agent.backups_total_size_mb', 'Tamaño total de respaldos', MetricType::Scalar, 'MB'),
            new MetricDefinition('site_agent.backup_status', 'Estado de respaldos', MetricType::Table),
            new MetricDefinition('site_agent.recent_backups', 'Respaldos recientes', MetricType::Table),
            // Salud del sitio.
            new MetricDefinition('site_agent.site_health', 'Estado del sitio', MetricType::Table),
            new MetricDefinition('site_agent.plugins_active', 'Plugins activos', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.plugins_inactive', 'Plugins inactivos', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.plugins_total', 'Plugins instalados', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.updates_pending', 'Actualizaciones pendientes', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.updates_core', 'Actualizaciones de núcleo', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.updates_plugins', 'Actualizaciones de plugins', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.updates_themes', 'Actualizaciones de temas', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.abandoned_count', 'Plugins abandonados', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.abandoned_plugins', 'Detalle de plugins abandonados', MetricType::Table),
            new MetricDefinition('site_agent.db_size_mb', 'Tamaño de la base de datos', MetricType::Scalar, 'MB'),
            new MetricDefinition('site_agent.uploads_size_mb', 'Tamaño de archivos subidos', MetricType::Scalar, 'MB'),
            // SSL (monitor de certificado, equivalente a MainWP).
            new MetricDefinition('site_agent.ssl_days_remaining', 'SSL: días para caducar', MetricType::Scalar, 'days'),
            new MetricDefinition('site_agent.ssl_status', 'Estado del certificado SSL', MetricType::Table),
            // Seguridad activa.
            new MetricDefinition('site_agent.spam_blocked', 'Spam bloqueado (periodo)', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.spam_blocked_total', 'Spam bloqueado (total)', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.admin_users', 'Administradores', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.users_new', 'Usuarios nuevos', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.security_audit', 'Auditoría de seguridad', MetricType::Table),
            // Rendimiento y limpieza.
            new MetricDefinition('site_agent.cron_overdue', 'Tareas cron atrasadas', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.db_autoload_mb', 'Autoload de la BD', MetricType::Scalar, 'MB'),
            new MetricDefinition('site_agent.revisions', 'Revisiones de contenido', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.disk_free_mb', 'Espacio libre en disco', MetricType::Scalar, 'MB'),
            new MetricDefinition('site_agent.performance_status', 'Estado de rendimiento', MetricType::Table),
            new MetricDefinition('site_agent.db_cleanup', 'Limpieza de base de datos', MetricType::Table),
            // Contenido y actividad.
            new MetricDefinition('site_agent.posts_published', 'Publicaciones del periodo', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.pages_published', 'Páginas publicadas', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.comments_received', 'Comentarios recibidos', MetricType::Scalar, 'count'),
            // Captación / leads.
            new MetricDefinition('site_agent.leads', 'Solicitudes recibidas (periodo)', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.leads_total', 'Solicitudes recibidas (total)', MetricType::Scalar, 'count'),
            // E-commerce operativo.
            new MetricDefinition('site_agent.out_of_stock', 'Productos agotados', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.low_stock', 'Productos con stock bajo', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.pending_orders', 'Pedidos por atender', MetricType::Scalar, 'count'),
            // Logins (Wordfence) e imágenes (ShortPixel).
            new MetricDefinition('site_agent.failed_logins', 'Inicios de sesión fallidos', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.logins_blocked', 'Bloqueos de acceso', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.images_optimized', 'Imágenes optimizadas', MetricType::Scalar, 'count'),
            new MetricDefinition('site_agent.images_saved_mb', 'Espacio ahorrado en imágenes', MetricType::Scalar, 'MB'),
        );
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'El Agente Imagina es un plugin ligero que instalas en el WordPress del cliente. Expone, de forma segura, '
            .'el estado real de los respaldos (escanea las carpetas de WPvivid/UpdraftPlus/etc. en el sitio) y la salud del '
            .'sitio (versiones, plugins, actualizaciones, almacenamiento). Imagina Reports lo consulta por HTTPS al '
            .'sincronizar — no abre puertos ni guarda datos crudos.',
            [
                'En el sitio del cliente, sube e instala el plugin «Imagina Reports Agent» (carpeta wp-plugin/imagina-reports-agent del repositorio, comprímela en ZIP y súbela en Plugins → Añadir nuevo → Subir plugin).',
                'Actívalo. Ve a Ajustes → Imagina Reports: ahí verás una «Clave del agente» generada automáticamente.',
                'Copia esa clave y pégala aquí en «Clave del agente». Deja la URL vacía (usa la del sitio) salvo que el WordPress esté en otra URL.',
                'Guarda y pulsa «Probar conexión»: si el plugin responde, el estado pasará a «ok».',
                'Para que aparezcan respaldos, el plugin de backups del sitio debe conservar una copia local en wp-content (WPvivid y UpdraftPlus lo hacen por defecto). Los respaldos solo en la nube, sin copia local, no se miden.',
            ],
            null,
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        $url = $this->endpoint($source);

        if ($url === self::PATH) {
            return ConnectionResult::failure('Falta la URL del sitio: configúrala o asígnale un sitio con URL.');
        }

        try {
            $response = $this->client($source)->get($url);
        } catch (Throwable $e) {
            return ConnectionResult::failure('No se pudo contactar el agente: '.$e->getMessage());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ConnectionResult::failure('Clave del agente inválida (HTTP '.$response->status().'). Cópiala de nuevo desde Ajustes → Imagina Reports del sitio.');
        }

        if ($response->failed()) {
            return ConnectionResult::failure('El agente respondió HTTP '.$response->status().'. Verifica que el plugin esté instalado y activo.');
        }

        return $response->json('success') === true
            ? ConnectionResult::success('Agente Imagina conectado.')
            : ConnectionResult::failure('Respuesta inesperada del agente. ¿Está el plugin actualizado?');
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        $url = $this->endpoint($source);

        if ($url === self::PATH) {
            return MetricSet::failed('Agente Imagina: falta la URL del sitio.');
        }

        try {
            $response = $this->client($source)->get($url, [
                'from' => $period->start->format('Y-m-d'),
                'to' => $period->end->format('Y-m-d'),
            ]);
        } catch (Throwable $e) {
            return MetricSet::failed('Agente Imagina: error de petición: '.$e->getMessage());
        }

        if ($response->failed()) {
            return MetricSet::failed('Agente Imagina: HTTP '.$response->status());
        }

        $data = $this->arrayOf($response->json());

        if (($data['success'] ?? null) !== true) {
            return MetricSet::failed('Agente Imagina: respuesta inválida.');
        }

        return MetricSet::ok($this->mapMetrics($data, $requestedMetrics));
    }

    /**
     * Map the plugin payload into the normalized metric bag, only including the
     * requested metrics (empty list = all). Scalars that the agent reports as null
     * (e.g. no backup yet) stay null so the bound block hides gracefully.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $requested
     * @return array<string, mixed>
     */
    private function mapMetrics(array $data, array $requested): array
    {
        $plugins = $this->arrayOf(Arr::get($data, 'plugins'));
        $updates = $this->arrayOf(Arr::get($data, 'updates'));
        $storage = $this->arrayOf(Arr::get($data, 'storage'));
        $backups = $this->arrayOf(Arr::get($data, 'backups'));
        $ssl = $this->arrayOf(Arr::get($data, 'ssl'));
        $security = $this->arrayOf(Arr::get($data, 'security'));
        $performance = $this->arrayOf(Arr::get($data, 'performance'));
        $content = $this->arrayOf(Arr::get($data, 'content'));
        $leads = $this->arrayOf(Arr::get($data, 'leads'));
        $ecommerce = $this->arrayOf(Arr::get($data, 'ecommerce'));
        $logins = $this->arrayOf(Arr::get($data, 'logins'));
        $images = $this->arrayOf(Arr::get($data, 'images'));

        $hasLeads = $this->toStr(Arr::get($leads, 'provider')) !== '';
        $hasStore = Arr::get($ecommerce, 'active') === true;
        $hasLogins = $this->toStr(Arr::get($logins, 'provider')) !== '';
        $hasImages = $this->toStr(Arr::get($images, 'provider')) !== '';
        $sslChecked = Arr::get($ssl, 'checked') === true;

        // Abandoned-plugin detection calls wp.org (cached), so only run it when the
        // metric is actually requested (empty list = full preview = all).
        $pluginList = $this->listOf(Arr::get($plugins, 'list'));
        $wantsAbandoned = $requested === []
            || in_array('site_agent.abandoned_count', $requested, true)
            || in_array('site_agent.abandoned_plugins', $requested, true);
        $abandoned = ($wantsAbandoned && $pluginList !== [])
            ? $this->abandonedChecker()->detect($pluginList)
            : [];

        $values = [
            'site_agent.backups_count' => $this->toInt(Arr::get($backups, 'count_in_period')),
            'site_agent.backups_total' => $this->toInt(Arr::get($backups, 'count_total')),
            'site_agent.last_backup_days' => $this->numOrNull(Arr::get($backups, 'last_backup_age_days')),
            'site_agent.last_backup_size_mb' => $this->numOrNull(Arr::get($backups, 'last_backup_size_mb')),
            'site_agent.backups_total_size_mb' => $this->numOrNull(Arr::get($backups, 'total_size_mb')),
            'site_agent.backup_status' => $this->backupStatus($backups),
            'site_agent.recent_backups' => $this->recentBackups($backups),
            'site_agent.site_health' => $this->siteHealth($this->arrayOf(Arr::get($data, 'site'))),
            'site_agent.plugins_active' => $this->toInt(Arr::get($plugins, 'active')),
            'site_agent.plugins_inactive' => $this->toInt(Arr::get($plugins, 'inactive')),
            'site_agent.plugins_total' => $this->toInt(Arr::get($plugins, 'total')),
            'site_agent.updates_pending' => $this->toInt(Arr::get($updates, 'total')),
            'site_agent.updates_core' => $this->toInt(Arr::get($updates, 'core')),
            'site_agent.updates_plugins' => $this->toInt(Arr::get($updates, 'plugins')),
            'site_agent.updates_themes' => $this->toInt(Arr::get($updates, 'themes')),
            'site_agent.abandoned_count' => $wantsAbandoned ? count($abandoned) : null,
            'site_agent.abandoned_plugins' => ($wantsAbandoned && $abandoned !== []) ? $this->abandonedTable($abandoned) : null,
            'site_agent.db_size_mb' => $this->numOrNull(Arr::get($storage, 'db_size_mb')),
            'site_agent.uploads_size_mb' => $this->numOrNull(Arr::get($storage, 'uploads_size_mb')),
            // SSL (oculto si el sitio no es HTTPS o no se pudo verificar).
            'site_agent.ssl_days_remaining' => $sslChecked ? $this->numOrNull(Arr::get($ssl, 'days_until_expiry')) : null,
            'site_agent.ssl_status' => $sslChecked ? $this->sslStatus($ssl) : null,
            // Seguridad.
            'site_agent.spam_blocked' => $this->toInt(Arr::get($security, 'spam_blocked_period')),
            'site_agent.spam_blocked_total' => $this->toInt(Arr::get($security, 'spam_blocked_total')),
            'site_agent.admin_users' => $this->toInt(Arr::get($security, 'admins')),
            'site_agent.users_new' => $this->toInt(Arr::get($security, 'users_added')),
            'site_agent.security_audit' => $this->securityAudit($security),
            // Rendimiento / limpieza.
            'site_agent.cron_overdue' => $this->toInt(Arr::get($performance, 'cron_overdue')),
            'site_agent.db_autoload_mb' => $this->numOrNull(Arr::get($performance, 'autoload_mb')),
            'site_agent.revisions' => $this->toInt(Arr::get($performance, 'revisions')),
            'site_agent.disk_free_mb' => $this->numOrNull(Arr::get($performance, 'disk_free_mb')),
            'site_agent.performance_status' => $this->performanceStatus($performance),
            'site_agent.db_cleanup' => $this->dbCleanup($performance),
            // Contenido.
            'site_agent.posts_published' => $this->toInt(Arr::get($content, 'posts_published')),
            'site_agent.pages_published' => $this->toInt(Arr::get($content, 'pages_published')),
            'site_agent.comments_received' => $this->toInt(Arr::get($content, 'comments_received')),
            // Leads (ocultos si no hay plugin de formularios detectado).
            'site_agent.leads' => $hasLeads ? $this->toInt(Arr::get($leads, 'count_period')) : null,
            'site_agent.leads_total' => $hasLeads ? $this->toInt(Arr::get($leads, 'count_total')) : null,
            // E-commerce (ocultos si no hay WooCommerce).
            'site_agent.out_of_stock' => $hasStore ? $this->toInt(Arr::get($ecommerce, 'out_of_stock')) : null,
            'site_agent.low_stock' => $hasStore ? $this->toInt(Arr::get($ecommerce, 'low_stock')) : null,
            'site_agent.pending_orders' => $hasStore ? $this->toInt(Arr::get($ecommerce, 'pending_orders')) : null,
            // Logins / imágenes (ocultos si no se detecta el plugin de origen).
            'site_agent.failed_logins' => $hasLogins ? $this->toInt(Arr::get($logins, 'failed_period')) : null,
            'site_agent.logins_blocked' => $hasLogins ? $this->toInt(Arr::get($logins, 'blocked_period')) : null,
            'site_agent.images_optimized' => $hasImages ? $this->toInt(Arr::get($images, 'optimized')) : null,
            'site_agent.images_saved_mb' => $hasImages ? $this->numOrNull(Arr::get($images, 'saved_mb')) : null,
        ];

        if ($requested === []) {
            return $values;
        }

        return array_intersect_key($values, array_flip($requested));
    }

    /**
     * @param  array<array-key, mixed>  $backups
     * @return list<array<string, string>>
     */
    private function backupStatus(array $backups): array
    {
        $provider = $this->toStr(Arr::get($backups, 'provider'));
        $location = $this->toStr(Arr::get($backups, 'last_backup_location'));
        $lastAt = $this->toStr(Arr::get($backups, 'last_backup_at'));
        $ageDays = $this->numOrNull(Arr::get($backups, 'last_backup_age_days'));
        $size = $this->numOrNull(Arr::get($backups, 'last_backup_size_mb'));

        return [
            ['Concepto' => 'Proveedor de respaldo', 'Valor' => $provider !== '' ? $provider : 'No detectado'],
            ['Concepto' => 'Destino', 'Valor' => $location !== '' ? $location : '—'],
            ['Concepto' => 'Último respaldo', 'Valor' => $lastAt !== '' ? $this->humanDate($lastAt) : '—'],
            ['Concepto' => 'Antigüedad', 'Valor' => $ageDays !== null ? $this->plural((int) $ageDays, 'día', 'días') : '—'],
            ['Concepto' => 'Tamaño del último', 'Valor' => $size !== null ? $size.' MB' : '—'],
            ['Concepto' => 'Respaldos en el periodo', 'Valor' => (string) $this->toInt(Arr::get($backups, 'count_in_period'))],
            ['Concepto' => 'Respaldos totales', 'Valor' => (string) $this->toInt(Arr::get($backups, 'count_total'))],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $backups
     * @return list<array<string, string>>
     */
    private function recentBackups(array $backups): array
    {
        $rows = [];

        foreach ($this->listOf(Arr::get($backups, 'recent')) as $entry) {
            $date = $this->toStr(Arr::get($entry, 'date'));
            $size = $this->numOrNull(Arr::get($entry, 'size_mb'));
            $provider = $this->toStr(Arr::get($entry, 'provider'));
            $location = $this->toStr(Arr::get($entry, 'location'));

            $rows[] = [
                'Fecha' => $date !== '' ? $this->humanDate($date) : '—',
                'Tamaño' => $size !== null ? $size.' MB' : '—',
                'Proveedor' => $provider !== '' ? $provider : '—',
                'Destino' => $location !== '' ? $location : '—',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $site
     * @return list<array<string, string>>
     */
    private function siteHealth(array $site): array
    {
        $rows = [
            'WordPress' => $this->toStr(Arr::get($site, 'wp_version')),
            'PHP' => $this->toStr(Arr::get($site, 'php_version')),
            'Base de datos' => $this->toStr(Arr::get($site, 'mysql_version')),
            'Tema activo' => $this->toStr(Arr::get($site, 'active_theme')),
            'Idioma' => $this->toStr(Arr::get($site, 'locale')),
            'HTTPS' => Arr::get($site, 'https') === true ? 'Activo' : 'Inactivo',
        ];

        $table = [];
        foreach ($rows as $concept => $value) {
            if ($value !== '') {
                $table[] = ['Concepto' => $concept, 'Valor' => $value];
            }
        }

        return $table;
    }

    /**
     * @param  list<array{slug: string, name: string, last_updated: string, reason: string}>  $abandoned
     * @return list<array<string, string>>
     */
    private function abandonedTable(array $abandoned): array
    {
        $rows = [];

        foreach ($abandoned as $plugin) {
            $rows[] = [
                'Plugin' => $plugin['name'],
                'Última actualización' => $this->humanDate($plugin['last_updated']),
                'Motivo' => $plugin['reason'],
            ];
        }

        return $rows;
    }

    /**
     * SSL certificate summary (mirrors a MainWP-style SSL monitor).
     *
     * @param  array<array-key, mixed>  $ssl
     * @return list<array<string, string>>
     */
    private function sslStatus(array $ssl): array
    {
        $valid = Arr::get($ssl, 'valid') === true;
        $days = $this->numOrNull(Arr::get($ssl, 'days_until_expiry'));
        $issuer = $this->toStr(Arr::get($ssl, 'issuer'));
        $expires = $this->toStr(Arr::get($ssl, 'expires_at'));

        return [
            ['Concepto' => 'Estado', 'Valor' => $valid ? 'Válido ✓' : 'No válido ⚠'],
            ['Concepto' => 'Emisor', 'Valor' => $issuer !== '' ? $issuer : '—'],
            ['Concepto' => 'Caduca', 'Valor' => $expires !== '' ? $this->humanDate($expires) : '—'],
            ['Concepto' => 'Días restantes', 'Valor' => $days !== null ? (string) $days : '—'],
        ];
    }

    /**
     * Plain-language hardening checklist with a ✓/⚠ semaphore.
     *
     * @param  array<array-key, mixed>  $security
     * @return list<array<string, string>>
     */
    private function securityAudit(array $security): array
    {
        $ok = '✓ Correcto';
        $warn = '⚠ Revisar';

        return [
            ['Comprobación' => 'Indexable por buscadores', 'Estado' => Arr::get($security, 'search_engines_blocked') === true ? '⚠ Bloqueado' : $ok],
            ['Comprobación' => 'HTTPS activo', 'Estado' => Arr::get($security, 'https') === true ? $ok : $warn],
            ['Comprobación' => 'Edición de archivos deshabilitada', 'Estado' => Arr::get($security, 'file_editing_disabled') === true ? $ok : $warn],
            ['Comprobación' => 'Modo depuración desactivado', 'Estado' => Arr::get($security, 'debug_off') === true ? $ok : $warn],
            ['Comprobación' => 'Cuentas de administrador', 'Estado' => (string) $this->toInt(Arr::get($security, 'admins'))],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $performance
     * @return list<array<string, string>>
     */
    private function performanceStatus(array $performance): array
    {
        $objectType = $this->toStr(Arr::get($performance, 'object_cache_type'));
        $pageCache = $this->toStr(Arr::get($performance, 'page_cache'));
        $overdue = $this->toInt(Arr::get($performance, 'cron_overdue'));

        return [
            ['Concepto' => 'Caché de objetos', 'Valor' => $objectType !== '' ? $objectType : 'Inactiva'],
            ['Concepto' => 'Caché de página', 'Valor' => $pageCache !== '' ? $pageCache : 'Ninguna'],
            ['Concepto' => 'Tareas cron atrasadas', 'Valor' => $overdue === 0 ? 'Ninguna ✓' : (string) $overdue],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $performance
     * @return list<array<string, string>>
     */
    private function dbCleanup(array $performance): array
    {
        $autoload = $this->numOrNull(Arr::get($performance, 'autoload_mb'));

        return [
            ['Concepto' => 'Autoload', 'Valor' => $autoload !== null ? $autoload.' MB' : '—'],
            ['Concepto' => 'Revisiones de contenido', 'Valor' => (string) $this->toInt(Arr::get($performance, 'revisions'))],
            ['Concepto' => 'Entradas en papelera', 'Valor' => (string) $this->toInt(Arr::get($performance, 'trashed_posts'))],
            ['Concepto' => 'Comentarios spam', 'Valor' => (string) $this->toInt(Arr::get($performance, 'spam_comments'))],
            ['Concepto' => 'Transients caducados', 'Valor' => (string) $this->toInt(Arr::get($performance, 'expired_transients'))],
        ];
    }

    /**
     * Coerce a numeric-ish value, preserving null (so an absent metric hides) rather
     * than collapsing it to 0 (which would read as a real "0").
     */
    private function numOrNull(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? $this->toNumber($value) : null;
    }

    private function plural(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }

    /**
     * The agent emits ISO-8601 (`gmdate('c')`) or `Y-m-d H:i`; show a compact
     * `d/m/Y H:i` for the client, falling back to the raw string if unparseable.
     */
    private function humanDate(string $value): string
    {
        $ts = strtotime($value);

        return $ts === false ? $value : date('d/m/Y H:i', $ts);
    }

    private function endpoint(DataSource $source): string
    {
        $base = $this->toStr(Arr::get($source->config ?? [], 'url'));

        if ($base === '') {
            $base = $source->site->url ?? '';
        }

        return rtrim($base, '/').self::PATH;
    }

    private function client(DataSource $source): PendingRequest
    {
        $apiKey = $this->toStr(Arr::get($source->credentials ?? [], 'api_key'));

        return Http::withHeaders(['X-Imagina-Key' => $apiKey])
            ->acceptJson()
            ->timeout(20);
    }
}
