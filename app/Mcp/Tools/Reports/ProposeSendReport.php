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

/**
 * The one action that reaches the agency's client. It has its own permission and its preview
 * names every recipient, because this is the change nobody can take back.
 */
final class ProposeSendReport extends ReportProposal
{
    public function name(): string
    {
        return 'propose_send_report';
    }

    public function title(): string
    {
        return 'Enviar un reporte al cliente';
    }

    public function description(): string
    {
        return 'Propone enviar por email un reporte aprobado a los destinatarios de su configuración (PDF + enlace al portal). '
            .'El reporte debe estar aprobado (propose_approve_report). La vista previa lista los destinatarios. '
            .'Irreversible: el cliente lo recibe. Requiere confirmación explícita con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::ReportsSend;
    }

    public function destructive(): bool
    {
        return true;
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
        $status = $this->reportStatus($context, $reportId);

        if ($status === 'draft') {
            throw new ToolError('El reporte está en borrador. Apruébalo primero con propose_approve_report.');
        }

        $definitionId = null;
        foreach ($this->fetchList($context, 'reports', ['limit' => 1000]) as $report) {
            if (self::intOrNull($report['id'] ?? null) === $reportId) {
                $definitionId = self::intOrNull($report['report_definition_id'] ?? null);

                break;
            }
        }

        $recipients = [];
        if ($definitionId !== null) {
            $definition = $this->fetch($context, "report-definitions/{$definitionId}");
            foreach (is_array($definition['recipients'] ?? null) ? $definition['recipients'] : [] as $email) {
                if (is_string($email)) {
                    $recipients[] = $email;
                }
            }
        }

        if ($recipients === []) {
            throw new ToolError('Este reporte no tiene destinatarios. Añádelos con propose_update_report_definition.');
        }

        $summary = 'Enviar '.$this->describeReport($context, $reportId).' por email a: '.implode(', ', $recipients).'.';
        if ($status === 'sent') {
            $summary .= ' Este reporte ya se envió antes: lo recibirán otra vez.';
        }

        return new PreparedAction($summary.' No se puede deshacer.', ['report_id' => $reportId], ['destinatarios' => $recipients]);
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $reportId = $arguments->int('report_id');
        $this->api->call($context, 'POST', "reports/{$reportId}/send")->orFail();

        return ToolResult::data(['report_id' => $reportId], 'Envío en marcha. Revisa el resultado con list_deliveries en un minuto.');
    }
}
