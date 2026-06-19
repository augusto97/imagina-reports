<?php

declare(strict_types=1);

namespace App\Connectors;

/**
 * The immutable list of metrics a connector can provide for a given data source
 * (CLAUDE.md §7/§10.1). Drives the editor binding picker and the AI builder; the
 * AI's bindings are validated against a real catalog so it cannot invent metrics.
 */
final readonly class MetricCatalog
{
    /**
     * @var array<string, MetricDefinition>
     */
    private array $metrics;

    public function __construct(MetricDefinition ...$metrics)
    {
        $keyed = [];

        foreach ($metrics as $metric) {
            $keyed[$metric->key] = $metric;
        }

        $this->metrics = $keyed;
    }

    /**
     * Return a new catalog with the given definition added (or replaced by key).
     */
    public function with(MetricDefinition $metric): self
    {
        return new self(...array_values([...$this->metrics, $metric->key => $metric]));
    }

    public function has(string $key): bool
    {
        return isset($this->metrics[$key]);
    }

    public function get(string $key): ?MetricDefinition
    {
        return $this->metrics[$key] ?? null;
    }

    /**
     * @return list<MetricDefinition>
     */
    public function all(): array
    {
        return array_values($this->metrics);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->metrics);
    }

    public function isEmpty(): bool
    {
        return $this->metrics === [];
    }

    public function merge(self $other): self
    {
        return new self(...array_values([...$this->metrics, ...$other->metrics]));
    }
}
