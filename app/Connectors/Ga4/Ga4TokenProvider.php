<?php

declare(strict_types=1);

namespace App\Connectors\Ga4;

/**
 * Exchanges a Google service-account JSON for a short-lived OAuth2 access token
 * scoped to the Analytics Data API. Abstracted so tests can stub it without any
 * network access (CLAUDE.md §14).
 */
interface Ga4TokenProvider
{
    /**
     * @param  array<string, mixed>  $serviceAccount  Decoded service-account JSON.
     */
    public function accessToken(array $serviceAccount): string;
}
