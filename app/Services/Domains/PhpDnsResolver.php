<?php

declare(strict_types=1);

namespace App\Services\Domains;

final class PhpDnsResolver implements DnsResolver
{
    public function cname(string $host): ?string
    {
        $records = @dns_get_record($host, DNS_CNAME);
        if (! is_array($records)) {
            return null;
        }
        foreach ($records as $record) {
            $target = $record['target'] ?? null;
            if (is_string($target) && $target !== '') {
                return strtolower(rtrim($target, '.'));
            }
        }

        return null;
    }

    public function ips(string $host): array
    {
        $ips = @gethostbynamel($host);

        return is_array($ips) ? array_values(array_unique($ips)) : [];
    }
}
