<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Data;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class GetSyncStatus extends ReadTool
{
    public function name(): string
    {
        return 'get_sync_status';
    }

    public function title(): string
    {
        return 'Estado de sincronización';
    }

    public function description(): string
    {
        return 'Qué fuentes de un sitio funcionan y cuáles fallan, con el motivo real que dio el proveedor, '
            .'y de qué periodos hay datos guardados. Úsala antes de generar un reporte o cuando falten datos.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    protected function properties(): array
    {
        return ['site_id' => self::integer('Id del sitio.')];
    }

    protected function required(): array
    {
        return ['site_id'];
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $siteId = $arguments->int('site_id');
        $sources = array_map(
            static fn (array $source): array => self::pick($source, ['id', 'type', 'status', 'last_synced_at', 'last_error']),
            $this->fetchList($context, "sites/{$siteId}/data-sources"),
        );

        $failing = array_values(array_filter($sources, static fn (array $source): bool => $source['status'] === 'error'));
        $periods = array_slice($this->fetchList($context, "sites/{$siteId}/snapshot-periods"), 0, 12);

        return ToolResult::data([
            'fuentes' => $sources,
            'con_error' => count($failing),
            'periodos_con_datos' => $periods,
        ]);
    }
}
