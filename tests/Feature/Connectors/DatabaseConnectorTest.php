<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Database\DatabaseConnector;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConnectorTest extends TestCase
{
    private string $dbPath = '';

    protected function tearDown(): void
    {
        if ($this->dbPath !== '' && file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function source(array $config): DataSource
    {
        return DataSource::factory()->make([
            'agency_id' => 1,
            'type' => DataSourceType::Database,
            'config' => $config,
            'credentials' => [],
        ]);
    }

    private function seededSqlitePath(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ir_db_');
        $this->dbPath = $path;

        $connection = DB::build(['driver' => 'sqlite', 'database' => $path]);
        $connection->statement('CREATE TABLE orders (id integer primary key, country text, total real)');
        $connection->insert("INSERT INTO orders (country, total) VALUES ('ES', 10.5), ('ES', 4.5), ('PT', 20)");

        return $path;
    }

    public function test_it_runs_aggregate_queries_at_the_source(): void
    {
        $source = $this->source([
            'driver' => 'sqlite',
            'database' => $this->seededSqlitePath(),
            'metrics' => [
                ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'scalar', 'sql' => 'SELECT SUM(total) FROM orders'],
                ['key' => 'by_country', 'label' => 'By country', 'type' => 'series', 'sql' => 'SELECT country, SUM(total) AS total FROM orders GROUP BY country ORDER BY country'],
                ['key' => 'rows', 'label' => 'Rows', 'type' => 'table', 'sql' => 'SELECT country, total FROM orders ORDER BY country, total'],
            ],
        ]);

        $set = (new DatabaseConnector)->fetch($source, Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isOk());
        $this->assertSame(35.0, $set->get('database.revenue'));
        $this->assertSame([
            ['label' => 'ES', 'value' => 15.0],
            ['label' => 'PT', 'value' => 20.0],
        ], $set->get('database.by_country'));
        $this->assertCount(3, $set->get('database.rows'));
    }

    public function test_it_exposes_a_dynamic_catalog_from_config(): void
    {
        $source = $this->source([
            'driver' => 'sqlite',
            'database' => 'irrelevant',
            'metrics' => [
                ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'scalar', 'sql' => 'SELECT 1'],
            ],
        ]);

        $this->assertSame(['database.revenue'], (new DatabaseConnector)->metricCatalog($source)->keys());
    }

    public function test_it_rejects_non_read_only_statements(): void
    {
        $source = $this->source([
            'driver' => 'sqlite',
            'database' => $this->seededSqlitePath(),
            'metrics' => [
                ['key' => 'evil', 'label' => 'Evil', 'type' => 'scalar', 'sql' => 'DELETE FROM orders'],
            ],
        ]);

        $set = (new DatabaseConnector)->fetch($source, Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isFailed());
        $this->assertStringContainsString('only SELECT/WITH', (string) $set->error);
    }

    public function test_a_failing_query_alongside_a_good_one_yields_partial(): void
    {
        $source = $this->source([
            'driver' => 'sqlite',
            'database' => $this->seededSqlitePath(),
            'metrics' => [
                ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'scalar', 'sql' => 'SELECT SUM(total) FROM orders'],
                ['key' => 'broken', 'label' => 'Broken', 'type' => 'scalar', 'sql' => 'SELECT nope FROM missing_table'],
            ],
        ]);

        $set = (new DatabaseConnector)->fetch($source, Period::make('2026-06-01', '2026-06-30'), []);

        $this->assertTrue($set->isPartial());
        $this->assertSame(35.0, $set->get('database.revenue'));
    }
}
