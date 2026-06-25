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
            new MetricDefinition('site_agent.db_size_mb', 'Tamaño de la base de datos', MetricType::Scalar, 'MB'),
            new MetricDefinition('site_agent.uploads_size_mb', 'Tamaño de archivos subidos', MetricType::Scalar, 'MB'),
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
            'site_agent.db_size_mb' => $this->numOrNull(Arr::get($storage, 'db_size_mb')),
            'site_agent.uploads_size_mb' => $this->numOrNull(Arr::get($storage, 'uploads_size_mb')),
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
