<?php

declare(strict_types=1);

namespace App\Connectors\TrueRanker;

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
 * TrueRanker connector (CLAUDE.md §9 — keyword rank tracking). Reads a project's tracked
 * keywords and their ranking history for the period from the TrueRanker API
 * (`https://app.trueranker.com/data`, auth via the `key` query param) and computes the SEO
 * KPIs the report shows: average position, keywords in top 3/10/100, improved/declined, and
 * a top-keywords table. TrueRanker exposes no server-side aggregate endpoint, so we compute
 * the aggregates in-app — but the input is BOUNDED by the project's tracked keyword set (the
 * project caps it), not millions of raw rows, so it respects the §3.3 performance rule.
 * Returns a normalized `trueranker.*` bag; catches its own errors (§7).
 */
final class TrueRankerConnector implements DataSourceConnector, ProvidesSetupGuide
{
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
            $response = $this->client()->get(self::API_BASE.'/projects/list', ['key' => $apiKey]);
        } catch (Throwable $e) {
            return ConnectionResult::failure('No se pudo contactar TrueRanker: '.$e->getMessage());
        }

        if ($response->failed()) {
            return ConnectionResult::failure('TrueRanker respondió HTTP '.$response->status().'.');
        }

        $json = $this->arrayOf($response->json());

        if (($json['ok'] ?? null) !== true) {
            $error = $this->toStr(Arr::get($json, 'error'));

            return ConnectionResult::failure($error !== '' ? 'TrueRanker: '.$error : 'API Key de TrueRanker inválida.');
        }

        return ConnectionResult::success('TrueRanker conectado.');
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        $apiKey = $this->apiKey($source);
        $project = $this->project($source);

        if ($apiKey === '' || $project === '') {
            return MetricSet::failed('TrueRanker: falta la API Key o el ID del proyecto.');
        }

        try {
            $response = $this->client()->get(self::API_BASE.'/project/keywords', [
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
            $error = $this->toStr(Arr::get($json, 'error'));

            return MetricSet::failed($error !== '' ? 'TrueRanker: '.$error : 'TrueRanker: respuesta inválida.');
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
}
