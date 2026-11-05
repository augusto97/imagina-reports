<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Which agency's own domain a request came in through (set by ResolveCustomDomain). Public
 * pages use it so reportes.agencia-a.com can only ever show agency A's reports.
 */
final class CustomDomain
{
    public const ATTRIBUTE = 'custom_domain_agency_id';

    public static function agencyId(Request $request): ?int
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        return is_int($id) ? $id : null;
    }

    /** True when the request arrived on ANOTHER agency's domain than the one owning the content. */
    public static function blocks(Request $request, int $agencyId): bool
    {
        $domainAgency = self::agencyId($request);

        return $domainAgency !== null && $domainAgency !== $agencyId;
    }
}
