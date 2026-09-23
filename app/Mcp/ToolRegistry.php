<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\Account\GetContext;
use App\Mcp\Tools\Clients\GetSite;
use App\Mcp\Tools\Clients\ListClients;
use App\Mcp\Tools\Clients\ProposeCreateClient;
use App\Mcp\Tools\Clients\ProposeCreateSite;
use App\Mcp\Tools\Clients\ProposeDeleteClient;
use App\Mcp\Tools\Clients\ProposeDeleteSite;
use App\Mcp\Tools\Clients\ProposeUpdateClient;
use App\Mcp\Tools\Clients\ProposeUpdateSite;
use App\Mcp\Tools\Data\GetMetricCatalog;
use App\Mcp\Tools\Data\GetSyncStatus;
use App\Mcp\Tools\Data\ListConnectors;
use App\Mcp\Tools\Data\QueryMetrics;
use App\Mcp\Tools\Insights\GetTrends;
use App\Mcp\Tools\Insights\GetUpsellOpportunities;
use App\Mcp\Tools\Insights\ListAnomalies;
use App\Mcp\Tools\Insights\ProposeAcknowledgeAnomaly;
use App\Mcp\Tools\Reports\GetReport;
use App\Mcp\Tools\Reports\ListDeliveries;
use App\Mcp\Tools\Reports\ListReports;
use App\Mcp\Tools\Reports\ProposeAddReportComment;
use App\Mcp\Tools\Reports\ProposeApproveReport;
use App\Mcp\Tools\Reports\ProposeDeleteReport;
use App\Mcp\Tools\Reports\ProposeGenerateReport;
use App\Mcp\Tools\Reports\ProposeRegenerateNarrative;
use App\Mcp\Tools\Reports\ProposeRetryFailedDeliveries;
use App\Mcp\Tools\Reports\ProposeSendReport;
use App\Mcp\Tools\Reports\ProposeUpdateNarrative;
use App\Mcp\Tools\Schedules\ProposeCreateSchedule;
use App\Mcp\Tools\Schedules\ProposeDeleteSchedule;
use App\Mcp\Tools\Sources\DiscoverAccounts;
use App\Mcp\Tools\Sources\GetConnectLink;
use App\Mcp\Tools\Sources\ProposeAddDataSource;
use App\Mcp\Tools\Sources\ProposeBackfillSite;
use App\Mcp\Tools\Sources\ProposeDeleteDataSource;
use App\Mcp\Tools\Sources\ProposeSelectAccount;
use App\Mcp\Tools\Sources\ProposeSyncSite;
use App\Mcp\Tools\Sources\TestDataSource;
use App\Mcp\Tools\Templates\ListTemplates;
use App\Mcp\Tools\Templates\ProposeCreateReportDefinition;
use App\Mcp\Tools\Templates\ProposeCreateTemplateWithAi;
use App\Mcp\Tools\Templates\ProposeUpdateReportDefinition;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\WorkLogs\ListWorkLogs;
use App\Mcp\Tools\WorkLogs\ProposeAddWorkLogs;
use App\Mcp\Tools\WorkLogs\ProposeDeleteWorkLog;
use Illuminate\Contracts\Container\Container;

/**
 * Every tool the connector offers. Adding a capability = one class + one line here.
 */
final class ToolRegistry
{
    /** @var list<class-string<Tool>> */
    private const TOOLS = [
        GetContext::class,
        GetSite::class,
        ListClients::class,
        ProposeCreateClient::class,
        ProposeCreateSite::class,
        ProposeDeleteClient::class,
        ProposeDeleteSite::class,
        ProposeUpdateClient::class,
        ProposeUpdateSite::class,
        GetMetricCatalog::class,
        GetSyncStatus::class,
        ListConnectors::class,
        QueryMetrics::class,
        GetTrends::class,
        GetUpsellOpportunities::class,
        ListAnomalies::class,
        ProposeAcknowledgeAnomaly::class,
        GetReport::class,
        ListDeliveries::class,
        ListReports::class,
        ProposeAddReportComment::class,
        ProposeApproveReport::class,
        ProposeDeleteReport::class,
        ProposeGenerateReport::class,
        ProposeRegenerateNarrative::class,
        ProposeRetryFailedDeliveries::class,
        ProposeSendReport::class,
        ProposeUpdateNarrative::class,
        ProposeCreateSchedule::class,
        ProposeDeleteSchedule::class,
        DiscoverAccounts::class,
        GetConnectLink::class,
        ProposeAddDataSource::class,
        ProposeBackfillSite::class,
        ProposeDeleteDataSource::class,
        ProposeSelectAccount::class,
        ProposeSyncSite::class,
        TestDataSource::class,
        ListTemplates::class,
        ProposeCreateReportDefinition::class,
        ProposeCreateTemplateWithAi::class,
        ProposeUpdateReportDefinition::class,
        ListWorkLogs::class,
        ProposeAddWorkLogs::class,
        ProposeDeleteWorkLog::class,
    ];

    /** @var array<string, Tool>|null */
    private ?array $tools = null;

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string, Tool>
     */
    public function all(): array
    {
        if ($this->tools === null) {
            $this->tools = [];
            foreach (self::TOOLS as $class) {
                $tool = $this->container->make($class);
                $this->tools[$tool->name()] = $tool;
            }
        }

        return $this->tools;
    }

    public function find(string $name): ?Tool
    {
        return $this->all()[$name] ?? null;
    }
}
