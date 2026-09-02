<?php

declare(strict_types=1);

namespace App\Connectors\TrueRanker;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\Connect\ConnectableResources;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\Contracts\ListsConnectableResources;
use App\Connectors\Contracts\ProvidesSetupGuide;
use App\Connectors\Exceptions\DiscoveryFailed;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\SetupGuide;
use App\Connectors\Support\DescribesApiErrors;
use App\Connectors\Support\ParsesValues;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * TrueRanker connector (CLAUDE.md §9 — keyword rank tracking). Reads a project's tracked
 * keywords and their ranking history for the period from the TrueRanker API
 * (`https://app.trueranker.com/data`, auth via the `key` query param) and computes the SEO
 * KPIs the report shows: average position, keywords in top 3/10/100, improved/declined, and
 * a top-keywords table. TrueRanker exposes no server-side aggregate endpoint, so we compute
 * the aggregates in-app — but the input is BOUNDED by the project's tracked keyword set (the
 * project caps it), not millions of raw rows, so it respects the §3.3 performance rule.
 * Returns a normalized `trueranker.*` bag; catches its own errors (§7).
 */
final class TrueRankerConnector implements DataSourceConnector, ListsConnectableResources, ProvidesSetupGuide
{
    use DescribesApiErrors;
    use ParsesValues;

    private const API_BASE = 'https://app.trueranker.com/data';

    public function key(): string
    {
        return DataSourceType::TrueRanker->value;
    }

    public function label(): string
    {
        return DataSourceType::TrueRanker->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('key', 'API Key', ConfigFieldType::Password, secret: true, help: 'Tu API Key de TrueRanker (cuenta → API and Developers, o el menú del plugin de WordPress). Requiere plan Agency o superior.'),
            new ConfigField('project', 'ID del proyecto', ConfigFieldType::Number, help: 'El ID numérico del proyecto en TrueRanker (lo ves en la URL del proyecto, o en la lista de proyectos de la API).'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('trueranker.keywords_tracked', 'Keywords monitorizadas', MetricType::Scalar, 'count'),
            new MetricDefinition('trueranker.avg_position', 'Posición media', MetricType::Scalar, 'position'),
            new MetricDefinition('trueranker.top3', 'Keywords en top 3', MetricType::Scalar, 'count'),
            new MetricDefinition('trueranker.top10', 'Keywords en top 10', MetricType::Scalar, 'count'),
            new MetricDefinition('trueranker.top100', 'Keywords en top 100', MetricType::Scalar, 'count'),
            new MetricDefinition('trueranker.improved', 'Keywords que subieron', MetricType::Scalar, 'count'),
            new MetricDefinition('trueranker.declined', 'Keywords que bajaron', MetricType::Scalar, 'count'),
            new MetricDefinition('trueranker.total_volume', 'Volumen de búsqueda total', MetricType::Scalar, 'count'),
            new MetricDefinition('trueranker.avg_position_by_date', 'Posición media por día', MetricType::Series, 'position'),
            new MetricDefinition('trueranker.rank_distribution', 'Distribución de posiciones', MetricType::Table, dimensions: ['bucket']),
            new MetricDefinition('trueranker.top_keywords', 'Keywords destacadas', MetricType::Table, dimensions: ['keyword']),
        );
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'TrueRanker es una plataforma de seguimiento de keywords. El conector lee, por HTTPS, las posiciones de las keywords de un proyecto y calcula las KPIs de SEO del informe. La API requiere el plan Agency o superior.',
            [
                'En TrueRanker, entra en tu cuenta → «API and Developers» y copia tu API Key (si usas el plugin de WordPress, está en el menú del plugin).',
                'Abre el proyecto que quieres reportar y copia su ID numérico (aparece en la URL del proyecto).',
                'Aquí pega la API Key en «API Key» y el ID en «ID del proyecto».',
                'Guarda y pulsa «Probar conexión»: si la clave es válida, el estado pasará a «ok».',
            ],
            'https://trueranker.com/docs/trueranker-api-documentation/',
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        $apiKey = $this->apiKey($source);

        if ($apiKey === '') {
            return ConnectionResult::failure('Falta la API Key de TrueRanker.');
        }

        try {
            $response = $this->projectList($apiKey);
        } catch (Throwable $e) {
            return ConnectionResult::failure('No se pudo contactar TrueRanker: '.$e->getMessage());
        }

        if ($response->failed()) {
            return ConnectionResult::failure('TrueRanker respondió HTTP '.$response->status().'.');
        }

        $json = $this->arrayOf($response->json());

        if (($json['ok'] ?? null) !== true) {
            return ConnectionResult::failure($this->explain($response, $json, $apiKey));
        }

        return ConnectionResult::success('TrueRanker conectado.');
    }

    /**
     * The documented projects endpoint. Needs only the key, so it doubles as the connection
     * test (key + plan) and as the source of the project picker.
     */
    private function projectList(string $apiKey): Response
    {
        return $this->client()->get(self::API_BASE.'/project/list', ['key' => $apiKey]);
    }

    /**
     * Say why a non-`ok` response isn't usable, in TrueRanker's own words where it gave any.
     *
     * Never concludes "the key is invalid": that was an inference with nothing behind it,
     * and it sent the operator off to regenerate a key that was fine. The plan note is from
     * the official docs — API access starts at the Agency plan — so a plan refusal points at
     * the actual thing to change.
     *
     * @param  array<array-key, mixed>  $json
     */
    private function explain(Response $response, array $json, string $apiKey): string
    {
        $detail = $this->providerError($json);

        if ($detail === '') {
            return 'TrueRanker respondió HTTP '.$response->status().': '.$this->bodyExcerpt($response, $apiKey);
        }

        $message = 'TrueRanker: '.$detail;

        if (str_contains(mb_strtolower($detail), 'plan')) {
            $message .= ' (la API de TrueRanker requiere el plan Agency o superior; la clave puede existir sin que el plan la habilite).';
        }

        return $message;
    }

    /**
     * The account's projects, so the client picks one instead of hunting for a numeric id.
     * Documented shape: `data.projects[] = {id, project_name, domain, num_keywords}`.
     */
    public function connectableResources(DataSource $source): ConnectableResources
    {
        $apiKey = $this->apiKey($source);

        if ($apiKey === '') {
            throw DiscoveryFailed::because('Falta la API Key de TrueRanker.');
        }

        $response = $this->projectList($apiKey);

        if ($response->failed()) {
            throw $this->discoveryFailed('TrueRanker', $response);
        }

        $json = $this->arrayOf($response->json());

        if (($json['ok'] ?? null) !== true) {
            throw DiscoveryFailed::because($this->explain($response, $json, $apiKey));
        }

        $options = [];

        foreach ($this->listOf(Arr::get($json, 'data.projects')) as $project) {
            $id = $this->toStr(Arr::get($project, 'id'));

            if ($id === '') {
                continue;
            }

            $name = $this->toStr(Arr::get($project, 'project_name'));
            $domain = $this->toStr(Arr::get($project, 'domain'));
            $label = trim($name !== '' ? $name : $domain);

            $options[] = ['value' => $id, 'label' => $label === '' ? $id : "{$label} ({$id})"];
        }

        return new ConnectableResources(
            'project',
            'el proyecto de TrueRanker',
            $options,
            'La API respondió correctamente pero esta cuenta no tiene ningún proyecto.',
        );
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        $apiKey = $this->apiKey($source);
        $project = $this->project($source);

        if ($apiKey === '' || $project === '') {
            return MetricSet::failed('TrueRanker: falta la API Key o el ID del proyecto.');
        }

        try {
            $response = $this->client()->get(self::API_BASE.'/project/keyword/list', [
                'key' => $apiKey,
                'project' => $project,
                'start' => $period->start->format('Ymd'),
                'end' => $period->end->format('Ymd'),
            ]);
        } catch (Throwable $e) {
            return MetricSet::failed('TrueRanker: error de petición: '.$e->getMessage());
        }

        if ($response->failed()) {
            return MetricSet::failed('TrueRanker: HTTP '.$response->status());
        }

        $json = $this->arrayOf($response->json());

        if (($json['ok'] ?? null) !== true) {
            return MetricSet::failed($this->explain($response, $json, $apiKey));
        }

        $keywords = $this->listOf(Arr::get($json, 'data.keywords'));
        $metrics = $this->computeMetrics($keywords);

        if ($requestedMetrics !== []) {
            $metrics = array_intersect_key($metrics, array_flip($requestedMetrics));
        }

        return MetricSet::ok($metrics);
    }

    /**
     * Aggregate the tracked keywords into the normalized metric bag. Each keyword carries a
     * `rank` map (date → {rank, url}); the latest dated rank is its current position and the
     * earliest is the start-of-period one, which drives improved/declined.
     *
     * @param  list<array<array-key, mixed>>  $keywords
     * @return array<string, mixed>
     */
    private function computeMetrics(array $keywords): array
    {
        $tracked = 0;
        $totalVolume = 0;
        $rankedCount = 0;
        $positionSum = 0;
        $top3 = 0;
        $top10 = 0;
        $top100 = 0;
        $improved = 0;
        $declined = 0;

        /** @var array<string, array{sum: int, count: int}> $byDate */
        $byDate = [];
        /** @var list<array{keyword: string, position: int|null, volume: int, country: string}> $rows */
        $rows = [];
        $distribution = ['Top 3' => 0, '4–10' => 0, '11–50' => 0, '51–100' => 0, 'Sin posición' => 0];

        foreach ($keywords as $keyword) {
            $tracked++;
            $volume = $this->toInt(Arr::get($keyword, 'volume'));
            $totalVolume += $volume;

            $series = $this->rankSeries(Arr::get($keyword, 'rank'));
            $latest = $series === [] ? null : $series[count($series) - 1]['rank'];
            $start = $series === [] ? null : $series[0]['rank'];

            if ($latest !== null) {
                $rankedCount++;
                $positionSum += $latest;
                if ($latest <= 3) {
                    $top3++;
                }
                if ($latest <= 10) {
                    $top10++;
                }
                $top100++;
            }

            if ($latest !== null && $start !== null) {
                if ($latest < $start) {
                    $improved++;
                } elseif ($latest > $start) {
                    $declined++;
                }
            }

            foreach ($series as $point) {
                $date = $point['date'];
                $byDate[$date] ??= ['sum' => 0, 'count' => 0];
                $byDate[$date]['sum'] += $point['rank'];
                $byDate[$date]['count']++;
            }

            $distribution[$this->bucket($latest)]++;

            $location = $this->toStr(Arr::get($keyword, 'location'));
            $rows[] = [
                'keyword' => $this->toStr(Arr::get($keyword, 'keyword')),
                'position' => $latest,
                'volume' => $volume,
                'country' => $location !== '' ? $location : $this->toStr(Arr::get($keyword, 'country')),
            ];
        }

        ksort($byDate);

        return [
            'trueranker.keywords_tracked' => $tracked,
            'trueranker.avg_position' => $rankedCount > 0 ? round($positionSum / $rankedCount, 1) : null,
            'trueranker.top3' => $top3,
            'trueranker.top10' => $top10,
            'trueranker.top100' => $top100,
            'trueranker.improved' => $improved,
            'trueranker.declined' => $declined,
            'trueranker.total_volume' => $totalVolume,
            'trueranker.avg_position_by_date' => array_map(
                static fn (string $date, array $agg): array => ['date' => $date, 'value' => round($agg['sum'] / max(1, $agg['count']), 1)],
                array_keys($byDate),
                array_values($byDate),
            ),
            'trueranker.rank_distribution' => array_map(
                static fn (string $label, int $value): array => ['label' => $label, 'value' => $value],
                array_keys($distribution),
                array_values($distribution),
            ),
            'trueranker.top_keywords' => $this->topKeywords($rows),
        ];
    }

    /**
     * The keyword's dated ranks as an ascending series of {date, rank}, keeping only real
     * positions (1–100); a missing/0/out-of-top-100 value means "not ranking" and is dropped.
     *
     * @return list<array{date: string, rank: int}>
     */
    private function rankSeries(mixed $rankMap): array
    {
        if (! is_array($rankMap)) {
            return [];
        }

        $series = [];
        foreach ($rankMap as $date => $entry) {
            if (! is_string($date)) {
                continue;
            }
            $rank = is_array($entry) ? $this->toInt(Arr::get($entry, 'rank')) : $this->toInt($entry);
            if ($rank >= 1 && $rank <= 100) {
                $series[$date] = $rank;
            }
        }

        ksort($series);

        return array_map(
            static fn (string $date, int $rank): array => ['date' => $date, 'rank' => $rank],
            array_keys($series),
            array_values($series),
        );
    }

    private function bucket(?int $position): string
    {
        if ($position === null) {
            return 'Sin posición';
        }
        if ($position <= 3) {
            return 'Top 3';
        }
        if ($position <= 10) {
            return '4–10';
        }
        if ($position <= 50) {
            return '11–50';
        }

        return '51–100';
    }

    /**
     * Top keywords by search volume (the ones that matter most), with their current position.
     *
     * @param  list<array{keyword: string, position: int|null, volume: int, country: string}>  $rows
     * @return list<array<string, string>>
     */
    private function topKeywords(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => $b['volume'] <=> $a['volume']);

        return array_map(static fn (array $row): array => [
            'Keyword' => $row['keyword'] !== '' ? $row['keyword'] : '—',
            'Posición' => $row['position'] !== null ? (string) $row['position'] : '+100',
            'Volumen' => (string) $row['volume'],
            'País' => $row['country'] !== '' ? $row['country'] : '—',
        ], array_slice($rows, 0, 15));
    }

    private function apiKey(DataSource $source): string
    {
        return $this->toStr(Arr::get($source->credentials ?? [], 'key'));
    }

    private function project(DataSource $source): string
    {
        $value = Arr::get($source->config ?? [], 'project');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->timeout(30);
    }

    /**
     * A short, safe excerpt of a response body, for when we can't interpret it. The API key
     * is redacted in case the endpoint echoes it back: a diagnostic must never turn into a
     * credential leak on screen (§6).
     */
    private function bodyExcerpt(Response $response, string $apiKey): string
    {
        $body = trim($response->body());

        if ($body === '') {
            return '(respuesta vacía)';
        }

        // A web page instead of JSON means we asked for a URL the API doesn't serve — worth
        // naming outright, since dumping markup tells the reader nothing.
        if (Str::startsWith($body, ['<!DOCTYPE', '<!doctype', '<html', '<HTML'])) {
            return 'devolvió una página web, no JSON (la URL no corresponde a la API).';
        }

        if ($apiKey !== '') {
            $body = str_replace($apiKey, '***', $body);
        }

        return Str::limit($body, 200);
    }
}
