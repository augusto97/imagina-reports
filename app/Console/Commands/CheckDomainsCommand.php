<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Agency;
use App\Services\Domains\DomainVerifier;
use Illuminate\Console\Command;

/**
 * Re-checks agencies' custom domains, so one goes live by itself once its DNS and the
 * server's certificate are in place — and drops back to the platform address if it stops
 * answering (a client never gets a dead link).
 */
final class CheckDomainsCommand extends Command
{
    protected $signature = 'domains:check {--all : Also re-check domains that are already live}';

    protected $description = 'Check agency custom domains (DNS + HTTPS) and update their status';

    public function handle(DomainVerifier $verifier): int
    {
        $query = Agency::query()->whereNotNull('domain')->where('domain', '!=', '');
        if (! (bool) $this->option('all')) {
            $query->where(static fn ($q) => $q->whereNull('domain_status')->orWhere('domain_status', '!=', DomainVerifier::ACTIVE));
        }

        $checked = 0;
        foreach ($query->get() as $agency) {
            $verifier->check($agency);
            $checked++;
        }

        $this->info("Dominios revisados: {$checked}");

        return self::SUCCESS;
    }
}
