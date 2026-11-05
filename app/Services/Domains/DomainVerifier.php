<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Models\Agency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Walks an agency's custom domain to "live" in two checks, so the agency always knows the
 * one thing left to do:
 *  1. DNS — the domain is a CNAME to the platform host (or resolves to the same IPs).
 *  2. HTTPS — https://domain answers as THIS installation (a certificate is installed on the
 *     server and the request reaches this app), via /api/v1/domain-check.
 * Only an `active` domain is used in the links sent to clients.
 */
final class DomainVerifier
{
    public const PENDING_DNS = 'pending_dns';

    public const PENDING_SSL = 'pending_ssl';

    public const ACTIVE = 'active';

    public function __construct(private readonly DnsResolver $dns) {}

    /** The host clients' domains must point to: the platform's own (APP_URL). */
    public static function platformHost(): string
    {
        $url = config('app.url');
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) ? strtolower($host) : '';
    }

    /** Identifies this installation on /api/v1/domain-check without revealing anything. */
    public static function instance(): string
    {
        $key = config('app.key');

        return substr(hash('sha256', 'domain-check|'.(is_string($key) ? $key : '')), 0, 24);
    }

    /** A clean hostname ("Reportes.MiAgencia.com/" → "reportes.miagencia.com"), or null if it isn't one. */
    public static function normalize(string $input): ?string
    {
        $host = strtolower(trim($input));
        $host = (string) preg_replace('#^https?://#', '', $host);
        $host = rtrim(explode('/', $host)[0], '.');

        $valid = preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;

        return $valid ? $host : null;
    }

    public function check(Agency $agency): Agency
    {
        $domain = $agency->domain;
        if ($domain === null || $domain === '') {
            $agency->forceFill(['domain_status' => null, 'domain_checked_at' => null, 'domain_error' => null])->save();

            return $agency;
        }

        $platform = self::platformHost();
        $status = self::PENDING_DNS;
        $error = null;

        if (! $this->pointsToPlatform($domain, $platform)) {
            $error = "El dominio todavía no apunta a {$platform}. Crea un registro CNAME de «{$domain}» hacia «{$platform}»; los cambios de DNS pueden tardar hasta unas horas.";
        } elseif (! $this->answersOverHttps($domain)) {
            $status = self::PENDING_SSL;
            $error = 'El DNS ya está bien. Falta activar el certificado de seguridad (HTTPS) en el servidor; lo hacemos nosotros y el dominio se activa solo en cuanto esté.';
        } else {
            $status = self::ACTIVE;
        }

        $agency->forceFill(['domain_status' => $status, 'domain_checked_at' => now(), 'domain_error' => $error])->save();
        Cache::forget(self::cacheKey($domain));

        return $agency;
    }

    public static function cacheKey(string $host): string
    {
        return 'custom-domain:'.$host;
    }

    private function pointsToPlatform(string $domain, string $platform): bool
    {
        if ($platform === '') {
            return false;
        }
        if ($this->dns->cname($domain) === $platform) {
            return true;
        }

        $mine = $this->dns->ips($platform);

        return $mine !== [] && array_intersect($this->dns->ips($domain), $mine) !== [];
    }

    private function answersOverHttps(string $domain): bool
    {
        try {
            $response = Http::timeout(8)->acceptJson()->get("https://{$domain}/api/v1/domain-check");

            return $response->successful() && $response->json('instance') === self::instance();
        } catch (Throwable) {
            return false;
        }
    }
}
