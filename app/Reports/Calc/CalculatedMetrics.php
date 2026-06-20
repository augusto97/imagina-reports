<?php

declare(strict_types=1);

namespace App\Reports\Calc;

use Throwable;

/**
 * Computes a report's calculated metrics from its formulas against a period's metric
 * bags (CLAUDE.md §10.1). Output is a `calc` bag keyed `calc.<key>` so blocks bind to
 * them exactly like a connector metric (`{source: 'calc', metric: '<key>'}`). Invalid
 * formulas (bad syntax, unknown metric, /0) are skipped — never fatal.
 *
 * @phpstan-type MetricBags array<string, array<array-key, mixed>>
 */
final readonly class CalculatedMetrics
{
    public function __construct(private FormulaEvaluator $evaluator) {}

    /**
     * @param  array<int, array<array-key, mixed>>  $definitions  each {key, formula, ...}
     * @param  MetricBags  $bags
     * @return array<string, int|float> calc.<key> → value
     */
    public function compute(array $definitions, array $bags): array
    {
        $flat = $this->flatten($bags);
        $result = [];

        foreach ($definitions as $definition) {
            $key = $definition['key'] ?? null;
            $formula = $definition['formula'] ?? null;

            if (! is_string($key) || $key === '' || ! is_string($formula) || $formula === '') {
                continue;
            }

            try {
                $result["calc.{$key}"] = $this->evaluator->evaluate($formula, $flat);
            } catch (Throwable) {
                // Skip invalid/incomputable metrics — the bound block is gracefully hidden.
            }
        }

        return $result;
    }

    /**
     * Flatten the per-source bags into one metric-name → number map for the evaluator.
     *
     * @param  MetricBags  $bags
     * @return array<string, int|float>
     */
    private function flatten(array $bags): array
    {
        $flat = [];

        foreach ($bags as $metrics) {
            foreach ($metrics as $name => $value) {
                if (is_string($name) && is_numeric($value)) {
                    $flat[$name] = $value + 0;
                }
            }
        }

        return $flat;
    }
}
