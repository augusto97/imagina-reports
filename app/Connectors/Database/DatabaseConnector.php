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
use App\Support\Http\SsrfGuard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
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
            new ConfigField('metrics', 'Metrics (JSON)', ConfigFieldType::Json, help: 'JSON con tus métricas. Solo consultas SELECT/WITH (agregadas en origen): [{"key","label","type":"scalar|series|table","sql":"SELECT …"}]. Usa :start y :end en el WHERE (p. ej. «WHERE created_at BETWEEN :start AND :end») para que cada reporte cuente solo su periodo; sin ellos la consulta devuelve lo mismo en todos los meses.'),
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
                description: 'Métrica propia de esta conexión: sale de una consulta a la base de datos configurada por la agencia.',
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
                $rows = $connection->select($metric['sql'], $this->periodBindings($metric['sql'], $period));
                $value = $this->shape($metric['type'], $rows);
                if ($value === null) {
                    // No row at all: nothing measured, so the block hides instead of showing 0.
                    $errors[] = "{$metric['key']}: la consulta no devolvió ninguna fila.";

                    continue;
                }
                $metrics["database.{$metric['key']}"] = $value;
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

        $host = $this->toStr(Arr::get($config, 'host'));

        // SSRF/port-scan guard: this connector opens a real DB socket to an agency-chosen
        // host, bypassing the outbound-HTTP guard — so block private/reserved targets here.
        if (SsrfGuard::isBlockedHost($host)) {
            throw new RuntimeException('The database host is not allowed.');
        }

        return [
            'driver' => $this->toStr(Arr::get($config, 'driver')) ?: 'mysql',
            'host' => $host,
            'port' => $this->toStr(Arr::get($config, 'port')),
            'database' => $this->toStr(Arr::get($config, 'database')),
            'username' => $this->toStr(Arr::get($config, 'username')),
            'password' => $this->toStr(Arr::get($credentials, 'password')),
        ];
    }

    /**
     * Whether the configured SQL is a single read-only statement. Starting with SELECT/WITH
     * is not enough on its own: a stacked statement ("select 1; delete from x") also starts
     * that way. PDO prepared statements already reject multi-statement on MySQL, but this is
     * the connector's own guard and must not depend on the driver — so we additionally reject
     * statement separators (outside string literals) and any write/DDL keyword.
     */
    private function isReadOnly(string $sql): bool
    {
        if (preg_match('/^\s*(select|with)\b/i', $sql) !== 1) {
            return false;
        }

        // Strip string literals and comments so a ';' or keyword inside them isn't a false
        // positive (e.g. WHERE name = 'a; drop').
        $stripped = preg_replace(
            ['/\'(?:\'\'|\\\\.|[^\'\\\\])*\'/s', '/"(?:""|\\\\.|[^"\\\\])*"/s', '/`[^`]*`/s', '/--[^\n]*/', '/#[^\n]*/', '/\/\*.*?\*\//s'],
            ' ',
            $sql,
        ) ?? '';

        // A trailing ';' is fine; a separator with anything after it means a second statement.
        if (preg_match('/;\s*\S/', $stripped) === 1) {
            return false;
        }

        return preg_match(
            '/\b(insert|update|delete|drop|truncate|alter|create|replace|grant|revoke|call|execute|merge|rename|load_file|into\s+outfile|into\s+dumpfile)\b/i',
            $stripped,
        ) !== 1;
    }

    /**
     * The report period for a query that asks for it with `:start` / `:end` (bound, never
     * pasted into the SQL). Only the names the query uses are passed: PDO rejects extras.
     *
     * @return array<string, string>
     */
    private function periodBindings(string $sql, Period $period): array
    {
        $stripped = preg_replace(['/\'(?:\'\'|\\\\.|[^\'\\\\])*\'/s', '/"(?:""|\\\\.|[^"\\\\])*"/s'], ' ', $sql) ?? $sql;
        $bindings = [];
        if (preg_match('/:start\b/', $stripped) === 1) {
            $bindings['start'] = $period->start->startOfDay()->format('Y-m-d H:i:s');
        }
        if (preg_match('/:end\b/', $stripped) === 1) {
            $bindings['end'] = $period->end->endOfDay()->format('Y-m-d H:i:s');
        }

        return $bindings;
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
    private function scalar(array $rows): int|float|null
    {
        $first = $rows[0] ?? null;

        if ($first === null) {
            return null;
        }

        $values = array_values((array) $first);
        $value = $values[0] ?? null;
        if ($value !== null && ! is_numeric($value)) {
            throw new RuntimeException('la consulta devolvió un valor que no es un número.');
        }

        // NULL is what SUM() gives over no matching rows: nothing happened, so 0.
        return $this->toNumber($value ?? 0);
    }
}
