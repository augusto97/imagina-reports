<?php

declare(strict_types=1);

use App\Http\Middleware\BindTenant;
use App\Http\Middleware\EnsureAgencyActive;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie auth: same-origin requests from the admin/portal SPAs
        // (SANCTUM_STATEFUL_DOMAINS) get session + CSRF, so the cookie authenticates
        // the /api/v1 routes (CLAUDE.md §2 — Sanctum, cookie sessions for own SPAs).
        $middleware->statefulApi();

        // Resolve the tenant from the authenticated user; apply after auth:sanctum.
        $middleware->alias([
            'tenant' => BindTenant::class,
            'platform' => EnsurePlatformAdmin::class,
            'active' => EnsureAgencyActive::class,
        ]);

        // Negotiate the request locale for the API (CLAUDE.md §6).
        $middleware->appendToGroup('api', SetLocale::class);

        // BindTenant must run before SubstituteBindings so route-model binding is
        // already agency-scoped (a cross-agency {model} then 404s, not leaks).
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            BindTenant::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
