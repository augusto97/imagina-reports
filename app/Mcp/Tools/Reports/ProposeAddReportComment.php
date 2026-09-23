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

final class ProposeAddReportComment extends ReportProposal
{
    public function name(): string
    {
        return 'propose_add_report_comment';
    }

    public function title(): string
    {
        return 'Comentar un reporte';
    }

    public function description(): string
    {
        return 'Propone añadir un comentario a un reporte. «visibility»: internal (solo el equipo, por defecto) o client (lo ve el cliente en su reporte). '
            .'Requiere confirmación con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::ReportsWrite;
    }

    protected function properties(): array
    {
        return [
            ...$this->reportProperty(),
            'body' => self::text('Texto del comentario (máximo 5000 caracteres).'),
            'visibility' => ['type' => 'string', 'enum' => ['internal', 'client'], 'description' => 'internal (por defecto) o client.'],
        ];
    }

    protected function required(): array
    {
        return ['report_id', 'body'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $reportId = $arguments->int('report_id');
        $body = $arguments->string('body');
        $visibility = $arguments->optionalString('visibility') ?? 'internal';

        if (! in_array($visibility, ['internal', 'client'], true)) {
            throw new ToolError('«visibility» debe ser internal o client.');
        }

        $who = $visibility === 'client' ? 'visible para el cliente' : 'interno, solo para el equipo';

        return new PreparedAction(
            "Añadir un comentario ({$who}) a ".$this->describeReport($context, $reportId).":\n\n{$body}",
            ['report_id' => $reportId, 'body' => $body, 'visibility' => $visibility],
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $reportId = $arguments->int('report_id');
        $this->api->call($context, 'POST', "reports/{$reportId}/comments", [
            'body' => $arguments->string('body'),
            'visibility' => $arguments->string('visibility'),
        ])->orFail();

        return ToolResult::data(['report_id' => $reportId], 'Comentario añadido.');
    }
}
