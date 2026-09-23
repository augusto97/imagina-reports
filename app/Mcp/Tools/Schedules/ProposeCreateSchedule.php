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

final class ProposeCreateSchedule extends ProposalTool
{
    public function name(): string
    {
        return 'propose_create_schedule';
    }

    public function title(): string
    {
        return 'Programar el envío automático';
    }

    public function description(): string
    {
        return 'Propone que un reporte se genere y envíe solo, cada mes (el día «send_day», 1–28) o cada semana, '
            .'a los destinatarios de su configuración. Requiere confirmación con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::SchedulesWrite;
    }

    protected function properties(): array
    {
        return [
            'report_definition_id' => self::integer('Id de la configuración de reporte.'),
            'cadence' => ['type' => 'string', 'enum' => ['monthly', 'weekly'], 'description' => 'monthly (mensual) o weekly (semanal).'],
            'send_day' => self::integer('Día del mes en que sale (1–28). Solo para mensual; por defecto 1.'),
        ];
    }

    protected function required(): array
    {
        return ['report_definition_id', 'cadence'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $definitionId = $arguments->int('report_definition_id');
        $definition = $this->fetch($context, "report-definitions/{$definitionId}");
        $cadence = $arguments->string('cadence');

        if (! in_array($cadence, ['monthly', 'weekly'], true)) {
            throw new ToolError('«cadence» debe ser monthly o weekly.');
        }

        $day = $arguments->optionalInt('send_day') ?? 1;
        if ($day < 1 || $day > 28) {
            throw new ToolError('«send_day» debe estar entre 1 y 28.');
        }

        $recipients = is_array($definition['recipients'] ?? null) ? array_filter($definition['recipients'], 'is_string') : [];
        $when = $cadence === 'monthly' ? "cada mes, el día {$day}" : 'cada semana';

        return new PreparedAction(
            'Generar y enviar automáticamente «'.self::str($definition['name'] ?? '')."» {$when}, a: "
                .($recipients === [] ? 'nadie todavía (añade destinatarios)' : implode(', ', $recipients)).'.',
            ['report_definition_id' => $definitionId, 'cadence' => $cadence, 'send_day' => $day],
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $schedule = $this->api->call($context, 'POST', 'schedules', [
            'report_definition_id' => $arguments->int('report_definition_id'),
            'cadence' => $arguments->string('cadence'),
            'send_day' => $arguments->int('send_day'),
        ])->orFail();

        return ToolResult::data(['programacion' => self::pick($schedule->json, ['id', 'cadence', 'send_day', 'next_run_at'])], 'Envío programado.');
    }
}
