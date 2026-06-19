<?php

declare(strict_types=1);

namespace App\Connectors;

/**
 * Outcome of a connector fetch (CLAUDE.md §7). Mirrors the snapshot status
 * persisted in ir_metric_snapshots: a failing API yields partial/failed data
 * instead of throwing, so a report can still render with the last good snapshot.
 */
enum MetricSetStatus: string
{
    case Ok = 'ok';
    case Partial = 'partial';
    case Failed = 'failed';
}
