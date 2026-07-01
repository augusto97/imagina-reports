<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an agency's subscription (SaaS Fase 2). `Active` keeps the agency running;
 * `PastDue` is the grace window after a failed/late payment; `Cancelled`/`Suspended` cut access.
 */
enum SubscriptionStatus: string
{
    case Pending = 'pending';      // created, awaiting the payer's authorization
    case Active = 'active';        // paid & current
    case PastDue = 'past_due';     // payment failed/late — in the grace window
    case Suspended = 'suspended';  // grace elapsed / provider paused
    case Cancelled = 'cancelled';  // ended

    public function grantsAccess(): bool
    {
        return $this === self::Active || $this === self::PastDue;
    }
}
