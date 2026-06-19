<?php

declare(strict_types=1);

namespace App\Connectors\Contracts;

use App\Connectors\ConfigField;
use App\Connectors\ConnectionResult;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricSet;
use App\Connectors\Period;
use App\Models\DataSource;

/**
 * One implementation per source (CLAUDE.md §7). Connectors are registered in the
 * ConnectorRegistry by their key. They must aggregate at the source (§3.3) and
 * catch their own API errors, returning a partial/failed MetricSet rather than
 * throwing — never invent metrics not declared in metricCatalog().
 */
interface DataSourceConnector
{
    /**
     * Stable machine key matching DataSourceType, e.g. "mainwp", "ga4", "gsc".
     */
    public function key(): string;

    /**
     * Human-readable name for the admin UI.
     */
    public function label(): string;

    /**
     * The fields required to configure this source — drives the admin form.
     *
     * @return list<ConfigField>
     */
    public function configSchema(): array;

    /**
     * Validate the configured credentials/config (the "Test connection" action).
     */
    public function testConnection(DataSource $source): ConnectionResult;

    /**
     * What this source CAN provide. Drives the editor binding picker and AI builder.
     */
    public function metricCatalog(DataSource $source): MetricCatalog;

    /**
     * Fetch ONLY the requested metrics, aggregated at the source, as a normalized
     * metric bag. Catches its own errors → partial/failed MetricSet.
     *
     * @param  list<string>  $requestedMetrics
     */
    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet;
}
