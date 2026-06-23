<?php

declare(strict_types=1);

namespace App\Connectors\MainWp;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\Support\ParsesValues;
use App\Enums\DataSourceType;
use App\Models\DataSource;
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
final class MainWpConnector implements DataSourceConnector
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
            // Computed by the report engine (MaintenanceDeltaCalculator) from the period's
            // snapshots — the "what we did this month" number, not fetched from the API.
            new MetricDefinition('mainwp.updates_applied', 'Actualizaciones aplicadas', MetricType::Scalar, 'count', description: 'Calculada comparando los snapshots del periodo; no la devuelve la API.'),
            new MetricDefinition('mainwp.updates_available', 'Actualizaciones pendientes', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.plugin_updates', 'Plugins por actualizar', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.theme_updates', 'Temas por actualizar', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.core_updates', 'Núcleo WordPress por actualizar', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.pending_updates', 'Actualizaciones pendientes (detalle)', MetricType::Table, dimensions: ['type']),
            new MetricDefinition('mainwp.plugins_total', 'Plugins instalados', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.plugins_active', 'Plugins activos', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.health_score', 'Salud del sitio', MetricType::Scalar, 'score'),
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

        return MetricSet::ok($this->only($this->metricsFor($site), $requestedMetrics));
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
        ];
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
