<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\BillingService;
use Illuminate\Console\Command;

/**
 * Re-reads every live subscription from its payment provider and repairs any drift
 * (SaaS Fase 2). The safety net for lost webhooks: without it, a single undelivered
 * notification would leave an agency active without paying, or paying without access.
 */
final class ReconcileSubscriptionsCommand extends Command
{
    protected $signature = 'billing:reconcile';

    protected $description = 'Re-read live subscriptions from the payment providers and fix any drift.';

    public function handle(BillingService $billing): int
    {
        $count = $billing->reconcile();

        $this->info("Corrected {$count} subscription(s).");

        return self::SUCCESS;
    }
}
