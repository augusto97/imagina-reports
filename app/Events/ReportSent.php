<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Report;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when a report has been delivered to its recipients (CLAUDE.md §3.1/§8).
 * Drives the `report.sent` webhook.
 */
final class ReportSent
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Report $report) {}
}
