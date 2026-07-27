<?php

declare(strict_types=1);

namespace App\Services\Billing\Concerns;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Providers hand us dates as free-form strings (`next_payment_date`, `next_billing_time`).
 * A malformed one must never break a webhook, so parsing is best-effort: on anything we
 * can't read we return null and the caller keeps the value it already had.
 */
trait ParsesProviderDates
{
    protected function parseProviderDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
