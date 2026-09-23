<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Reports;

use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\Tools\DeleteTool;

final class ProposeDeleteReport extends DeleteTool
{
    public function name(): string
    {
        return 'propose_delete_report';
    }

    public function title(): string
    {
        return 'Eliminar un reporte';
    }

    public function description(): string
    {
        return 'Propone eliminar un reporte generado (y su PDF). Su enlace de portal dejará de funcionar. Irreversible: requiere confirmación con apply_proposal.';
    }

    protected function properties(): array
    {
        return ['report_id' => self::integer('Id del reporte.')];
    }

    protected function required(): array
    {
        return ['report_id'];
    }

    protected function describe(ToolArguments $arguments, McpContext $context): array
    {
        $reportId = $arguments->int('report_id');
        $report = $this->fetch($context, "reports/{$reportId}");

        return [
            'path' => "reports/{$reportId}",
            'summary' => "Eliminar el reporte #{$reportId} de «".self::str(data_get($report, 'context.site')).'» ('.self::str(data_get($report, 'context.period')).'); su enlace del portal dejará de funcionar.',
        ];
    }
}
