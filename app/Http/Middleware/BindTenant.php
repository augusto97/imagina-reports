<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant for the request from the authenticated user's agency, so the
 * AgencyScope filters all subsequent queries automatically (CLAUDE.md §5).
 *
 * Runs after authentication; for unauthenticated requests it is a no-op and the
 * scope stays inactive.
 */
final class BindTenant
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->tenant->set($user->agency_id);
        }

        return $next($request);
    }
}
