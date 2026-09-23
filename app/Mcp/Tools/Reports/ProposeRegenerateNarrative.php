<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Reports;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReportProposal;

final class ProposeRegenerateNarrative extends ReportProposal
{
    public function name(): string
    {
        return 'propose_regenerate_narrative';
    }

    public function title(): string
    {
        return 'Reescribir el resumen con IA';
    }

    public function description(): string
    {
        return 'Propone volver a escribir con IA el resumen ejecutivo de un reporte a partir de sus datos. Sustituye el resumen actual. Requiere confirmación con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::ReportsWrite;
    }

    protected function properties(): array
    {
        return $this->reportProperty();
    }

    protected function required(): array
    {
        return ['report_id'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $reportId = $arguments->int('report_id');

        return new PreparedAction('Reescribir con IA el resumen ejecutivo de '.$this->describeReport($context, $reportId).'.', ['report_id' => $reportId]);
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $reportId = $arguments->int('report_id');
        $this->api->call($context, 'POST', "reports/{$reportId}/narrative/regenerate")->orFail();

        return ToolResult::data(['report_id' => $reportId], 'Resumen reescrito. Revísalo con get_report.');
    }
}
