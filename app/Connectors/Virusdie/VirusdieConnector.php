<?php

declare(strict_types=1);

namespace App\Connectors\Virusdie;

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
 * Virusdie connector (CLAUDE.md §9). Reads malware scan results and firewall status
 * via the **MainWP Virusdie extension** on the MainWP dashboard (no separate Virusdie
 * API). Same dashboard_url + token as MainWP. The exact endpoint/fields are a
 * documented assumption (see PROGRESS Open Questions).
 */
final class VirusdieConnector implements DataSourceConnector, ProvidesSetupGuide
{
    use ParsesValues;

    private const ENDPOINT = '/wp-json/mainwp/v2/virusdie/summary';

    public function key(): string
    {
        return DataSourceType::Virusdie->value;
    }

    public function label(): string
    {
        return DataSourceType::Virusdie->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('dashboard_url', 'MainWP dashboard URL', ConfigFieldType::Url, help: 'URL del panel MainWP. Virusdie se lee a través de la extensión Virusdie de MainWP.'),
            new ConfigField('token', 'MainWP API token', ConfigFieldType::Password, secret: true, help: 'El mismo token (Bearer) de la API de MainWP.'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('virusdie.malware_found', 'Malware detectado', MetricType::Scalar, 'count'),
            new MetricDefinition('virusdie.threats_removed', 'Amenazas eliminadas', MetricType::Scalar, 'count'),
            new MetricDefinition('virusdie.infected_sites', 'Sitios infectados', MetricType::Scalar, 'count'),
            new MetricDefinition('virusdie.clean_sites', 'Sitios limpios', MetricType::Scalar, 'count'),
            new MetricDefinition('virusdie.scanned_sites', 'Sitios analizados', MetricType::Scalar, 'count'),
            new MetricDefinition('virusdie.firewall_active', 'Firewall activo', MetricType::Scalar),
            new MetricDefinition('virusdie.infected_sites_list', 'Sitios con malware', MetricType::Table, dimensions: ['site']),
        );
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'Virusdie se lee a través de la extensión Virusdie de MainWP — usa los MISMOS datos que tu fuente MainWP.',
            [
                'En tu panel MainWP ten activa la extensión «Virusdie» y los sitios protegidos con Virusdie.',
                'En «dashboard_url» y «token» usa exactamente los mismos valores que en tu fuente MainWP (URL del panel + token REST v2).',
                'Guarda y pulsa «Probar conexión».',
            ],
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            $response = $this->client($source)->get(self::ENDPOINT);

            return $response->successful()
                ? ConnectionResult::success('MainWP/Virusdie reachable.')
                : ConnectionResult::failure('MainWP responded with HTTP '.$response->status());
        } catch (Throwable $e) {
            return ConnectionResult::failure('Could not reach MainWP/Virusdie: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        try {
            $response = $this->client($source)->get(self::ENDPOINT);
        } catch (Throwable $e) {
            return MetricSet::failed('Virusdie request error: '.$e->getMessage());
        }

        if ($response->failed()) {
            return MetricSet::failed('Virusdie request failed: HTTP '.$response->status());
        }

        $data = $this->arrayOf($response->json());

        return MetricSet::ok([
            'virusdie.malware_found' => $this->toInt(Arr::get($data, 'malware_found')),
            'virusdie.threats_removed' => $this->toInt(Arr::get($data, 'threats_removed', Arr::get($data, 'malware_removed'))),
            'virusdie.infected_sites' => $this->toInt(Arr::get($data, 'infected_sites')),
            'virusdie.clean_sites' => $this->toInt(Arr::get($data, 'clean_sites')),
            'virusdie.scanned_sites' => $this->toInt(Arr::get($data, 'scanned_sites')),
            'virusdie.firewall_active' => filter_var(Arr::get($data, 'firewall_active'), FILTER_VALIDATE_BOOL) ? 1 : 0,
            'virusdie.infected_sites_list' => $this->infectedSites(Arr::get($data, 'sites')),
        ]);
    }

    /**
     * Per-site malware breakdown, keeping only infected sites as normalized table rows.
     *
     * @return list<array{label: string, value: int}>
     */
    private function infectedSites(mixed $sites): array
    {
        $rows = [];
        foreach ($this->listOf($sites) as $site) {
            $malware = $this->toInt(Arr::get($site, 'malware', Arr::get($site, 'threats')));
            if ($malware <= 0) {
                continue;
            }
            $rows[] = ['label' => $this->toStr(Arr::get($site, 'name', Arr::get($site, 'url'))), 'value' => $malware];
        }

        return $rows;
    }

    private function client(DataSource $source): PendingRequest
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];

        return Http::baseUrl(rtrim($this->toStr(Arr::get($config, 'dashboard_url')), '/'))
            ->withToken($this->toStr(Arr::get($credentials, 'token')))
            ->acceptJson()
            ->timeout(20);
    }
}
