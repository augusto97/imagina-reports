<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a generated report (CLAUDE.md §5/§8): draft → approved → sent.
 */
enum ReportStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Sent = 'sent';
}
