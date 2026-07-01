<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReportDeliveryResource;
use App\Models\Report;
use App\Models\ReportDelivery;
use App\Services\DeliveryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The email delivery log of a report (CLAUDE.md §5): who it was sent to, when, and
 * whether it failed — plus a retry action. Tenant-scoped by the global AgencyScope.
 */
final class ReportDeliveryController extends Controller
{
    public function index(Report $report): AnonymousResourceCollection
    {
        return ReportDeliveryResource::collection($report->deliveries()->latest()->get());
    }

    /** Re-send a single failed/previous attempt to its recipient (records a fresh attempt). */
    public function retry(ReportDelivery $reportDelivery, DeliveryService $delivery): ReportDeliveryResource
    {
        $report = $reportDelivery->report()->firstOrFail();

        return ReportDeliveryResource::make($delivery->sendOne($report, $reportDelivery->recipient));
    }

    /** Retry every failed recipient of a report at once (bulk). */
    public function retryFailed(Report $report, DeliveryService $delivery): AnonymousResourceCollection
    {
        $recipients = $report->deliveries()
            ->where('status', DeliveryStatus::Failed->value)
            ->get()
            ->pluck('recipient')
            ->all();

        $unique = array_values(array_unique(array_filter($recipients, static fn ($recipient): bool => is_string($recipient) && $recipient !== '')));

        $fresh = array_map(fn (string $recipient): ReportDelivery => $delivery->sendOne($report, $recipient), $unique);

        return ReportDeliveryResource::collection($fresh);
    }
}
