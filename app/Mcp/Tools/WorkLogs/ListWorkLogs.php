<?php

declare(strict_types=1);

namespace App\Mcp\Tools\WorkLogs;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class ListWorkLogs extends ReadTool
{
    public function name(): string
    {
        return 'list_work_logs';
    }

    public function title(): string
    {
        return 'Trabajo realizado';
    }

    public function description(): string
    {
        return 'Las tareas anotadas como «trabajo realizado» en un sitio (lo que el cliente ve en el bloque «Qué hicimos este mes»). '
            .'Filtra por fechas con «from» y «to».';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Id del sitio.'),
            'from' => self::date('Desde (opcional).'),
            'to' => self::date('Hasta (opcional).'),
        ];
    }

    protected function required(): array
    {
        return ['site_id'];
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $siteId = $arguments->int('site_id');
        $query = array_filter(['from' => $arguments->optionalDate('from'), 'to' => $arguments->optionalDate('to')], static fn (?string $value): bool => $value !== null);

        $logs = array_map(
            static fn (array $log): array => self::pick($log, ['id', 'performed_at', 'description', 'status', 'minutes', 'category']),
            $this->fetchList($context, "sites/{$siteId}/work-logs", $query),
        );

        $minutes = array_sum(array_map(static fn (array $log): int => is_int($log['minutes']) ? $log['minutes'] : 0, $logs));

        return ToolResult::data(['tareas' => $logs, 'total' => count($logs), 'horas' => round($minutes / 60, 1)]);
    }
}
