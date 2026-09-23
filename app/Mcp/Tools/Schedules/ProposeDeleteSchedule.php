<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Schedules;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolError;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ProposalTool;

final class ProposeDeleteSchedule extends ProposalTool
{
    public function name(): string
    {
        return 'propose_delete_schedule';
    }

    public function title(): string
    {
        return 'Quitar un envío automático';
    }

    public function description(): string
    {
        return 'Propone dejar de generar y enviar automáticamente un reporte. La configuración del reporte se conserva '
            .'y se puede volver a programar. Requiere confirmación con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::SchedulesWrite;
    }

    protected function properties(): array
    {
        return ['schedule_id' => self::integer('Id del envío programado (ver get_site).')];
    }

    protected function required(): array
    {
        return ['schedule_id'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $scheduleId = $arguments->int('schedule_id');

        foreach ($this->fetchList($context, 'schedules') as $schedule) {
            if (self::intOrNull($schedule['id'] ?? null) !== $scheduleId) {
                continue;
            }
            $definitionId = self::intOrNull($schedule['report_definition_id'] ?? null);
            $name = $definitionId !== null ? self::str($this->fetch($context, "report-definitions/{$definitionId}")['name'] ?? '') : '';

            return new PreparedAction("Dejar de enviar automáticamente «{$name}».", ['schedule_id' => $scheduleId]);
        }

        throw new ToolError('Ese envío programado no existe.');
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $scheduleId = $arguments->int('schedule_id');
        $this->api->call($context, 'DELETE', "schedules/{$scheduleId}")->orFail();

        return ToolResult::data(['schedule_id' => $scheduleId], 'Envío automático quitado.');
    }
}
