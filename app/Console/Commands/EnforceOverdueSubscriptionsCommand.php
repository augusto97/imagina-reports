<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\BillingService;
use Illuminate\Console\Command;

/**
 * Suspends agencies whose payment stayed overdue past the grace window (SaaS Fase 2).
 * Wired to the scheduler so a failed payment eventually cuts access.
 */
final class EnforceOverdueSubscriptionsCommand extends Command
{
    protected $signature = 'billing:enforce-overdue';

    protected $description = 'Suspend agencies whose subscription is overdue past the grace window.';

    public function handle(BillingService $billing): int
    {
        $count = $billing->enforceOverdue();

        $this->info("Suspended {$count} overdue agency(ies).");

        return self::SUCCESS;
    }
}
