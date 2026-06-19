<?php

declare(strict_types=1);

namespace App\Connectors\Google;

use Google\Auth\Credentials\ServiceAccountCredentials;

/**
 * Default token provider: uses google/auth (a google/apiclient dependency) to mint
 * an access token from the service-account JSON for the requested scope.
 */
final class ServiceAccountTokenProvider implements GoogleTokenProvider
{
    public function accessToken(array $serviceAccount, string $scope): string
    {
        $credentials = new ServiceAccountCredentials($scope, $serviceAccount);

        $token = $credentials->fetchAuthToken();

        $accessToken = $token['access_token'] ?? null;

        return is_string($accessToken) ? $accessToken : '';
    }
}
