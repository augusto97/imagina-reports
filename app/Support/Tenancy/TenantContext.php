<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;

/**
 * Holds the currently-active agency (tenant) for the request/job lifecycle.
 *
 * Registered as a singleton (AppServiceProvider). The AgencyScope only filters
 * when an agency is bound, so framework bootstrapping and authentication — which
 * run before a tenant is resolved — stay unscoped and safe.
 */
final class TenantContext
{
    private ?int $agencyId = null;

    public function set(int $agencyId): void
    {
        $this->agencyId = $agencyId;
    }

    public function id(): ?int
    {
        return $this->agencyId;
    }

    public function hasAgency(): bool
    {
        return $this->agencyId !== null;
    }

    public function forget(): void
    {
        $this->agencyId = null;
    }

    /**
     * Run a callback scoped to a specific agency, restoring the previous tenant
     * afterwards. Useful for scheduled/queued work that spans multiple agencies.
     *
     * @template TReturn
     *
     * @param  Closure():TReturn  $callback
     * @return TReturn
     */
    public function actingAs(int $agencyId, Closure $callback): mixed
    {
        $previous = $this->agencyId;
        $this->agencyId = $agencyId;

        try {
            return $callback();
        } finally {
            $this->agencyId = $previous;
        }
    }
}
