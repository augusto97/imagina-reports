<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpContext;

/**
 * Helpers shared by the tools that act on one generated report.
 */
abstract class ReportProposal extends ProposalTool
{
    /**
     * A one-line human label for a report: which site, which period, which state.
     */
    protected function describeReport(McpContext $context, int $reportId): string
    {
        $report = $this->fetch($context, "reports/{$reportId}");
        $site = self::str(data_get($report, 'context.site'));
        $period = self::str(data_get($report, 'context.period'));

        return "el reporte #{$reportId} de «{$site}» ({$period}, estado: ".self::str($report['status'] ?? '').')';
    }

    protected function reportStatus(McpContext $context, int $reportId): string
    {
        return self::str($this->fetch($context, "reports/{$reportId}")['status'] ?? '');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function reportProperty(): array
    {
        return ['report_id' => self::integer('Id del reporte (ver list_reports).')];
    }
}
