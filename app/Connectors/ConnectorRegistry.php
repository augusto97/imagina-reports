<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\Exceptions\UnknownConnectorException;
use App\Enums\DataSourceType;
use App\Models\DataSource;

/**
 * Maps a source key/type to its connector (CLAUDE.md §7). Adding a source = a new
 * connector class registered here (done in the ConnectorServiceProvider). Bound as
 * a singleton so registrations persist for the request/job lifecycle.
 */
final class ConnectorRegistry
{
    /**
     * @var array<string, DataSourceConnector>
     */
    private array $connectors = [];

    public function register(DataSourceConnector $connector): void
    {
        $this->connectors[$connector->key()] = $connector;
    }

    public function has(string $key): bool
    {
        return isset($this->connectors[$key]);
    }

    /**
     * @throws UnknownConnectorException
     */
    public function get(string $key): DataSourceConnector
    {
        return $this->connectors[$key] ?? throw UnknownConnectorException::forKey($key);
    }

    public function forType(DataSourceType $type): DataSourceConnector
    {
        return $this->get($type->value);
    }

    public function for(DataSource $source): DataSourceConnector
    {
        return $this->forType($source->type);
    }

    /**
     * @return list<DataSourceConnector>
     */
    public function all(): array
    {
        return array_values($this->connectors);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->connectors);
    }
}
