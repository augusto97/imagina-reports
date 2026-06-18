<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Negotiates the request locale (CLAUDE.md §6 i18n: ES default; EN, PT-BR available)
 * from the Accept-Language header, falling back to the app default.
 */
final class SetLocale
{
    public const SUPPORTED = ['es', 'en', 'pt_BR'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->getPreferredLanguage(self::SUPPORTED);

        if (is_string($locale)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
