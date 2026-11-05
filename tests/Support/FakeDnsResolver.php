<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Domains\DnsResolver;

/** In-memory DNS for the custom-domain tests. */
final class FakeDnsResolver implements DnsResolver
{
    /**
     * @param  array<string, string>  $cnames
     * @param  array<string, list<string>>  $ips
     */
    public function __construct(public array $cnames = [], public array $ips = []) {}

    public function cname(string $host): ?string
    {
        return $this->cnames[$host] ?? null;
    }

    public function ips(string $host): array
    {
        return $this->ips[$host] ?? [];
    }
}
