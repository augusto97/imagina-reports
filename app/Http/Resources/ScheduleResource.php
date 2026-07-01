<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class ScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $schedule = $this->resource;

        if (! $schedule instanceof Schedule) {
            throw new LogicException('ScheduleResource expects a Schedule.');
        }

        return [
            'id' => $schedule->id,
            'report_definition_id' => $schedule->report_definition_id,
            'cadence' => $schedule->cadence->value,
            'send_day' => $schedule->send_day,
            'next_run_at' => $schedule->next_run_at->toIso8601String(),
        ];
    }
}
