<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Connection health of a configured data source (CLAUDE.md §5). Distinct from a
 * snapshot's per-period status (ok/partial/failed); this reflects the last sync/test.
 */
enum DataSourceStatus: string
{
    case Pending = 'pending';
    case Ok = 'ok';
    case Error = 'error';
}
