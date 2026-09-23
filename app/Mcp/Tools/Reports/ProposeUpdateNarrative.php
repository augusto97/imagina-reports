<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Reports;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolError;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReportProposal;

final class ProposeUpdateNarrative extends ReportProposal
{
    public function name(): string
    {
        return 'propose_update_narrative';
    }

    public function title(): string
    {
        return 'Editar el resumen de un reporte';
    }

    public function description(): string
    {
        return 'Propone sustituir el resumen ejecutivo de un reporte por el texto que indiques (máximo 5000 caracteres). '
            .'Escríbelo para el cliente, en lenguaje claro. Requiere confirmación con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::ReportsWrite;
    }

    protected function properties(): array
    {
        return [...$this->reportProperty(), 'text' => self::text('El nuevo resumen ejecutivo.')];
    }

    protected function required(): array
    {
        return ['report_id', 'text'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $reportId = $arguments->int('report_id');
        $text = $arguments->string('text');

        if (mb_strlen($text) > 5000) {
            throw new ToolError('El resumen no puede superar los 5000 caracteres.');
        }

        return new PreparedAction(
            'Sustituir el resumen ejecutivo de '.$this->describeReport($context, $reportId)." por:\n\n{$text}",
            ['report_id' => $reportId, 'text' => $text],
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $reportId = $arguments->int('report_id');
        $this->api->call($context, 'PUT', "reports/{$reportId}/narrative", ['text' => $arguments->string('text')])->orFail();

        return ToolResult::data(['report_id' => $reportId], 'Resumen actualizado.');
    }
}
