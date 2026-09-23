<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Insights;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class GetUpsellOpportunities extends ReadTool
{
    public function name(): string
    {
        return 'get_upsell_opportunities';
    }

    public function title(): string
    {
        return 'Oportunidades de venta';
    }

    public function description(): string
    {
        return 'Clientes a los que conviene ofrecer un servicio adicional según sus datos (p. ej. mucho tráfico sin SEO, ataques sin firewall), con el motivo.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        return ToolResult::data(['oportunidades' => $this->fetch($context, 'upsell')]);
    }
}
