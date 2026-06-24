<?php

declare(strict_types=1);

namespace App\Connectors\MainWp;

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
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * MainWP connector (CLAUDE.md §9). Reads the single managed site that matches this
 * data source's site URL from a MainWP dashboard's REST API (v2, Bearer token) and
 * returns its maintenance metrics: pending plugin/theme/core updates, a per-item
 * "pending updates" detail table (à la Modular DS), the plugin inventory and the
 * site's health score.
 *
 * Reports are per client/site, so this connector scopes to ONE site (matched by URL)
 * rather than aggregating the whole dashboard. The "updates applied this month"
 * number is NOT in the API — it is computed by MaintenanceDeltaCalculator from the
 * period's snapshots (CLAUDE.md §9 "MainWP work-done deltas").
 *
 * MainWP returns the upgrade/inventory fields as JSON-ENCODED STRINGS (verified
 * against a live dashboard): `plugin_upgrades`/`theme_upgrades` decode to an object
 * keyed by slug (one key per pending update), `wp_upgrades` to the core-update info,
 * and `plugins`/`themes` to a list of installed items.
 */
final class MainWpConnector implements DataSourceConnector, ProvidesSetupGuide
{
    use ParsesValues;

    private const API_PREFIX = '/wp-json/mainwp/v2';

    public function key(): string
    {
        return DataSourceType::MainWp->value;
    }

    public function label(): string
    {
        return DataSourceType::MainWp->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('dashboard_url', 'MainWP dashboard URL', ConfigFieldType::Url, help: 'URL del panel MainWP, p. ej. https://dash.tuagencia.com'),
            new ConfigField('token', 'API token (Bearer)', ConfigFieldType::Password, secret: true, help: 'En MainWP → Ajustes → REST API genera un token v2 (Bearer) con permisos de lectura.'),
        ];
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'Usa la API REST v2 de tu panel MainWP (token Bearer). Para el historial de mantenimiento hace falta un plugin extra en cada sitio hijo.',
            [
                'En tu panel MainWP → Ajustes → REST API → activa la API y genera una clave v2 con permisos de lectura.',
                'En «MainWP dashboard URL» pon la URL del panel (https://panel.tuagencia.com).',
                'Pega la clave en «API token (Bearer)».',
                'Importante: para «Lo que hicimos este mes» (historial de actualizaciones), instala y activa el plugin «MainWP Child Reports» en cada sitio hijo.',
                'Guarda y pulsa «Probar conexión».',
            ],
            'https://kb.mainwp.com/docs/rest-api/',
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            $response = $this->client($source)->get('/sites');
        } catch (Throwable $e) {
            return ConnectionResult::failure('Could not reach MainWP: '.$e->getMessage());
        }

        if ($response->failed()) {
            return ConnectionResult::failure('MainWP responded with HTTP '.$response->status());
        }

        $target = $this->targetUrl($source);
        if ($target === '') {
            return ConnectionResult::success('MainWP dashboard reachable. Asigna una URL al sitio para acotar el reporte.');
        }

        return $this->matchSite($this->extractSites($response->json()), $target) === null
            ? ConnectionResult::failure("MainWP no gestiona ningún sitio que coincida con {$target}. Revisa la URL del sitio.")
            : ConnectionResult::success('MainWP dashboard reachable; sitio localizado.');
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            // The real "what we did this month" number: the count of updates actually
            // applied in the period, from the MainWP Pro Reports activity log. Falls back
            // to a snapshot diff (MaintenanceDeltaCalculator) only if the log is empty.
            new MetricDefinition('mainwp.updates_applied', 'Actualizaciones aplicadas', MetricType::Scalar, 'count', description: 'Actualizaciones realmente aplicadas en el periodo (registro de actividad de MainWP Pro Reports).'),
            new MetricDefinition('mainwp.work_log', 'Trabajo realizado (actualizaciones)', MetricType::Table, dimensions: ['type'], description: 'Detalle fechado de cada plugin/tema/núcleo actualizado en el periodo, con su versión anterior y nueva.'),
            new MetricDefinition('mainwp.updates_available', 'Actualizaciones pendientes', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.plugin_updates', 'Plugins por actualizar', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.theme_updates', 'Temas por actualizar', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.core_updates', 'Núcleo WordPress por actualizar', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.pending_updates', 'Actualizaciones pendientes (detalle)', MetricType::Table, dimensions: ['type']),
            new MetricDefinition('mainwp.plugins_total', 'Plugins instalados', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.plugins_active', 'Plugins activos', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.health_score', 'Salud del sitio', MetricType::Scalar, 'score'),
            new MetricDefinition('mainwp.child_reports_active', 'MainWP Child Reports activo', MetricType::Scalar, 'bool', description: 'Indica si el sitio hijo registra el historial de actividad (necesario para «Lo que hicimos este mes»).'),
            // SSL Monitor + Domain Monitor extensions (read via the dashboard's
            // dedicated /ssl-monitor/info and /domain-monitor/profiles endpoints).
            new MetricDefinition('mainwp.ssl_days_remaining', 'Días para que caduque el SSL', MetricType::Scalar, 'days', description: 'Días restantes hasta que expire el certificado SSL (extensión MainWP SSL Monitor).'),
            new MetricDefinition('mainwp.domain_days_remaining', 'Días para que caduque el dominio', MetricType::Scalar, 'days', description: 'Días restantes hasta que expire el registro del dominio (extensión MainWP Domain Monitor).'),
            new MetricDefinition('mainwp.ssl_domain', 'SSL y dominio (detalle)', MetricType::Table, dimensions: ['concepto'], description: 'Certificado SSL y registro de dominio: proveedor, fecha de caducidad y días restantes.'),
            // Vulnerability Checker extension (Pro Reports `vulnerable` endpoint).
            new MetricDefinition('mainwp.vulnerabilities_count', 'Vulnerabilidades detectadas', MetricType::Scalar, 'count', description: 'Nº de vulnerabilidades conocidas (CVE) en plugins/temas del sitio (extensión MainWP Vulnerability Checker).'),
            new MetricDefinition('mainwp.vulnerabilities_list', 'Vulnerabilidades (detalle)', MetricType::Table, dimensions: ['elemento'], description: 'Plugins/temas con vulnerabilidades conocidas y su fecha de detección.'),
            // Wordfence extension (Pro Reports `wordfence` endpoint, action=scan).
            new MetricDefinition('mainwp.wordfence_scans_count', 'Escaneos de Wordfence', MetricType::Scalar, 'count', description: 'Nº de escaneos de seguridad de Wordfence ejecutados en el periodo.'),
            new MetricDefinition('mainwp.wordfence_scans', 'Escaneos de Wordfence (detalle)', MetricType::Table, dimensions: ['fecha'], description: 'Registro de escaneos de Wordfence: fecha y resultado del análisis.'),
            // Backups (UpdraftPlus/WPvivid via Pro Reports `backups`, action=created)
            // and the MainWP Maintenance tool (`maintenance`, action=process).
            new MetricDefinition('mainwp.backups_count', 'Respaldos creados', MetricType::Scalar, 'count', description: 'Nº de copias de seguridad creadas en el periodo (extensiones de backup vía MainWP).'),
            new MetricDefinition('mainwp.maintenance_count', 'Tareas de mantenimiento', MetricType::Scalar, 'count', description: 'Nº de tareas de mantenimiento ejecutadas en el periodo (herramienta de mantenimiento de MainWP).'),
            new MetricDefinition('mainwp.malware_found', 'Malware detectado', MetricType::Scalar, 'count', description: 'Amenazas/malware detectados por Virusdie en el sitio (extensión Virusdie de MainWP, último escaneo).'),
            // MainWP per-site security/hardening checklist (/sites/{id}/security).
            new MetricDefinition('mainwp.security_issues_count', 'Puntos de seguridad por revisar', MetricType::Scalar, 'count', description: 'Nº de comprobaciones de seguridad de MainWP que no están en verde (endurecimiento del sitio).'),
            new MetricDefinition('mainwp.security_checklist', 'Estado de seguridad', MetricType::Table, dimensions: ['comprobacion'], description: 'Lista de verificación de seguridad de MainWP: WordPress al día, SSL, depuración, plugins/temas obsoletos o inactivos.'),
        );
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        try {
            $response = $this->client($source)->get('/sites');
        } catch (Throwable $e) {
            return MetricSet::failed('MainWP request error: '.$e->getMessage());
        }

        if ($response->failed()) {
            return MetricSet::failed('MainWP sites request failed: HTTP '.$response->status());
        }

        $sites = $this->extractSites($response->json());
        $target = $this->targetUrl($source);

        if ($target === '') {
            return MetricSet::failed('El sitio no tiene una URL configurada; MainWP necesita la URL para identificar el sitio gestionado.');
        }

        $site = $this->matchSite($sites, $target);
        if ($site === null) {
            return MetricSet::failed("MainWP no gestiona ningún sitio que coincida con {$target}.");
        }

        $metrics = $this->metricsFor($site);

        // The "what we did this month" history (applied updates) lives in a separate,
        // heavier Pro Reports endpoint — only fetch it when a report actually asks for it.
        if ($this->wantsHistory($requestedMetrics)) {
            $idDomain = $this->toInt(Arr::get($site, 'id'));
            $history = $this->fetchWorkLog($source, $idDomain !== 0 ? (string) $idDomain : $target, $period);
            $metrics['mainwp.work_log'] = $history;
            $metrics['mainwp.updates_applied'] = count($history);
        }

        // SSL / domain expiry live in dedicated extension endpoints — only fetch them
        // when a report actually binds to one of those metrics.
        if ($this->wantsSslDomain($requestedMetrics)) {
            $metrics = array_merge($metrics, $this->sslDomainMetrics($source, $site));
        }

        // Vulnerability Checker (Pro Reports) — only when the report binds to it.
        if ($this->wantsVulnerabilities($requestedMetrics)) {
            $metrics = array_merge($metrics, $this->fetchVulnerabilities($source, $this->idDomain($site, $target), $period));
        }

        // Wordfence security scans (Pro Reports, action=scan).
        if ($this->wantsWordfence($requestedMetrics)) {
            $metrics = array_merge($metrics, $this->fetchWordfence($source, $this->idDomain($site, $target), $period));
        }

        // Simple Pro Reports counters (backups created, maintenance tasks run).
        foreach ([
            'mainwp.backups_count' => ['backups', 'created', '[backup.created.count]'],
            'mainwp.maintenance_count' => ['maintenance', 'process', '[maintenance.process.count]'],
            'mainwp.malware_found' => ['virusdie', 'scan', '[virusdie.scan.count]'],
        ] as $metric => [$endpoint, $action, $token]) {
            if ($this->wants($requestedMetrics, $metric)) {
                $count = $this->proReportCount($source, $this->idDomain($site, $target), $endpoint, $action, $token, $period);

                if ($count !== null) {
                    $metrics[$metric] = $count;
                }
            }
        }

        // MainWP security/hardening checklist (its own /sites/{id}/security endpoint).
        if ($this->wants($requestedMetrics, 'mainwp.security_checklist')
            || $this->wants($requestedMetrics, 'mainwp.security_issues_count')) {
            $metrics = array_merge($metrics, $this->securityChecklist($source, $this->idDomain($site, $target)));
        }

        return MetricSet::ok($this->only($metrics, $requestedMetrics));
    }

    /**
     * @param  list<string>  $requestedMetrics
     */
    private function wantsHistory(array $requestedMetrics): bool
    {
        return $requestedMetrics === []
            || in_array('mainwp.work_log', $requestedMetrics, true)
            || in_array('mainwp.updates_applied', $requestedMetrics, true);
    }

    /**
     * @param  list<string>  $requestedMetrics
     */
    private function wantsSslDomain(array $requestedMetrics): bool
    {
        if ($requestedMetrics === []) {
            return true;
        }

        foreach (['mainwp.ssl_days_remaining', 'mainwp.domain_days_remaining', 'mainwp.ssl_domain'] as $key) {
            if (in_array($key, $requestedMetrics, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SSL certificate + domain registration expiry for this site, read from the
     * MainWP SSL Monitor and Domain Monitor extensions. Each endpoint returns a map
     * keyed by the managed-site id; we look up this site (by id, then URL) and derive
     * the days remaining from `valid_to` / `expiry_date` (both `d/m/Y`). The
     * `31/12/1969` epoch-zero sentinel (the extension has no data yet) yields no value
     * so the block hides gracefully.
     *
     * @param  array<array-key, mixed>  $site
     * @return array<string, mixed>
     */
    private function sslDomainMetrics(DataSource $source, array $site): array
    {
        $id = $this->toStr(Arr::get($site, 'id'));
        $target = $this->targetUrl($source);

        $ssl = $this->lookupExtensionEntry($source, '/ssl-monitor/info', $id, $target);
        $domain = $this->lookupExtensionEntry($source, '/domain-monitor/profiles', $id, $target);

        $sslValidTo = $this->toStr(Arr::get($ssl, 'valid_to'));
        $sslDays = $this->daysUntil($sslValidTo);

        $domainExpiry = $this->toStr(Arr::get($domain, 'expiry_date'));
        $domainDays = $this->daysUntil($domainExpiry);

        // A date already in the past means MainWP's SSL/domain monitor scan is outdated
        // (certificates auto-renew, registrars renew), so a negative "expires in -N days"
        // countdown would mislead the client. Treat stale data as no data → block hides.
        if ($sslDays !== null && $sslDays < 0) {
            $sslDays = null;
        }
        if ($domainDays !== null && $domainDays < 0) {
            $domainDays = null;
        }

        $rows = [];
        $metrics = [];

        if ($sslDays !== null) {
            $metrics['mainwp.ssl_days_remaining'] = $sslDays;
            $rows[] = [
                'Concepto' => 'Certificado SSL',
                'Proveedor' => $this->toStr(Arr::get($ssl, 'issuer_o')),
                'Caduca' => $sslValidTo,
                'Días restantes' => $sslDays,
            ];
        }

        if ($domainDays !== null) {
            $metrics['mainwp.domain_days_remaining'] = $domainDays;
            $rows[] = [
                'Concepto' => 'Dominio',
                'Proveedor' => $this->toStr(Arr::get($domain, 'registrar')),
                'Caduca' => $domainExpiry,
                'Días restantes' => $domainDays,
            ];
        }

        if ($rows !== []) {
            $metrics['mainwp.ssl_domain'] = $rows;
        }

        return $metrics;
    }

    /**
     * Fetch an extension endpoint (data keyed by managed-site id) and return this
     * site's entry, matched by id first then by normalized URL. Empty on any failure.
     *
     * @return array<array-key, mixed>
     */
    private function lookupExtensionEntry(DataSource $source, string $endpoint, string $id, string $target): array
    {
        try {
            $response = $this->client($source)->get($endpoint);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            return [];
        }

        if ($id !== '' && isset($data[$id]) && is_array($data[$id])) {
            return $data[$id];
        }

        foreach ($data as $entry) {
            if (is_array($entry) && $this->normalizeUrl($this->toStr(Arr::get($entry, 'url'))) === $target) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * Whole days from today until a `d/m/Y` date (negative if already past). Returns
     * null for empty/invalid input or the `31/12/1969` no-data sentinel. Computed from
     * day-start timestamps so it is robust across Carbon major versions.
     */
    private function daysUntil(string $date): ?int
    {
        $date = trim($date);
        if ($date === '' || str_starts_with($date, '31/12/1969')) {
            return null;
        }

        try {
            $expiry = CarbonImmutable::createFromFormat('!d/m/Y', $date);
        } catch (Throwable) {
            return null;
        }

        if (! $expiry instanceof CarbonImmutable) {
            return null;
        }

        $seconds = $expiry->startOfDay()->getTimestamp() - CarbonImmutable::now()->startOfDay()->getTimestamp();

        return (int) round($seconds / 86400);
    }

    /**
     * The site identifier for Pro Reports endpoints: the managed-site numeric id when
     * present, otherwise the normalized site URL (the routes accept either).
     *
     * @param  array<array-key, mixed>  $site
     */
    private function idDomain(array $site, string $target): string
    {
        $id = $this->toInt(Arr::get($site, 'id'));

        return $id !== 0 ? (string) $id : $target;
    }

    /**
     * @param  list<string>  $requestedMetrics
     */
    private function wants(array $requestedMetrics, string $metric): bool
    {
        return $requestedMetrics === [] || in_array($metric, $requestedMetrics, true);
    }

    /**
     * A single Pro Reports counter token (e.g. `[backup.created.count]`). These
     * endpoints all return `data.other_tokens_data` with one count token. Returns null
     * on any failure or a missing token so the bound block hides gracefully.
     */
    private function proReportCount(DataSource $source, string $idDomain, string $endpoint, string $action, string $token, Period $period): ?int
    {
        try {
            $response = $this->client($source)->get("/pro-reports/{$idDomain}/{$endpoint}", [
                'action' => $action,
                'start' => $period->start->format('Y-m-d'),
                'end' => $period->end->format('Y-m-d'),
            ]);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $tokens = $response->json('data.other_tokens_data');

        if (! is_array($tokens) || ! array_key_exists($token, $tokens)) {
            return null;
        }

        return $this->toInt($tokens[$token]);
    }

    /**
     * MainWP's per-site security/hardening checklist (`/sites/{id}/security`). Each flag
     * is `Y` (secure) / `N` (issue) — polarity verified against real site data; anything
     * else (e.g. `Y_UNABLE`) is "not checked". Returns a plain-language table plus the
     * count of items that need attention.
     *
     * @return array<string, mixed>
     */
    private function securityChecklist(DataSource $source, string $idDomain): array
    {
        try {
            $response = $this->client($source)->get("/sites/{$idDomain}/security");
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            return [];
        }

        $labels = [
            'wp_uptodate' => 'WordPress al día',
            'phpversion_matched' => 'Versión de PHP recomendada',
            'php_reporting' => 'Errores de PHP ocultos',
            'db_reporting' => 'Errores de base de datos ocultos',
            'sslprotocol' => 'HTTPS / SSL activo',
            'debug_disabled' => 'Modo depuración desactivado',
            'sec_outdated_plugins' => 'Sin plugins obsoletos',
            'sec_inactive_plugins' => 'Sin plugins inactivos',
            'sec_outdated_themes' => 'Sin temas obsoletos',
            'sec_inactive_themes' => 'Sin temas inactivos',
        ];

        $rows = [];
        $issues = 0;

        foreach ($labels as $key => $label) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = strtoupper($this->toStr($data[$key]));
            $state = match (true) {
                $value === 'Y' => '✓ Seguro',
                $value === 'N' => '⚠ Revisar',
                default => '—',
            };

            if ($value === 'N') {
                $issues++;
            }

            $rows[] = ['Comprobación' => $label, 'Estado' => $state];
        }

        if ($rows === []) {
            return [];
        }

        return [
            'mainwp.security_issues_count' => $issues,
            'mainwp.security_checklist' => $rows,
        ];
    }

    /**
     * @param  list<string>  $requestedMetrics
     */
    private function wantsWordfence(array $requestedMetrics): bool
    {
        if ($requestedMetrics === []) {
            return true;
        }

        return in_array('mainwp.wordfence_scans_count', $requestedMetrics, true)
            || in_array('mainwp.wordfence_scans', $requestedMetrics, true);
    }

    /**
     * Wordfence security scans for this site from the MainWP Wordfence extension (Pro
     * Reports `wordfence` endpoint, `action=scan`). Rows live in `data.sections_data`,
     * each carrying the bracket tokens `[wordfence.scan.date]`, `[wordfence.scan.time]`
     * and `[wordfence.scan.details]` (the human "Scan complete — N issues" summary).
     *
     * @return array<string, mixed>
     */
    private function fetchWordfence(DataSource $source, string $idDomain, Period $period): array
    {
        try {
            $response = $this->client($source)->get("/pro-reports/{$idDomain}/wordfence", [
                'action' => 'scan',
                'start' => $period->start->format('Y-m-d'),
                'end' => $period->end->format('Y-m-d'),
            ]);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $scans = [];
        foreach ($this->historyRows($response->json('data')) as $row) {
            $date = trim($this->toStr($row['[wordfence.scan.date]'] ?? '').' '.$this->toStr($row['[wordfence.scan.time]'] ?? ''));
            $details = $this->toStr($row['[wordfence.scan.details]'] ?? '');

            if ($date === '' && $details === '') {
                continue;
            }

            $scans[] = ['Fecha' => $date, 'Detalle' => $details];
        }

        $metrics = ['mainwp.wordfence_scans_count' => count($scans)];

        if ($scans !== []) {
            $metrics['mainwp.wordfence_scans'] = $scans;
        }

        return $metrics;
    }

    /**
     * @param  list<string>  $requestedMetrics
     */
    private function wantsVulnerabilities(array $requestedMetrics): bool
    {
        if ($requestedMetrics === []) {
            return true;
        }

        return in_array('mainwp.vulnerabilities_count', $requestedMetrics, true)
            || in_array('mainwp.vulnerabilities_list', $requestedMetrics, true);
    }

    /**
     * Known plugin/theme vulnerabilities (CVE) for this site from the MainWP
     * Vulnerability Checker extension (Pro Reports `vulnerable` endpoint). The payload
     * exposes a flat `other_tokens_data` map: `[vulnerabilities.count]` (total) plus
     * `[vulnerable.plugins]`/`[vulnerable.themes]` HTML blobs where each affected item
     * is a `slug: date` line followed by its `<br/>`-separated description.
     *
     * @return array<string, mixed>
     */
    private function fetchVulnerabilities(DataSource $source, string $idDomain, Period $period): array
    {
        try {
            $response = $this->client($source)->get("/pro-reports/{$idDomain}/vulnerable", [
                'start' => $period->start->format('Y-m-d'),
                'end' => $period->end->format('Y-m-d'),
            ]);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $tokens = $response->json('data.other_tokens_data');
        if (! is_array($tokens)) {
            return [];
        }

        $metrics = [
            'mainwp.vulnerabilities_count' => $this->toInt($tokens['[vulnerabilities.count]'] ?? 0),
        ];

        $rows = $this->vulnerabilityRows(
            $this->toStr($tokens['[vulnerable.plugins]'] ?? '').' '.$this->toStr($tokens['[vulnerable.themes]'] ?? ''),
        );

        if ($rows !== []) {
            $metrics['mainwp.vulnerabilities_list'] = $rows;
        }

        return $metrics;
    }

    /**
     * Parse the affected-item header lines (`slug: dd/mm/yyyy …`) out of the
     * Vulnerability Checker HTML blob, ignoring the long CVE description lines.
     *
     * @return list<array{Elemento: string, Detectada: string}>
     */
    private function vulnerabilityRows(string $blob): array
    {
        $rows = [];

        foreach (preg_split('#<br\s*/?>#i', $blob) ?: [] as $part) {
            $line = trim($this->toStr($part));

            if (preg_match('#^([\w\-./]+):\s+(\d{1,2}/\d{1,2}/\d{4}[^<]*)$#', $line, $matches) === 1) {
                $rows[] = ['Elemento' => $matches[1], 'Detectada' => trim($matches[2])];
            }
        }

        return $rows;
    }

    /**
     * The dated "what we did this month" log: every plugin/theme/core update actually
     * applied in the period (MainWP Pro Reports activity log, `action=updated`). Each
     * Pro Reports section returns rows keyed by bracket tokens (e.g. `[plugin.name]`,
     * `[plugin.old.version]`, `[plugin.current.version]`, `[plugin.updated.utime]`),
     * which we read by suffix so plugins/themes/core share one parser.
     *
     * @return list<array{Fecha: string, Tipo: string, Elemento: string, Versión: string}>
     */
    private function fetchWorkLog(DataSource $source, string $idDomain, Period $period): array
    {
        $params = [
            'action' => 'updated',
            'start' => $period->start->format('Y-m-d'),
            'end' => $period->end->format('Y-m-d'),
        ];

        /** @var list<array{utime: string, row: array{Fecha: string, Tipo: string, Elemento: string, Versión: string}}> $entries */
        $entries = [];

        foreach (['plugins' => 'Plugin', 'themes' => 'Tema', 'wordpress' => 'WordPress'] as $endpoint => $label) {
            try {
                $response = $this->client($source)->get("/pro-reports/{$idDomain}/{$endpoint}", $params);
            } catch (Throwable) {
                continue;
            }

            if ($response->failed()) {
                continue;
            }

            foreach ($this->historyRows($response->json('data')) as $row) {
                $entry = $this->workLogEntry($row, $label, $period);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        // Most recent first.
        usort($entries, static fn (array $a, array $b): int => strcmp($b['utime'], $a['utime']));

        return array_map(static fn (array $entry): array => $entry['row'], $entries);
    }

    /**
     * Flatten a Pro Reports `data` payload to its rows. Populated responses wrap the
     * rows in `sections_data` (a list of sections, each a list of rows); empty ones
     * return `data: []`.
     *
     * @return list<array<array-key, mixed>>
     */
    private function historyRows(mixed $data): array
    {
        if (! is_array($data) || ! is_array($data['sections_data'] ?? null)) {
            return [];
        }

        $rows = [];
        foreach ($data['sections_data'] as $section) {
            foreach ($this->listOf($section) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array{utime: string, row: array{Fecha: string, Tipo: string, Elemento: string, Versión: string}}|null
     */
    private function workLogEntry(array $row, string $label, Period $period): ?array
    {
        $name = $this->suffixed($row, '.name]');
        if ($name === '') {
            return null;
        }

        $utime = $this->suffixed($row, '.updated.utime]');
        $day = $utime !== '' ? substr($utime, 0, 10) : '';

        // Keep only updates inside the period (the API is asked for the range, but we
        // filter by the row's own timestamp to be exact).
        if ($day !== '' && ! $period->contains($day)) {
            return null;
        }

        $old = $this->suffixed($row, '.old.version]');
        $new = $this->suffixed($row, '.current.version]');
        $version = match (true) {
            $old !== '' && $new !== '' => "{$old} → {$new}",
            $new !== '' => $new,
            default => $old,
        };

        $fecha = $day !== '' ? $this->formatDay($day) : $this->suffixed($row, '.updated.date]');

        return [
            'utime' => $utime,
            'row' => [
                'Fecha' => $fecha,
                'Tipo' => $label,
                'Elemento' => $name,
                'Versión' => $version,
            ],
        ];
    }

    /**
     * First value whose key ends with the given bracket-token suffix (e.g. `.name]`),
     * so one parser serves the `[plugin.*]`, `[theme.*]` and `[wordpress.*]` tokens.
     *
     * @param  array<array-key, mixed>  $row
     */
    private function suffixed(array $row, string $suffix): string
    {
        foreach ($row as $key => $value) {
            if (is_string($key) && str_ends_with($key, $suffix)) {
                return $this->toStr($value);
            }
        }

        return '';
    }

    private function formatDay(string $day): string
    {
        try {
            return CarbonImmutable::parse($day)->format('d/m/Y');
        } catch (Throwable) {
            return $day;
        }
    }

    private function client(DataSource $source): PendingRequest
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];

        $baseUrl = rtrim($this->toStr(Arr::get($config, 'dashboard_url', '')), '/').self::API_PREFIX;

        return Http::baseUrl($baseUrl)
            ->withToken($this->toStr(Arr::get($credentials, 'token', '')))
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * The site URL this data source reports on (CLAUDE.md §5: reports are per-site).
     */
    private function targetUrl(DataSource $source): string
    {
        return $this->normalizeUrl($this->toStr($source->site->url ?? ''));
    }

    /**
     * MainWP may return a bare list of sites or wrap it under data/sites.
     *
     * @return list<array<array-key, mixed>>
     */
    private function extractSites(mixed $payload): array
    {
        $list = match (true) {
            is_array($payload) && array_is_list($payload) => $payload,
            is_array($payload) && is_array($payload['data'] ?? null) => $payload['data'],
            is_array($payload) && is_array($payload['sites'] ?? null) => $payload['sites'],
            default => [],
        };

        return array_values(array_filter($list, is_array(...)));
    }

    /**
     * Find the managed site whose URL matches the target (host + path, scheme/www/slash
     * insensitive), or null when none does.
     *
     * @param  list<array<array-key, mixed>>  $sites
     * @return array<array-key, mixed>|null
     */
    private function matchSite(array $sites, string $target): ?array
    {
        if ($target === '') {
            return null;
        }

        foreach ($sites as $site) {
            if ($this->normalizeUrl($this->toStr(Arr::get($site, 'url', ''))) === $target) {
                return $site;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $site
     * @return array<string, mixed>
     */
    private function metricsFor(array $site): array
    {
        $pluginUpgrades = $this->decode(Arr::get($site, 'plugin_upgrades'));
        $themeUpgrades = $this->decode(Arr::get($site, 'theme_upgrades'));
        $wpUpgrade = $this->decode(Arr::get($site, 'wp_upgrades'));
        $plugins = $this->decode(Arr::get($site, 'plugins'));

        $pluginCount = count($pluginUpgrades);
        $themeCount = count($themeUpgrades);
        $coreCount = $wpUpgrade === [] ? 0 : 1;

        $pluginsActive = 0;
        foreach ($plugins as $plugin) {
            if (is_array($plugin) && $this->truthy(Arr::get($plugin, 'active'))) {
                $pluginsActive++;
            }
        }

        return [
            'mainwp.updates_available' => $pluginCount + $themeCount + $coreCount,
            'mainwp.plugin_updates' => $pluginCount,
            'mainwp.theme_updates' => $themeCount,
            'mainwp.core_updates' => $coreCount,
            'mainwp.pending_updates' => $this->pendingTable($pluginUpgrades, $themeUpgrades, $wpUpgrade, $site),
            'mainwp.plugins_total' => count($plugins),
            'mainwp.plugins_active' => $pluginsActive,
            'mainwp.health_score' => $this->toInt(Arr::get($site, 'health_score', 0)),
            // Whether the child site runs MainWP Child Reports — required for the dated
            // "what we did" history (the Pro Reports activity log). Drives the editor hint.
            'mainwp.child_reports_active' => $this->childReportsActive($plugins),
        ];
    }

    /**
     * 1 when the MainWP Child Reports plugin is installed and active on the child site
     * (its slug/name appears in the plugin inventory) — the source of the work-log
     * history. Without it MainWP records no per-site activity stream.
     *
     * @param  array<array-key, mixed>  $plugins
     */
    private function childReportsActive(array $plugins): int
    {
        foreach ($plugins as $plugin) {
            if (! is_array($plugin)) {
                continue;
            }

            $slug = strtolower($this->toStr(Arr::get($plugin, 'slug')));
            $name = strtolower($this->toStr(Arr::get($plugin, 'name')));

            if ((str_contains($slug, 'mainwp-child-reports') || str_contains($name, 'child reports'))
                && $this->truthy(Arr::get($plugin, 'active'))) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Build the per-item "pending updates" detail (Modular-DS style): one row per
     * plugin/theme/core update with its current and target version.
     *
     * @param  array<array-key, mixed>  $pluginUpgrades
     * @param  array<array-key, mixed>  $themeUpgrades
     * @param  array<array-key, mixed>  $wpUpgrade
     * @param  array<array-key, mixed>  $site
     * @return list<array{Tipo: string, Elemento: string, Actual: string, Nueva: string}>
     */
    private function pendingTable(array $pluginUpgrades, array $themeUpgrades, array $wpUpgrade, array $site): array
    {
        $rows = [];

        foreach ($pluginUpgrades as $slug => $info) {
            $rows[] = $this->upgradeRow('Plugin', $slug, $this->arrayOf($info));
        }

        foreach ($themeUpgrades as $slug => $info) {
            $rows[] = $this->upgradeRow('Tema', $slug, $this->arrayOf($info));
        }

        if ($wpUpgrade !== []) {
            $rows[] = [
                'Tipo' => 'WordPress',
                'Elemento' => 'Núcleo de WordPress',
                'Actual' => $this->toStr(Arr::get($wpUpgrade, 'current', Arr::get($site, 'wp_version', ''))),
                'Nueva' => $this->toStr(Arr::get($wpUpgrade, 'new', '')),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $info
     * @return array{Tipo: string, Elemento: string, Actual: string, Nueva: string}
     */
    private function upgradeRow(string $type, int|string $slug, array $info): array
    {
        $name = $this->toStr(Arr::get($info, 'Name', Arr::get($info, 'name', (string) $slug)));

        return [
            'Tipo' => $type,
            'Elemento' => $name !== '' ? $name : (string) $slug,
            'Actual' => $this->toStr(Arr::get($info, 'Version', Arr::get($info, 'version', ''))),
            'Nueva' => $this->toStr(Arr::get($info, 'update.new_version', '')),
        ];
    }

    /**
     * Decode a MainWP field that may arrive as a JSON-encoded string, an array, or
     * be absent. Always returns an array (empty when not decodable).
     *
     * @return array<array-key, mixed>
     */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    /**
     * Normalize a URL for matching: lowercase host+path, drop scheme, leading "www."
     * and any trailing slash. Returns '' for empty/invalid input.
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $url = preg_replace('#^https?://#i', '', $url) ?? $url;
        $url = preg_replace('#^www\.#i', '', $url) ?? $url;

        return strtolower(rtrim($url, '/'));
    }

    /**
     * Keep only the requested metric keys; an empty request returns everything.
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $requestedMetrics
     * @return array<string, mixed>
     */
    private function only(array $metrics, array $requestedMetrics): array
    {
        if ($requestedMetrics === []) {
            return $metrics;
        }

        return array_intersect_key($metrics, array_flip($requestedMetrics));
    }
}
