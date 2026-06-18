<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Connectors\Period;
use App\Events\ReportGenerated;
use App\Models\DataSource;
use App\Reports\MetricBagLoader;
use App\Reports\UpsellDetector;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * On report generation (CLAUDE.md §13): derives upsell opportunities from the
 * resolved metrics + connected sources and surfaces them to the agency as an
 * internal alert (log) and an `upsell.detected` webhook (§8). Internal-only — never
 * shown to the client. Reads only stored snapshots, no live APIs.
 */
final readonly class DetectUpsellOpportunities
{
    public function __construct(
        private MetricBagLoader $bags,
        private UpsellDetector $detector,
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

        $connected = array_values(
            DataSource::query()
                ->where('site_id', $definition->site_id)
                ->get()
                ->map(static fn (DataSource $source): string => $source->type->value)
                ->all(),
        );

        foreach ($this->detector->detect($current, $previous, $connected) as $opportunity) {
            $payload = [
                'report_id' => $report->id,
                'site_id' => $definition->site_id,
                'opportunity' => $opportunity->toArray(),
            ];

            Log::info('Upsell opportunity: '.$opportunity->type->label(), ['agency_id' => $report->agency_id] + $payload);

            $this->webhooks->dispatch($report->agency_id, 'upsell.detected', $payload);
        }
    }
}
