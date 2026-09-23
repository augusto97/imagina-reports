<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Data;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class GetMetricCatalog extends ReadTool
{
    public function name(): string
    {
        return 'get_metric_catalog';
    }

    public function title(): string
    {
        return 'Métricas disponibles de un sitio';
    }

    public function description(): string
    {
        return 'Las métricas que ofrecen las fuentes conectadas de un sitio, con su clave (p. ej. «ga4.sessions»). '
            .'Usa esas claves en query_metrics. Filtra por fuente con «source» (ga4, gsc, facebook_ads…).';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Id del sitio.'),
            'source' => self::text('Solo las métricas de esta fuente (opcional).'),
        ];
    }

    protected function required(): array
    {
        return ['site_id'];
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $siteId = $arguments->int('site_id');
        $source = $arguments->optionalString('source');
        $metrics = [];

        foreach ($this->fetchList($context, "sites/{$siteId}/metric-catalog") as $entry) {
            if ($source !== null && ($entry['source'] ?? null) !== $source) {
                continue;
            }
            $metrics[] = self::pick($entry, ['key', 'label', 'type', 'unit', 'source']);
        }

        return ToolResult::data(['metricas' => $metrics, 'total' => count($metrics)]);
    }
}
