<?php

declare(strict_types=1);

namespace App\Services\Domains;

/** DNS lookups for the custom-domain check (an interface so tests never hit real DNS). */
interface DnsResolver
{
    /** The CNAME target of a host, lowercase and without the trailing dot, or null. */
    public function cname(string $host): ?string;

    /**
     * The IPv4 addresses a host resolves to.
     *
     * @return list<string>
     */
    public function ips(string $host): array;
}
