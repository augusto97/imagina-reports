<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Insights;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class ListAnomalies extends ReadTool
{
    public function name(): string
    {
        return 'list_anomalies';
    }

    public function title(): string
    {
        return 'Anomalías detectadas';
    }

    public function description(): string
    {
        return 'Caídas o picos inusuales detectados (tráfico que baja de golpe, ataques que se disparan…), con el sitio, la métrica y el cambio. Las no reconocidas aún tienen «acknowledged_at» vacío.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        return ToolResult::data(['anomalias' => $this->fetch($context, 'anomalies')]);
    }
}
