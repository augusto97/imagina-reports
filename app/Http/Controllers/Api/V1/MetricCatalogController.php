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

        return response()->json($catalog);
    }

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
        ];
    }
}
