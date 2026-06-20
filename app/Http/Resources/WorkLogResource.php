<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WorkLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class WorkLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $log = $this->resource;

        if (! $log instanceof WorkLog) {
            throw new LogicException('WorkLogResource expects a WorkLog.');
        }

        return [
            'id' => $log->id,
            'report_id' => $log->report_id,
            'site_id' => $log->site_id,
            'performed_at' => $log->performed_at->toIso8601String(),
            'description' => $log->description,
            'minutes' => $log->minutes,
            'category' => $log->category,
            'screenshot_path' => $log->screenshot_path,
        ];
    }
}
