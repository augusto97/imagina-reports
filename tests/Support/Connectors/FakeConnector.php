<?php

declare(strict_types=1);

namespace Tests\Support\Connectors;

use App\Connectors\ConfigField;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Models\DataSource;

/**
 * Minimal in-memory connector used to exercise the registry and contracts in tests
 * without touching any external API.
 */
final class FakeConnector implements DataSourceConnector
{
    public function __construct(
        private readonly string $key = 'fake',
        private readonly string $label = 'Fake',
        private readonly ?MetricSet $result = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('token', 'API token', secret: true),
        ];
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        return ConnectionResult::success();
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('fake.visits', 'Visits', MetricType::Scalar, unit: 'count'),
        );
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        return $this->result ?? MetricSet::ok(['fake.visits' => 42]);
    }
}
