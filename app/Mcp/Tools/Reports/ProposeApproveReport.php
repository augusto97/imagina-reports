<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Reports;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReportProposal;

final class ProposeApproveReport extends ReportProposal
{
    public function name(): string
    {
        return 'propose_approve_report';
    }

    public function title(): string
    {
        return 'Aprobar un reporte';
    }

    public function description(): string
    {
        return 'Propone marcar un reporte en borrador como aprobado (paso necesario antes de enviarlo). Requiere confirmación con apply_proposal.';
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

        return new PreparedAction('Aprobar '.$this->describeReport($context, $reportId).'.', ['report_id' => $reportId]);
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $reportId = $arguments->int('report_id');
        $this->api->call($context, 'POST', "reports/{$reportId}/approve")->orFail();

        return ToolResult::data(['report_id' => $reportId], 'Reporte aprobado. Ya se puede enviar (propose_send_report).');
    }
}
