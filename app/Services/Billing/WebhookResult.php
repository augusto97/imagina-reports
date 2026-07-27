<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use Illuminate\Support\Carbon;

/**
 * A normalized subscription-status change read from a provider (SaaS Fase 2) — either from
 * an inbound webhook or from a direct status fetch during reconciliation.
 *
 * `currentPeriodEnd` is the provider's next charge date when it tells us one; null means
 * "unknown", and the caller keeps whatever it already had.
 */
final readonly class WebhookResult
{
    public function __construct(
        public string $externalId,
        public SubscriptionStatus $status,
        public ?Carbon $currentPeriodEnd = null,
    ) {}
}
