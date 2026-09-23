<?php

declare(strict_types=1);

namespace App\Mcp\Tools\WorkLogs;

use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolError;
use App\Mcp\Tools\DeleteTool;

final class ProposeDeleteWorkLog extends DeleteTool
{
    public function name(): string
    {
        return 'propose_delete_work_log';
    }

    public function title(): string
    {
        return 'Borrar una tarea anotada';
    }

    public function description(): string
    {
        return 'Propone borrar una tarea del trabajo realizado de un sitio. Irreversible: requiere confirmación con apply_proposal.';
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Id del sitio.'),
            'work_log_id' => self::integer('Id de la tarea (ver list_work_logs).'),
        ];
    }

    protected function required(): array
    {
        return ['site_id', 'work_log_id'];
    }

    protected function describe(ToolArguments $arguments, McpContext $context): array
    {
        $siteId = $arguments->int('site_id');
        $logId = $arguments->int('work_log_id');

        foreach ($this->fetchList($context, "sites/{$siteId}/work-logs") as $log) {
            if (self::intOrNull($log['id'] ?? null) === $logId) {
                return [
                    'path' => "work-logs/{$logId}",
                    'summary' => 'Borrar la tarea del '.self::str($log['performed_at'] ?? '').': «'.self::str($log['description'] ?? '').'».',
                ];
            }
        }

        throw new ToolError('Esa tarea no existe en este sitio.');
    }
}
