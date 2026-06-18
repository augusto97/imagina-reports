<?php

declare(strict_types=1);

namespace App\Connectors\Ga4;

use Google\Auth\Credentials\ServiceAccountCredentials;

/**
 * Default token provider: uses google/auth (a google/apiclient dependency) to mint
 * an Analytics-read access token from the service-account JSON.
 */
final class GoogleServiceAccountTokenProvider implements Ga4TokenProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    public function accessToken(array $serviceAccount): string
    {
        $credentials = new ServiceAccountCredentials(self::SCOPE, $serviceAccount);

        $token = $credentials->fetchAuthToken();

        $accessToken = $token['access_token'] ?? null;

        return is_string($accessToken) ? $accessToken : '';
    }
}
