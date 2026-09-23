<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Reports;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReportProposal;

final class ProposeRetryFailedDeliveries extends ReportProposal
{
    public function name(): string
    {
        return 'propose_retry_failed_deliveries';
    }

    public function title(): string
    {
        return 'Reintentar envíos fallidos';
    }

    public function description(): string
    {
        return 'Propone reintentar los envíos por email que fallaron de un reporte. Los destinatarios que fallaron volverán a recibirlo. Requiere confirmación con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::ReportsSend;
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

        return new PreparedAction('Reintentar los envíos fallidos de '.$this->describeReport($context, $reportId).'.', ['report_id' => $reportId]);
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $reportId = $arguments->int('report_id');
        $this->api->call($context, 'POST', "reports/{$reportId}/deliveries/retry-failed")->orFail();

        return ToolResult::data(['report_id' => $reportId], 'Reintento en cola. Revisa el resultado con list_deliveries en un minuto.');
    }
}
