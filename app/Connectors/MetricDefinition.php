<?php

declare(strict_types=1);

namespace App\Connectors;

/**
 * One entry in a connector's MetricCatalog (CLAUDE.md §7/§10.1): a metric the
 * source CAN provide. The editor's binding picker and the AI builder choose from
 * these — nothing about which metrics a report shows is hardcoded.
 */
final readonly class MetricDefinition
{
    /**
     * @param  string  $key  Fully-qualified metric key, e.g. "ga4.sessions".
     * @param  list<string>  $dimensions  Optional dimensions the metric can be broken down by.
     */
    public function __construct(
        public string $key,
        public string $label,
        public MetricType $type,
        public ?string $unit = null,
        public array $dimensions = [],
        public ?string $description = null,
    ) {}
}
