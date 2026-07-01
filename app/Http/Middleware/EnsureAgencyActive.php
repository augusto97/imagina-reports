<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Agency;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a suspended agency (SaaS Fase 2 — unpaid) from everything except reading its
 * profile and paying to reactivate. Platform admins are never blocked (they run support).
 */
final class EnsureAgencyActive
{
    /** Routes a suspended agency may still reach (auth, profile, billing). */
    private const ALLOWED = ['api.user', 'api.logout', 'api.agency.show', 'api.billing.show', 'api.billing.subscribe'];

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->is_platform_admin) {
            return $next($request);
        }

        $agencyId = $this->tenant->id();
        if ($agencyId === null) {
            return $next($request);
        }

        $agency = Agency::query()->find($agencyId);
        if ($agency !== null && $agency->isSuspended() && ! in_array($request->route()?->getName(), self::ALLOWED, true)) {
            abort(402, 'Tu cuenta está suspendida por falta de pago. Regulariza la suscripción para reactivarla.');
        }

        return $next($request);
    }
}
