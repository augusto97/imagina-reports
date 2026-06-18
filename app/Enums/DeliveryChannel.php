<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a report is delivered (CLAUDE.md §5).
 */
enum DeliveryChannel: string
{
    case Email = 'email';
    case Portal = 'portal';
}
