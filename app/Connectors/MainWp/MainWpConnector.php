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
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * MainWP connector (CLAUDE.md §9). Reads managed sites, available updates, the
 * plugin/theme/core inventory, abandoned plugins and SSL state from a MainWP
 * dashboard's REST API (v2, Bearer token) and returns an aggregated metric bag.
 *
 * Aggregates at the source (§3.3) and catches its own errors → partial/failed
 * MetricSet (§7). The exact v2 endpoint paths and JSON field names are an
 * assumption to validate against a live dashboard — see PROGRESS Open Questions.
 */
final class MainWpConnector implements DataSourceConnector
{
    private const API_PREFIX = '/wp-json/mainwp/v2';

    private const SSL_EXPIRY_WARNING_DAYS = 30;

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
            new ConfigField('dashboard_url', 'MainWP dashboard URL', ConfigFieldType::Url, help: 'e.g. https://dash.example.com'),
            new ConfigField('token', 'API token (Bearer)', ConfigFieldType::Password, secret: true),
        ];
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            $response = $this->client($source)->get('/sites');

            return $response->successful()
                ? ConnectionResult::success('MainWP dashboard reachable.')
                : ConnectionResult::failure('MainWP responded with HTTP '.$response->status());
        } catch (Throwable $e) {
            return ConnectionResult::failure('Could not reach MainWP: '.$e->getMessage());
        }
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('mainwp.sites_total', 'Managed sites', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.updates_available', 'Pending updates', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.plugin_updates', 'Plugin updates pending', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.theme_updates', 'Theme updates pending', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.core_updates', 'WordPress core updates pending', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.abandoned_plugins', 'Abandoned plugins', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.ssl_expiring', 'Sites with SSL expiring soon', MetricType::Scalar, 'count'),
            new MetricDefinition('mainwp.sites', 'Sites overview', MetricType::Table),
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
        $metrics = $this->aggregate($sites);

        return MetricSet::ok($this->only($metrics, $requestedMetrics));
    }

    private function client(DataSource $source): PendingRequest
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];

        $baseUrl = rtrim($this->str(Arr::get($config, 'dashboard_url', '')), '/').self::API_PREFIX;

        return Http::baseUrl($baseUrl)
            ->withToken($this->str(Arr::get($credentials, 'token', '')))
            ->acceptJson()
            ->timeout(20);
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
     * @param  list<array<array-key, mixed>>  $sites
     * @return array<string, mixed>
     */
    private function aggregate(array $sites): array
    {
        $pluginUpdates = 0;
        $themeUpdates = 0;
        $coreUpdates = 0;
        $abandoned = 0;
        $sslExpiring = 0;
        $table = [];

        foreach ($sites as $site) {
            $plugins = $this->countFor($site, 'plugins', 'plugin_upgrades');
            $themes = $this->countFor($site, 'themes', 'theme_upgrades');
            $core = $this->countFor($site, 'wp', 'wp_upgrades');

            $pluginUpdates += $plugins;
            $themeUpdates += $themes;
            $coreUpdates += $core;
            $abandoned += $this->toInt(Arr::get($site, 'abandoned_plugins', 0));

            if ($this->sslExpiringSoon($site)) {
                $sslExpiring++;
            }

            $table[] = [
                'name' => $this->str(Arr::get($site, 'name', '')),
                'url' => $this->str(Arr::get($site, 'url', '')),
                'plugin_updates' => $plugins,
                'theme_updates' => $themes,
                'core_updates' => $core,
            ];
        }

        return [
            'mainwp.sites_total' => count($sites),
            'mainwp.updates_available' => $pluginUpdates + $themeUpdates + $coreUpdates,
            'mainwp.plugin_updates' => $pluginUpdates,
            'mainwp.theme_updates' => $themeUpdates,
            'mainwp.core_updates' => $coreUpdates,
            'mainwp.abandoned_plugins' => $abandoned,
            'mainwp.ssl_expiring' => $sslExpiring,
            'mainwp.sites' => $table,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $site
     */
    private function countFor(array $site, string $nestedKey, string $flatKey): int
    {
        $nested = Arr::get($site, "update_counts.{$nestedKey}");

        return $this->toInt($nested ?? Arr::get($site, $flatKey, 0));
    }

    /**
     * @param  array<array-key, mixed>  $site
     */
    private function sslExpiringSoon(array $site): bool
    {
        $expiresAt = Arr::get($site, 'ssl.expires_at');

        if (! is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        try {
            $expiry = CarbonImmutable::parse($expiresAt);
        } catch (Throwable) {
            return false;
        }

        return $expiry->isBefore(CarbonImmutable::now()->addDays(self::SSL_EXPIRY_WARNING_DAYS));
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
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
