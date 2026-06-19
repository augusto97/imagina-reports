<?php

declare(strict_types=1);

namespace App\Connectors;

/**
 * The shape of a metric's value in the normalized metric bag (CLAUDE.md §10.1):
 * a single number, a time series, or a tabular top-N result.
 */
enum MetricType: string
{
    case Scalar = 'scalar';   // e.g. ga4.sessions
    case Series = 'series';   // e.g. ga4.sessions_by_date
    case Table = 'table';     // e.g. ga4.top_pages
}
