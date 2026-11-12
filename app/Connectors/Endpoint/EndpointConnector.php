<?php

declare(strict_types=1);

namespace App\Connectors\Endpoint;

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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Endpoint / CSV connector (CLAUDE.md §9/§13). Reads an external URL that returns
 * **already-aggregated** JSON or CSV and maps it to metrics via operator-defined
 * config. The remote endpoint is expected to do the aggregation (§3.3 hard rule) —
 * this connector only shapes the small summarized result. Metrics are config-defined,
 * so the catalog is dynamic.
 *
 * @phpstan-type MetricDef array{key: string, label: string, type: string, path: string, label_field: string, value_field: string}
 */
final class EndpointConnector implements DataSourceConnector
{
    use ParsesValues;

    public function key(): string
    {
        return DataSourceType::Endpoint->value;
    }

    public function label(): string
    {
        return DataSourceType::Endpoint->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('url', 'URL', ConfigFieldType::Url, help: 'URL que devuelve los datos YA agregados (JSON o CSV). Si la URL lleva {start} y {end}, se cambian por las fechas del periodo (AAAA-MM-DD) para que cada reporte pida solo su mes.'),
            new ConfigField('format', 'Format', ConfigFieldType::Text, required: false, help: 'json (por defecto) o csv.'),
            new ConfigField('token', 'Bearer token', ConfigFieldType::Password, required: false, secret: true, help: 'Token Bearer opcional para autenticar la petición.'),
            new ConfigField('metrics', 'Metrics (JSON)', ConfigFieldType::Json, help: 'JSON con el mapeo: [{"key","label","type":"scalar|series|table","path","label_field","value_field"}].'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        $definitions = [];

        foreach ($this->metricDefs($source) as $metric) {
            $definitions[] = new MetricDefinition(
                "endpoint.{$metric['key']}",
                $metric['label'],
                MetricType::tryFrom($metric['type']) ?? MetricType::Scalar,
                description: 'Métrica propia de esta conexión: sale de la URL o archivo de datos configurado por la agencia.',
            );
        }

        return new MetricCatalog(...$definitions);
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            $response = $this->request($source);

            return $response->successful()
                ? ConnectionResult::success('Endpoint reachable.')
                : ConnectionResult::failure('Endpoint responded with HTTP '.$response->status());
        } catch (Throwable $e) {
            return ConnectionResult::failure('Could not reach endpoint: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        try {
            $response = $this->request($source, $period);
        } catch (Throwable $e) {
            return MetricSet::failed('Endpoint request error: '.$e->getMessage());
        }

        if ($response->failed()) {
            return MetricSet::failed('Endpoint request failed: HTTP '.$response->status());
        }

        $isCsv = $this->format($source) === 'csv';
        $rows = $isCsv ? $this->parseCsv($response->body()) : [];
        $json = $isCsv ? [] : $this->arrayOf($response->json());

        $metrics = [];
        $errors = [];

        foreach ($this->metricDefs($source) as $metric) {
            $value = $isCsv ? $this->shapeCsv($metric, $rows) : $this->shapeJson($metric, $json);
            if ($value === null) {
                // Missing or not a number: «not measured» (the block hides), never a silent 0.
                $errors[] = "{$metric['key']}: no se encontró un número en la respuesta.";

                continue;
            }
            $metrics["endpoint.{$metric['key']}"] = $value;
        }

        if ($metrics === [] && $errors !== []) {
            return MetricSet::failed(implode('; ', $errors));
        }

        return $errors === [] ? MetricSet::ok($metrics) : MetricSet::partial($metrics, implode('; ', $errors));
    }

    /**
     * @return list<MetricDef>
     */
    private function metricDefs(DataSource $source): array
    {
        $defs = [];

        foreach ($this->listOf(Arr::get($source->config ?? [], 'metrics')) as $metric) {
            $key = $this->toStr(Arr::get($metric, 'key'));
            if ($key === '') {
                continue;
            }

            $defs[] = [
                'key' => $key,
                'label' => $this->toStr(Arr::get($metric, 'label', $key)),
                'type' => $this->toStr(Arr::get($metric, 'type', 'scalar')),
                'path' => $this->toStr(Arr::get($metric, 'path')),
                'label_field' => $this->toStr(Arr::get($metric, 'label_field')),
                'value_field' => $this->toStr(Arr::get($metric, 'value_field')),
            ];
        }

        return $defs;
    }

    private function request(DataSource $source, ?Period $period = null): Response
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];
        $token = $this->toStr(Arr::get($credentials, 'token'));

        $request = Http::timeout(20);

        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $url = $this->toStr(Arr::get($config, 'url'));
        $period ??= new Period(now()->startOfMonth(), now()->endOfMonth());

        return $request->get(strtr($url, [
            '{start}' => rawurlencode($period->start->toDateString()),
            '{end}' => rawurlencode($period->end->toDateString()),
        ]));
    }

    private function format(DataSource $source): string
    {
        return strtolower($this->toStr(Arr::get($source->config ?? [], 'format'))) === 'csv' ? 'csv' : 'json';
    }

    /**
     * @param  MetricDef  $metric
     * @param  array<array-key, mixed>  $json
     */
    private function shapeJson(array $metric, array $json): mixed
    {
        $value = Arr::get($json, $metric['path']);

        return match ($metric['type']) {
            'series' => $this->series($this->listOf($value), $metric['label_field'], $metric['value_field']),
            'table' => array_map(fn (mixed $row): array => $this->arrayOf($row), $this->listOf($value)),
            default => is_numeric($value) ? $this->toNumber($value) : null,
        };
    }

    /**
     * @param  MetricDef  $metric
     * @param  list<array<string, mixed>>  $rows
     */
    private function shapeCsv(array $metric, array $rows): mixed
    {
        return match ($metric['type']) {
            'series' => $this->series($rows, $metric['label_field'], $metric['value_field']),
            'table' => $rows,
            default => is_numeric($cell = $this->cell($rows[0] ?? [], $metric['value_field'])) ? $this->toNumber($cell) : null,
        };
    }

    /**
     * @param  list<array<array-key, mixed>>  $items
     * @return list<array{label: string, value: int|float}>
     */
    private function series(array $items, string $labelField, string $valueField): array
    {
        return array_map(function (array $item) use ($labelField, $valueField): array {
            $values = array_values($item);

            return [
                'label' => $this->toStr($labelField !== '' ? Arr::get($item, $labelField) : ($values[0] ?? null)),
                'value' => $this->toNumber($valueField !== '' ? Arr::get($item, $valueField) : ($values[1] ?? null)),
            ];
        }, $items);
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function cell(array $row, string $field): mixed
    {
        if ($field !== '') {
            return Arr::get($row, $field);
        }

        return array_values($row)[0] ?? null;
    }

    /**
     * Parse CSV text into a list of associative rows keyed by the header line.
     *
     * @return list<array<string, mixed>>
     */
    private function parseCsv(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body));

        if ($lines === false || $lines === [] || $lines === ['']) {
            return [];
        }

        $header = (string) array_shift($lines);
        // Spreadsheets in Spanish save with «;» (the comma is the decimal mark), others with
        // «,» or a tab: use whichever the header line is split by.
        $counts = ['semicolon' => substr_count($header, ';'), 'tab' => substr_count($header, "\t"), 'comma' => substr_count($header, ',')];
        arsort($counts);
        $delimiter = match (array_key_first($counts)) {
            'semicolon' => ';',
            'tab' => "\t",
            default => ',',
        };
        if (max($counts) === 0) {
            $delimiter = ',';
        }

        $headers = array_map($this->toStr(...), str_getcsv($header, $delimiter, '"', '\\'));
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line, $delimiter, '"', '\\');
            $row = [];

            foreach ($headers as $index => $name) {
                $cell = $cells[$index] ?? null;
                // «1.234,56» from a «;» file is 1234.56.
                if ($delimiter === ';' && is_string($cell) && preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$|^-?\d+,\d+$/', trim($cell)) === 1) {
                    $cell = str_replace(['.', ','], ['', '.'], trim($cell));
                }
                $row[$name] = $cell;
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
