<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Insights;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class GetTrends extends ReadTool
{
    public function name(): string
    {
        return 'get_trends';
    }

    public function title(): string
    {
        return 'Tendencias de la cartera';
    }

    public function description(): string
    {
        return 'Cómo evolucionan las métricas clave de todos los clientes de la agencia a lo largo de los meses.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        return ToolResult::data(['tendencias' => $this->fetch($context, 'trends')]);
    }
}
