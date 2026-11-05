<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Agency;
use App\Services\Domains\DomainVerifier;
use App\Support\CustomDomain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves an agency's client-facing pages on its own domain (reportes.suagencia.com).
 *
 * On a host that belongs to an agency, only what its clients use is answered — the portal,
 * the report and dashboard pages, embeds, connection links, the public API and the assets.
 * Everything else (the panel, the login…) goes to the platform's own address, and the bare
 * domain goes to the agency's website. Hosts that aren't an agency's domain pass untouched,
 * so the platform keeps working however it is reached.
 */
final class ResolveCustomDomain
{
    private const CLIENT_PATHS = ['portal/', 'reports/', 'dashboard/', 'embed/', 'connect/', 'api/v1/public/', 'api/v1/domain-check', 'build/', 'storage/', 'favicon', 'robots.txt', 'up'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $platform = DomainVerifier::platformHost();
        if ($host === '' || $host === $platform || in_array($host, ['localhost', '127.0.0.1'], true)) {
            return $next($request);
        }

        $agency = $this->agencyFor($host);
        if ($agency === null) {
            return $next($request);
        }

        $request->attributes->set(CustomDomain::ATTRIBUTE, $agency['id']);
        $path = ltrim($request->path(), '/');

        if ($path === '' || $path === '/') {
            $website = $agency['website'];

            return $website !== null ? redirect()->away($website) : response()->view('domain-home', ['agencyName' => $agency['name']]);
        }

        foreach (self::CLIENT_PATHS as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

        $appUrl = config('app.url');

        return redirect()->away(rtrim(is_string($appUrl) ? $appUrl : '', '/').$request->getRequestUri());
    }

    /**
     * @return array{id: int, name: string, website: string|null}|null
     */
    private function agencyFor(string $host): ?array
    {
        /** @var array{id: int, name: string, website: string|null}|false $cached */
        $cached = Cache::remember(DomainVerifier::cacheKey($host), now()->addMinutes(5), static function () use ($host): array|false {
            $agency = Agency::query()
                ->where('domain', $host)
                ->whereIn('domain_status', [DomainVerifier::PENDING_SSL, DomainVerifier::ACTIVE])
                ->first();

            return $agency === null ? false : ['id' => $agency->id, 'name' => $agency->name, 'website' => $agency->contact()['website'] ?? null];
        });

        return $cached === false ? null : $cached;
    }
}
