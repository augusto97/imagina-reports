<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Reports;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolError;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ProposalTool;

/**
 * Generation is queued, never awaited: MCP clients give up after about a minute and a report
 * can take longer. The assistant is told how to follow up instead.
 */
final class ProposeGenerateReport extends ProposalTool
{
    public function name(): string
    {
        return 'propose_generate_report';
    }

    public function title(): string
    {
        return 'Generar un reporte';
    }

    public function description(): string
    {
        return 'Propone generar un reporte a partir de una configuración de reporte (report_definition_id, ver get_site) para un periodo. '
            .'Periodo: «month» (AAAA-MM) o «period_start»+«period_end»; por defecto el último mes completo. '
            .'La vista previa avisa de las fuentes con error. Tras confirmarlo con apply_proposal, el reporte se genera en segundo plano '
            .'(alrededor de un minuto): consúltalo después con list_reports. Queda como borrador hasta aprobarlo.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::ReportsWrite;
    }

    protected function properties(): array
    {
        return [
            'report_definition_id' => self::integer('Id de la configuración de reporte.'),
            'month' => self::text('Mes AAAA-MM (opcional).'),
            'period_start' => self::date('Inicio del periodo (opcional).'),
            'period_end' => self::date('Fin del periodo (opcional).'),
        ];
    }

    protected function required(): array
    {
        return ['report_definition_id'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $definitionId = $arguments->int('report_definition_id');
        $definition = $this->fetch($context, "report-definitions/{$definitionId}");
        $siteId = self::intOrNull($definition['site_id'] ?? null) ?? throw new ToolError('La configuración de reporte no tiene sitio.');
        $site = $this->site($context, $siteId);
        $period = self::period($arguments->optionalString('month'), $arguments->optionalDate('period_start'), $arguments->optionalDate('period_end'));

        $failing = [];
        foreach ($this->fetchList($context, "sites/{$siteId}/data-sources") as $source) {
            if (($source['status'] ?? null) === 'error') {
                $failing[] = self::str($source['type'] ?? '').': '.self::str($source['last_error'] ?? 'error');
            }
        }

        $summary = 'Generar el reporte «'.self::str($definition['name'] ?? '').'» de «'.self::str($site['name'] ?? '')
            ."» para el periodo {$period['period_start']} → {$period['period_end']}.";

        if ($failing !== []) {
            $summary .= "\nAtención, estas fuentes tienen error y sus bloques pueden salir vacíos:\n- ".implode("\n- ", $failing);
        }

        return new PreparedAction($summary, ['report_definition_id' => $definitionId, ...$period], ['fuentes_con_error' => $failing]);
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $this->api->call($context, 'POST', 'reports/generate', [
            'report_definition_id' => $arguments->int('report_definition_id'),
            'period_start' => $arguments->date('period_start'),
            'period_end' => $arguments->date('period_end'),
        ])->orFail();

        return ToolResult::data(
            ['en_cola' => true],
            'Reporte en generación. Tardará alrededor de un minuto; consúltalo con list_reports (aparecerá como borrador).',
        );
    }
}
