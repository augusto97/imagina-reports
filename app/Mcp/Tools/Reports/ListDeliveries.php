<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Reports;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class ListDeliveries extends ReadTool
{
    public function name(): string
    {
        return 'list_deliveries';
    }

    public function title(): string
    {
        return 'Envíos de un reporte';
    }

    public function description(): string
    {
        return 'A quién se envió un reporte, cuándo, y qué envíos fallaron (con el motivo).';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    protected function properties(): array
    {
        return ['report_id' => self::integer('Id del reporte.')];
    }

    protected function required(): array
    {
        return ['report_id'];
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $reportId = $arguments->int('report_id');

        return ToolResult::data(['envios' => array_map(
            static fn (array $delivery): array => self::pick($delivery, ['id', 'channel', 'recipient', 'status', 'sent_at', 'error']),
            $this->fetchList($context, "reports/{$reportId}/deliveries"),
        )]);
    }
}
