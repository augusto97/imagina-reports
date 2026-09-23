<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Insights;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolError;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ProposalTool;

final class ProposeAcknowledgeAnomaly extends ProposalTool
{
    public function name(): string
    {
        return 'propose_acknowledge_anomaly';
    }

    public function title(): string
    {
        return 'Marcar una anomalía como revisada';
    }

    public function description(): string
    {
        return 'Propone marcar como revisada una anomalía detectada (deja de aparecer como pendiente). Requiere confirmación con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::ReportsWrite;
    }

    protected function properties(): array
    {
        return ['anomaly_id' => self::integer('Id de la anomalía (ver list_anomalies).')];
    }

    protected function required(): array
    {
        return ['anomaly_id'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $anomalyId = $arguments->int('anomaly_id');

        foreach ($this->fetchList($context, 'anomalies') as $anomaly) {
            if (self::intOrNull($anomaly['id'] ?? null) === $anomalyId) {
                return new PreparedAction(
                    'Marcar como revisada la anomalía de «'.self::str($anomaly['site_name'] ?? '').'» en '
                        .self::str($anomaly['metric'] ?? '').' ('.self::str($anomaly['change_percent'] ?? '').' %).',
                    ['anomaly_id' => $anomalyId],
                );
            }
        }

        throw new ToolError('Esa anomalía no existe.');
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $anomalyId = $arguments->int('anomaly_id');
        $this->api->call($context, 'POST', "anomalies/{$anomalyId}/acknowledge")->orFail();

        return ToolResult::data(['anomaly_id' => $anomalyId], 'Anomalía marcada como revisada.');
    }
}
