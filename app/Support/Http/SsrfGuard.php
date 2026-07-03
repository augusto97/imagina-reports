<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * Blocks Server-Side Request Forgery: connectors and webhooks fetch agency-supplied URLs
 * server-side, so an agency could point one at the cloud metadata endpoint (169.254.169.254),
 * loopback, or a private-network service and read the result back. This guard blocks a target
 * only when it POSITIVELY resolves to a private/reserved/loopback address — anything else
 * (public host, unresolvable name, unparseable URL) is allowed so legitimate traffic to
 * clients' public sites and vendor APIs is never affected.
 */
final class SsrfGuard
{
    /** True when the URL's host is (or resolves to) a private/reserved/loopback address. */
    public static function isBlockedUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' && self::isBlockedHost($host);
    }

    /** Same check for a bare host or IP (e.g. a database connector's host field). */
    public static function isBlockedHost(string $host): bool
    {
        $host = trim($host, " \t[]"); // strip brackets from [::1]-style IPv6 hosts

        if ($host === '') {
            return false;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);

        foreach ($ips as $ip) {
            if (self::isPrivateOrReserved($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                $ips[] = (string) $ip;
            }
        }

        $records = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($records as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    private static function isPrivateOrReserved(string $ip): bool
    {
        // filter_var returns the IP when it is a PUBLIC address; false when it falls in a
        // private (RFC1918/RFC4193) or reserved (loopback, link-local incl. 169.254/16,
        // multicast, …) range. So "not public" ⇒ block.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
