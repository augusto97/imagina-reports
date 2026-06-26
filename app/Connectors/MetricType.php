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

    // A bounded, multi-dimension pre-aggregated table: rows of {dim1, dim2, …, measures}
    // (e.g. ga4.geo = [{country, city, sessions, users}, …]). The editor shapes it with
    // filters / breakdown / measure / sort / limit; the DatasetEngine slices it at
    // resolve time. Still aggregated at the source and top-N — never raw rows (§3.3).
    case Dataset = 'dataset';
}
