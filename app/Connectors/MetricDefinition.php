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
     * @param  list<string>  $dimensions  Dimensions this metric/dataset can be broken down or filtered by.
     * @param  list<array{key: string, label: string, unit: string|null}>  $measures  For Dataset metrics:
     *                                                                                the numeric columns a block can pick (the editor's "measure" dropdown). Empty otherwise.
     * @param  array<string, string>  $dimensionLabels  Human labels for the dimension keys (e.g. country → País),
     *                                                  so the editor's filter/breakdown pickers read well. Falls back to the key when absent.
     */
    public function __construct(
        public string $key,
        public string $label,
        public MetricType $type,
        public ?string $unit = null,
        public array $dimensions = [],
        public ?string $description = null,
        public array $measures = [],
        public array $dimensionLabels = [],
    ) {}
}
