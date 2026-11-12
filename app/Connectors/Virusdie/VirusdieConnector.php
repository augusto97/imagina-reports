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
 * Virusdie connector (CLAUDE.md §9). Reads Virusdie's scans via the **MainWP
 * Virusdie extension** on the MainWP dashboard (same dashboard_url + token as MainWP).
 * Per-site: it calls the Pro Reports endpoint `/pro-reports/{id_domain}/virusdie?
 * action=scan` (validated against the live dashboard), which exposes the count in
 * `data.other_tokens_data['[virusdie.scan.count]']` (MainWP documents it as
 * `[virusdie.scans.count]`, «number of scans performed»): scans RUN, not threats found.
 */
final class VirusdieConnector implements DataSourceConnector, ProvidesSetupGuide
{
    use ParsesValues;

    private const API_PREFIX = '/wp-json/mainwp/v2';

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
            // The count token is the number of scans RUN, not threats found.
            new MetricDefinition('virusdie.scans', 'Escaneos antimalware', MetricType::Scalar, 'count', description: 'Veces que Virusdie revisó el sitio en busca de malware este mes, leídas a través de la extensión de MainWP.'),
        );
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'Virusdie se lee a través de la extensión Virusdie de MainWP — usa los MISMOS datos que tu fuente MainWP.',
            [
                'En tu panel MainWP ten activa la extensión «Virusdie» y el sitio protegido con Virusdie.',
                'En «dashboard_url» y «token» usa exactamente los mismos valores que en tu fuente MainWP (URL del panel + token REST v2).',
                'Asegúrate de que el sitio (cliente) tenga su URL configurada — Virusdie identifica el sitio por su dominio.',
                'Guarda y pulsa «Probar conexión».',
            ],
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        $idDomain = $this->idDomain($source);
        if ($idDomain === '') {
            return ConnectionResult::failure('Asigna una URL al sitio para leer Virusdie.');
        }

        try {
            $response = $this->client($source)->get("/pro-reports/{$idDomain}/virusdie", ['action' => 'scan']);

            return $response->successful()
                ? ConnectionResult::success('MainWP/Virusdie reachable.')
                : ConnectionResult::failure('MainWP responded with HTTP '.$response->status());
        } catch (Throwable $e) {
            return ConnectionResult::failure('Could not reach MainWP/Virusdie: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        $idDomain = $this->idDomain($source);
        if ($idDomain === '') {
            return MetricSet::failed('El sitio no tiene URL; Virusdie (vía MainWP) la necesita para identificar el sitio.');
        }

        try {
            $response = $this->client($source)->get("/pro-reports/{$idDomain}/virusdie", [
                'action' => 'scan',
                'start_date' => $period->start->format('Y-m-d'),
                'end_date' => $period->end->format('Y-m-d'),
            ]);
        } catch (Throwable $e) {
            return MetricSet::failed('Virusdie request error: '.$e->getMessage());
        }

        if ($response->failed()) {
            return MetricSet::failed('Virusdie request failed: HTTP '.$response->status());
        }

        $tokens = $this->arrayOf($response->json('data.other_tokens_data'));

        $count = $tokens['[virusdie.scans.count]'] ?? $tokens['[virusdie.scan.count]'] ?? null;
        if (! is_numeric($count)) {
            return MetricSet::partial([], 'Virusdie no devolvió el número de escaneos del periodo.');
        }

        return MetricSet::ok(['virusdie.scans' => $this->toInt($count)]);
    }

    /**
     * The site domain Virusdie is scoped to (host only, scheme/www/slash-insensitive).
     */
    private function idDomain(DataSource $source): string
    {
        $url = trim($this->toStr($source->site->url ?? ''));
        if ($url === '') {
            return '';
        }

        $url = preg_replace('#^https?://#i', '', $url) ?? $url;
        $url = preg_replace('#^www\.#i', '', $url) ?? $url;

        return strtolower(rtrim($url, '/'));
    }

    private function client(DataSource $source): PendingRequest
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];

        return Http::baseUrl(rtrim($this->toStr(Arr::get($config, 'dashboard_url')), '/').self::API_PREFIX)
            ->withToken($this->toStr(Arr::get($credentials, 'token')))
            ->acceptJson()
            ->timeout(20);
    }
}
