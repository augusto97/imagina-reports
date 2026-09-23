<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Mcp\ApiGateway;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API tokens exist for the MCP connector, and only work through it.
 *
 * A token's permissions (read-only, "may not send reports"…) are enforced tool by tool in the
 * MCP layer. Used directly against /api/v1 the same token would skip all of that and reach
 * every endpoint its user can — so a bearer-authenticated request is refused unless it carries
 * the gateway's server-side marker, which no HTTP client can set.
 *
 * The panel's own requests are untouched: they authenticate by session cookie, never bearer.
 */
final class RestrictApiTokens
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null
            && $request->user() !== null
            && $request->attributes->get(ApiGateway::ATTRIBUTE) !== true
        ) {
            return response()->json([
                'message' => 'Este token solo funciona a través del conector MCP de Imagina Reports ('.url('/api/v1/mcp').').',
            ], 403);
        }

        return $next($request);
    }
}
