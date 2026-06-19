<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Report;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when the GENERATE stage finishes a report (CLAUDE.md §3.1/§8). Drives the
 * `report.generated` webhook and anomaly detection, both as listeners.
 */
final class ReportGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Report $report) {}
}
