<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReportDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class ReportDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $delivery = $this->resource;

        if (! $delivery instanceof ReportDelivery) {
            throw new LogicException('ReportDeliveryResource expects a ReportDelivery.');
        }

        return [
            'id' => $delivery->id,
            'report_id' => $delivery->report_id,
            'channel' => $delivery->channel->value,
            'recipient' => $delivery->recipient,
            'status' => $delivery->status->value,
            'sent_at' => $delivery->sent_at?->toIso8601String(),
            'error' => $delivery->error,
            'created_at' => $delivery->created_at?->toIso8601String(),
        ];
    }
}
