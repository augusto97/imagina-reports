<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the platform panel to super-admins (SaaS Fase 1). A platform admin manages
 * every agency; everyone else is denied.
 */
final class EnsurePlatformAdmin
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->is_platform_admin, 403, 'Solo para administradores de plataforma.');

        return $next($request);
    }
}
