<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;

/**
 * A normalized subscription-status change parsed from a provider webhook (SaaS Fase 2).
 */
final readonly class WebhookResult
{
    public function __construct(
        public string $externalId,
        public SubscriptionStatus $status,
    ) {}
}
