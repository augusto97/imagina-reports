<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome of a delivery attempt (CLAUDE.md §5).
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
}
