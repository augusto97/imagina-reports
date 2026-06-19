<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ReportGenerated;
use App\Events\ReportSent;
use App\Models\Report;
use App\Services\Webhooks\WebhookDispatcher;

/**
 * Emits the report lifecycle webhooks (CLAUDE.md §8): `report.generated` and
 * `report.sent`. Registered as an event subscriber so both hooks live in one place.
 */
final readonly class ReportWebhookSubscriber
{
    public function __construct(private WebhookDispatcher $webhooks) {}

    public function handleGenerated(ReportGenerated $event): void
    {
        $this->webhooks->dispatch($event->report->agency_id, 'report.generated', $this->payload($event->report));
    }

    public function handleSent(ReportSent $event): void
    {
        $this->webhooks->dispatch($event->report->agency_id, 'report.sent', $this->payload($event->report));
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            ReportGenerated::class => 'handleGenerated',
            ReportSent::class => 'handleSent',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Report $report): array
    {
        return [
            'report_id' => $report->id,
            'public_token' => $report->public_token,
            'status' => $report->status->value,
            'health_score' => $report->health_score,
            'period_start' => $report->period_start->toDateString(),
            'period_end' => $report->period_end->toDateString(),
        ];
    }
}
