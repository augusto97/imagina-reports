<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Connectors\Period;
use App\Events\ReportGenerated;
use App\Reports\AnomalyDetector;
use App\Reports\MetricBagLoader;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * On report generation (CLAUDE.md §13): compares the report's period against the
 * previous one, and for each detected anomaly raises an internal alert (log) and an
 * `anomaly.detected` webhook (§8). Reads only stored snapshots — no live APIs.
 */
final readonly class DetectReportAnomalies
{
    public function __construct(
        private MetricBagLoader $bags,
        private AnomalyDetector $detector,
        private WebhookDispatcher $webhooks,
    ) {}

    public function handle(ReportGenerated $event): void
    {
        $report = $event->report;
        $definition = $report->definition;

        if ($definition === null) {
            return;
        }

        $period = new Period($report->period_start, $report->period_end);
        $current = $this->bags->forSite($definition->site_id, $period);
        $previous = $this->bags->forSite($definition->site_id, $period->previous());

        foreach ($this->detector->detect($current, $previous) as $anomaly) {
            $context = [
                'agency_id' => $report->agency_id,
                'report_id' => $report->id,
                'site_id' => $definition->site_id,
                'anomaly' => $anomaly->toArray(),
            ];

            Log::warning('Anomaly detected: '.$anomaly->type->label(), $context);

            $this->webhooks->dispatch($report->agency_id, 'anomaly.detected', [
                'report_id' => $report->id,
                'site_id' => $definition->site_id,
                'anomaly' => $anomaly->toArray(),
            ]);
        }
    }
}
