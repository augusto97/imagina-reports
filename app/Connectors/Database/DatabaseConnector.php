<?php

declare(strict_types=1);

namespace App\Connectors\Database;

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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Database connector (CLAUDE.md §9/§13/§3.3). Runs operator-configured aggregate
 * queries (SELECT/GROUP BY) on the CLIENT's own read-only database and stores only
 * the small summarized result. **Never pulls raw rows** — that is the one thing that
 * would break performance (hard rule §3.3). Metrics are config-defined, so the
 * catalog is dynamic. This is branded scheduled reporting, NOT a BI engine.
 *
 * @phpstan-type MetricDef array{key: string, label: string, type: string, sql: string}
 */
final class DatabaseConnector implements DataSourceConnector
{
    use ParsesValues;

    public function key(): string
    {
        return DataSourceType::Database->value;
    }

    public function label(): string
    {
        return DataSourceType::Database->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('driver', 'Driver', ConfigFieldType::Text, help: 'Motor de la base de datos: mysql, pgsql o sqlite.'),
            new ConfigField('host', 'Host', ConfigFieldType::Text, required: false, help: 'Host de la BD del cliente (déjalo vacío para sqlite).'),
            new ConfigField('port', 'Port', ConfigFieldType::Number, required: false, help: 'Puerto (3306 MySQL, 5432 Postgres). Opcional.'),
            new ConfigField('database', 'Database', ConfigFieldType::Text, help: 'Nombre de la base de datos (o ruta del archivo en sqlite).'),
            new ConfigField('username', 'Username', ConfigFieldType::Text, required: false, help: 'Usuario de SOLO LECTURA.'),
            new ConfigField('password', 'Password', ConfigFieldType::Password, required: false, secret: true, help: 'Contraseña del usuario de solo lectura.'),
            new ConfigField('metrics', 'Metrics (JSON)', ConfigFieldType::Json, help: 'JSON con tus métricas. Solo consultas SELECT/WITH (agregadas en origen): [{"key","label","type":"scalar|series|table","sql":"SELECT …"}].'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        $definitions = [];

        foreach ($this->metricDefs($source) as $metric) {
            $definitions[] = new MetricDefinition(
                "database.{$metric['key']}",
                $metric['label'],
                MetricType::tryFrom($metric['type']) ?? MetricType::Scalar,
            );
        }

        return new MetricCatalog(...$definitions);
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            DB::build($this->connectionConfig($source))->select('select 1');

            return ConnectionResult::success('Database reachable.');
        } catch (Throwable $e) {
            return ConnectionResult::failure('Could not connect: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        try {
            $connection = DB::build($this->connectionConfig($source));
        } catch (Throwable $e) {
            return MetricSet::failed('Database connection failed: '.$e->getMessage());
        }

        $metrics = [];
        $errors = [];

        foreach ($this->metricDefs($source) as $metric) {
            if (! $this->isReadOnly($metric['sql'])) {
                $errors[] = "{$metric['key']}: only SELECT/WITH queries are allowed.";

                continue;
            }

            try {
                $rows = $connection->select($metric['sql']);
                $metrics["database.{$metric['key']}"] = $this->shape($metric['type'], $rows);
            } catch (Throwable $e) {
                $errors[] = "{$metric['key']}: ".$e->getMessage();
            }
        }

        if ($metrics === [] && $errors !== []) {
            return MetricSet::failed(implode('; ', $errors));
        }

        return $errors === []
            ? MetricSet::ok($metrics)
            : MetricSet::partial($metrics, implode('; ', $errors));
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
                'sql' => $this->toStr(Arr::get($metric, 'sql')),
            ];
        }

        return $defs;
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfig(DataSource $source): array
    {
        $config = $source->config ?? [];
        $credentials = $source->credentials ?? [];

        return [
            'driver' => $this->toStr(Arr::get($config, 'driver')) ?: 'mysql',
            'host' => $this->toStr(Arr::get($config, 'host')),
            'port' => $this->toStr(Arr::get($config, 'port')),
            'database' => $this->toStr(Arr::get($config, 'database')),
            'username' => $this->toStr(Arr::get($config, 'username')),
            'password' => $this->toStr(Arr::get($credentials, 'password')),
        ];
    }

    private function isReadOnly(string $sql): bool
    {
        return preg_match('/^\s*(select|with)\b/i', $sql) === 1;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     */
    private function shape(string $type, array $rows): mixed
    {
        return match ($type) {
            'series' => array_map(function (mixed $row): array {
                $values = array_values((array) $row);

                return ['label' => $this->toStr($values[0] ?? null), 'value' => $this->toNumber($values[1] ?? null)];
            }, $rows),
            'table' => array_map(static fn (mixed $row): array => (array) $row, $rows),
            default => $this->scalar($rows),
        };
    }

    /**
     * @param  array<array-key, mixed>  $rows
     */
    private function scalar(array $rows): int|float
    {
        $first = $rows[0] ?? null;

        if ($first === null) {
            return 0;
        }

        $values = array_values((array) $first);

        return $this->toNumber($values[0] ?? 0);
    }
}
