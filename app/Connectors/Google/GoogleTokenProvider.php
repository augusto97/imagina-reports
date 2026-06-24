<?php

declare(strict_types=1);

namespace App\Connectors\Google;

/**
 * Exchanges a Google service-account JSON for a short-lived OAuth2 access token
 * for a given API scope. Shared by the GA4 and GSC connectors and abstracted so
 * tests can stub it without any network access (CLAUDE.md §14).
 */
interface GoogleTokenProvider
{
    /**
     * @param  array<array-key, mixed>  $serviceAccount  Decoded service-account JSON.
     */
    public function accessToken(array $serviceAccount, string $scope): string;
}
