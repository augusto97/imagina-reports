<?php

declare(strict_types=1);

namespace App\Connectors\BetterUptime;

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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Better Stack (Uptime) connector (CLAUDE.md §9). Bearer token; reads uptime %,
 * SLA and incidents for a monitor. Field names are a documented assumption (see
 * PROGRESS Open Questions); parsing is defensive.
 */
final class BetterUptimeConnector implements DataSourceConnector, ProvidesSetupGuide
{
    use ParsesValues;

    private const API_BASE = 'https://uptime.betterstack.com/api/v2';

    public function key(): string
    {
        return DataSourceType::BetterUptime->value;
    }

    public function label(): string
    {
        return DataSourceType::BetterUptime->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('monitor_id', 'Monitor ID', ConfigFieldType::Text, help: 'ID del monitor: Better Stack → Monitors → tu monitor (aparece en la URL).'),
            new ConfigField('api_token', 'API token (Bearer)', ConfigFieldType::Password, secret: true, help: 'Better Stack → Settings → API tokens → crea un token (Bearer).'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('betteruptime.uptime_percent', 'Disponibilidad', MetricType::Scalar, 'percent'),
            new MetricDefinition('betteruptime.incidents', 'Incidentes', MetricType::Scalar, 'count'),
            new MetricDefinition('betteruptime.total_downtime', 'Tiempo caído', MetricType::Scalar, 'duration', description: 'Segundos de caída en el periodo; usa el formato «duración» en el bloque para verlo en min/h.'),
            new MetricDefinition('betteruptime.longest_incident', 'Incidente más largo', MetricType::Scalar, 'duration', description: 'Duración en segundos del incidente más largo; formatéalo como «duración».'),
            new MetricDefinition('betteruptime.average_incident', 'Incidente medio', MetricType::Scalar, 'duration', description: 'Duración media de los incidentes, en segundos; formatéalo como «duración».'),
            new MetricDefinition('betteruptime.avg_response_time', 'Tiempo de respuesta medio (ms)', MetricType::Scalar, 'ms'),
            new MetricDefinition('betteruptime.response_times', 'Tiempo de respuesta (ms/día)', MetricType::Series, dimensions: ['date']),
            new MetricDefinition('betteruptime.incidents_list', 'Incidentes (detalle)', MetricType::Table, description: 'Cada caída del periodo con inicio, duración, causa y estado.'),
            new MetricDefinition('betteruptime.uptime_by_date', 'Disponibilidad por día (%)', MetricType::Series, dimensions: ['date'], description: 'Disponibilidad diaria calculada de los incidentes — la barra verde/roja del status page.'),
        );
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'Usa un token de la API de Better Stack (Uptime) y el ID del monitor del sitio.',
            [
                'Better Stack → Uptime → Settings → API tokens (o «Integrations») → crea un API token.',
                'Pégalo en «API token (Bearer)».',
                'Abre el monitor del sitio; su ID aparece en la URL (…/monitors/EL-ID). Pégalo en «monitor_id».',
                'Guarda y pulsa «Probar conexión».',
            ],
            'https://betterstack.com/docs/uptime/api/getting-started-with-uptime-api/',
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            $response = $this->sla($source, Period::make('30 days ago', 'today'));

            return $response->successful()
                ? ConnectionResult::success('Better Stack API reachable.')
                : ConnectionResult::failure('Better Stack responded with HTTP '.$response->status());
        } catch (Throwable $e) {
            return ConnectionResult::failure('Could not reach Better Stack: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        try {
            $response = $this->sla($source, $period);
        } catch (Throwable $e) {
            return MetricSet::failed('Better Stack request error: '.$e->getMessage());
        }

        if ($response->failed()) {
            return MetricSet::failed('Better Stack request failed: HTTP '.$response->status());
        }

        $attributes = $this->arrayOf(Arr::get($this->arrayOf($response->json()), 'data.attributes'));

        $metrics = [
            'betteruptime.uptime_percent' => $this->toFloat(Arr::get($attributes, 'availability')),
            'betteruptime.incidents' => $this->toInt(Arr::get($attributes, 'number_of_incidents')),
            'betteruptime.total_downtime' => $this->toInt(Arr::get($attributes, 'total_downtime')),
            'betteruptime.longest_incident' => $this->toInt(Arr::get($attributes, 'longest_incident')),
            'betteruptime.average_incident' => $this->toInt(Arr::get($attributes, 'average_incident')),
        ];

        // The response-times chart lives in a separate endpoint — only fetch it when a
        // report asks for it. A failure there must not sink the SLA metrics above.
        if ($this->wantsResponseTimes($requestedMetrics)) {
            $metrics += $this->responseTimeMetrics($source, $period);
        }

        // Incidents drive both the table and the daily-uptime bar — fetch them once.
        $wantTable = $requestedMetrics === [] || in_array('betteruptime.incidents_list', $requestedMetrics, true);
        $wantBar = $requestedMetrics === [] || in_array('betteruptime.uptime_by_date', $requestedMetrics, true);

        if ($wantTable || $wantBar) {
            $incidents = $this->fetchIncidents($source, $period);

            if ($wantTable) {
                $metrics['betteruptime.incidents_list'] = $this->incidentTable($incidents, $this->clientTimezone($source));
            }
            if ($wantBar) {
                $metrics['betteruptime.uptime_by_date'] = $this->uptimeByDate($incidents, $period);
            }
        }

        return MetricSet::ok($metrics);
    }

    /**
     * The period's incidents (within range) from the account-level /incidents endpoint
     * filtered by monitor. A failure yields an empty list, never a failed set.
     *
     * @return list<array{started_at: string, resolved_at: string, cause: string, start: int|false, end: int|false}>
     */
    private function fetchIncidents(DataSource $source, Period $period): array
    {
        try {
            $response = $this->incidents($source, $period);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $incidents = [];
        foreach ($this->listOf(Arr::get($this->arrayOf($response->json()), 'data')) as $incident) {
            $attributes = $this->arrayOf(Arr::get($incident, 'attributes'));
            $startedAt = $this->toStr(Arr::get($attributes, 'started_at'));
            if ($startedAt === '' || ! $period->contains(substr($startedAt, 0, 10))) {
                continue;
            }

            $resolvedAt = $this->toStr(Arr::get($attributes, 'resolved_at'));
            $incidents[] = [
                'started_at' => $startedAt,
                'resolved_at' => $resolvedAt,
                'cause' => $this->toStr(Arr::get($attributes, 'cause')),
                'start' => strtotime($startedAt),
                'end' => $resolvedAt !== '' ? strtotime($resolvedAt) : false,
            ];
        }

        return $incidents;
    }

    /**
     * @param  list<array{started_at: string, resolved_at: string, cause: string, start: int|false, end: int|false}>  $incidents
     * @return list<array{Inicio: string, Duración: string, Causa: string, Estado: string}>
     */
    private function incidentTable(array $incidents, string $timezone): array
    {
        $rows = [];
        foreach ($incidents as $incident) {
            $start = $incident['start'];
            $end = $incident['end'];

            $rows[] = [
                'Inicio' => $this->formatInTimezone($incident['started_at'], $timezone),
                'Duración' => $start !== false && $end !== false
                    ? $this->humanizeSeconds($end - $start)
                    : 'En curso',
                'Causa' => $incident['cause'],
                'Estado' => $incident['resolved_at'] !== '' ? 'Resuelto' : 'En curso',
            ];
        }

        return $rows;
    }

    /**
     * Daily uptime % over the period (the green/red status-page bar), derived from the
     * incidents: each day's downtime is the incident overlap with that day, so
     * uptime = 100 − downtime/86400. Aggregating incidents avoids any per-day API call.
     *
     * @param  list<array{started_at: string, resolved_at: string, cause: string, start: int|false, end: int|false}>  $incidents
     * @return list<array{date: string, value: float}>
     */
    private function uptimeByDate(array $incidents, Period $period): array
    {
        $series = [];
        $day = $period->start->startOfDay();
        $last = $period->end->startOfDay();

        while ($day->lessThanOrEqualTo($last)) {
            $dayStart = $day->getTimestamp();
            $dayEnd = $dayStart + 86400;

            $downtime = 0;
            foreach ($incidents as $incident) {
                $start = $incident['start'];
                if ($start === false) {
                    continue;
                }
                $end = $incident['end'] === false ? $dayEnd : $incident['end'];
                $overlap = min($end, $dayEnd) - max($start, $dayStart);
                if ($overlap > 0) {
                    $downtime += $overlap;
                }
            }

            $downtime = min($downtime, 86400);
            $series[] = ['date' => $day->format('Y-m-d'), 'value' => round(100 - $downtime / 86400 * 100, 2)];
            $day = $day->addDay();
        }

        return $series;
    }

    /**
     * The client's IANA timezone (reports are per-site → client), defaulting to UTC.
     * Incident times depend on the client's country, so we render them in this zone.
     */
    private function clientTimezone(DataSource $source): string
    {
        // data_get traverses site → client → timezone, tolerating a missing relation at
        // any step (returns null) — no fragile nullsafe chain.
        $timezone = $this->toStr(data_get($source, 'site.client.timezone'));

        return $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * Format a UTC ISO timestamp in the given timezone, e.g. "10/06/2026 11:00 GMT-05:00".
     */
    private function formatInTimezone(string $iso, string $timezone): string
    {
        try {
            $moment = CarbonImmutable::parse($iso)->setTimezone($timezone);
        } catch (Throwable) {
            try {
                $moment = CarbonImmutable::parse($iso)->setTimezone('UTC');
            } catch (Throwable) {
                return $iso;
            }
        }

        return $moment->format('d/m/Y H:i').' GMT'.$moment->format('P');
    }

    /**
     * Seconds as "30 s" / "45 min" / "1 h 30 min" (matches the editor's duration format).
     */
    private function humanizeSeconds(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds.' s';
        }

        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $restMinutes = $minutes % 60;

        return $restMinutes === 0
            ? intdiv($minutes, 60).' h'
            : intdiv($minutes, 60).' h '.$restMinutes.' min';
    }

    private function incidents(DataSource $source, Period $period): Response
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];
        $monitorId = $this->toStr(Arr::get($config, 'monitor_id'));

        return Http::withToken($this->toStr(Arr::get($credentials, 'api_token')))
            ->acceptJson()
            ->timeout(20)
            ->get(self::API_BASE.'/incidents', [
                'monitor_id' => $monitorId,
                'from' => $period->start->toDateString(),
                'to' => $period->end->toDateString(),
                'per_page' => 50,
            ]);
    }

    /**
     * @param  list<string>  $requestedMetrics
     */
    private function wantsResponseTimes(array $requestedMetrics): bool
    {
        return $requestedMetrics === []
            || in_array('betteruptime.response_times', $requestedMetrics, true)
            || in_array('betteruptime.avg_response_time', $requestedMetrics, true);
    }

    /**
     * Daily-averaged response time (ms) series + overall average, from the
     * /response-times endpoint. response_time arrives in SECONDS (e.g. 0.028 = 28 ms)
     * and can span several regions; we average across all points per day.
     *
     * @return array<string, mixed>
     */
    private function responseTimeMetrics(DataSource $source, Period $period): array
    {
        try {
            $response = $this->responseTimes($source, $period);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $regions = $this->listOf(Arr::get($this->arrayOf($response->json()), 'data.attributes.regions'));

        /** @var array<string, array{sum: float, count: int}> $byDay */
        $byDay = [];
        $totalSum = 0.0;
        $totalCount = 0;

        foreach ($regions as $region) {
            foreach ($this->listOf(Arr::get($region, 'response_times')) as $point) {
                $at = $this->toStr(Arr::get($point, 'at'));
                if ($at === '') {
                    continue;
                }
                $day = substr($at, 0, 10);
                $seconds = $this->toFloat(Arr::get($point, 'response_time'));
                $byDay[$day]['sum'] = ($byDay[$day]['sum'] ?? 0.0) + $seconds;
                $byDay[$day]['count'] = ($byDay[$day]['count'] ?? 0) + 1;
                $totalSum += $seconds;
                $totalCount++;
            }
        }

        ksort($byDay);

        $series = [];
        foreach ($byDay as $day => $agg) {
            $series[] = ['date' => $day, 'value' => round(($agg['sum'] / $agg['count']) * 1000, 1)];
        }

        return [
            'betteruptime.response_times' => $series,
            'betteruptime.avg_response_time' => $totalCount > 0 ? round(($totalSum / $totalCount) * 1000, 1) : 0.0,
        ];
    }

    private function responseTimes(DataSource $source, Period $period): Response
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];
        $monitorId = $this->toStr(Arr::get($config, 'monitor_id'));

        return Http::withToken($this->toStr(Arr::get($credentials, 'api_token')))
            ->acceptJson()
            ->timeout(20)
            ->get(self::API_BASE."/monitors/{$monitorId}/response-times", [
                'from' => $period->start->toDateString(),
                'to' => $period->end->toDateString(),
            ]);
    }

    private function sla(DataSource $source, Period $period): Response
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];
        $monitorId = $this->toStr(Arr::get($config, 'monitor_id'));

        return Http::withToken($this->toStr(Arr::get($credentials, 'api_token')))
            ->acceptJson()
            ->timeout(20)
            ->get(self::API_BASE."/monitors/{$monitorId}/sla", [
                'from' => $period->start->toDateString(),
                'to' => $period->end->toDateString(),
            ]);
    }
}
