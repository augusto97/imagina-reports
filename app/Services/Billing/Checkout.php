<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * The result of starting a subscription: the provider's id + the URL to send the payer to
 * so they authorize the recurring charge (SaaS Fase 2).
 */
final readonly class Checkout
{
    public function __construct(
        public string $externalId,
        public string $approvalUrl,
    ) {}
}
