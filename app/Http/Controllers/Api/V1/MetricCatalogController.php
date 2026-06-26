<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\ConnectorRegistry;
use App\Connectors\MetricDefinition;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * The combined metric catalog of a site's configured data sources (CLAUDE.md
 * §11.3) — the "free metrics" the editor's binding picker chooses from. The
 * binding stores {source, metric}; here we expose both the short `metric` and
 * the full catalog `key` ("{source}.{metric}").
 */
final class MetricCatalogController extends Controller
{
    public function show(Site $site, ConnectorRegistry $registry): JsonResponse
    {
        $catalog = [];

        foreach ($site->dataSources()->get() as $source) {
            if (! $registry->has($source->type->value)) {
                continue;
            }

            foreach ($registry->for($source)->metricCatalog($source)->all() as $definition) {
                $catalog[] = $this->present($source->type->value, $definition);
            }
        }

        // Work-log metrics (CLAUDE.md §11.5) — not a connector, but bindable like one
        // so the editor can place "hours invested", "tasks", the category breakdown
        // and "hours vs plan" anywhere.
        foreach (self::WORKLOG_METRICS as [$metric, $label, $type, $unit]) {
            $catalog[] = [
                'source' => 'worklog',
                'metric' => $metric,
                'key' => "worklog.{$metric}",
                'label' => $label,
                'type' => $type,
                'unit' => $unit,
                'dimensions' => [],
            ];
        }

        return response()->json($catalog);
    }

    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string|null}>
     */
    private const WORKLOG_METRICS = [
        ['hours', 'Horas invertidas', 'number', 'h'],
        ['tasks', 'Tareas realizadas', 'number', null],
        ['by_category', 'Horas por categoría', 'table', null],
        ['hours_vs_plan', 'Horas vs plan', 'number', 'h'],
    ];

    /**
     * @return array<string, mixed>
     */
    private function present(string $source, MetricDefinition $definition): array
    {
        return [
            'source' => $source,
            'metric' => (string) Str::of($definition->key)->after("{$source}."),
            'key' => $definition->key,
            'label' => $definition->label,
            'type' => $definition->type->value,
            'unit' => $definition->unit,
            'dimensions' => $definition->dimensions,
            // Dataset modeling metadata (empty for plain metrics) — drives the editor's
            // measure/breakdown/filter pickers (CLAUDE.md §10 dashboards).
            'measures' => $definition->measures,
            'dimension_labels' => $definition->dimensionLabels,
        ];
    }
}
