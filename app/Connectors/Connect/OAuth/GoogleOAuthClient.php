<?php

declare(strict_types=1);

namespace App\Connectors\Connect\OAuth;

use Illuminate\Support\Facades\Http;

/**
 * Google OAuth 2.0 helper for the one-click "Connect with Google" flow (GA4, Search
 * Console, Google Ads). Builds the consent URL, exchanges the returned code for a
 * long-lived refresh token, and mints short-lived access tokens from it for the
 * connectors. The OAuth app's client_id/secret are platform-level (services.google_oauth),
 * configured once by the operator — never per client.
 */
final class GoogleOAuthClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /**
     * The consent screen URL. `access_type=offline` + `prompt=consent` force a refresh
     * token back even on re-authorization, so we can keep syncing without the client.
     *
     * @param  list<string>  $scopes
     */
    public function authorizeUrl(array $scopes, string $redirectUri, string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return self::AUTH_URL.'?'.$query;
    }

    /**
     * Exchange the authorization code for tokens. Returns the refresh token (the durable
     * credential we store) or null if Google didn't return one.
     */
    public function exchangeCode(string $code, string $redirectUri): ?string
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed()) {
            return null;
        }

        $refresh = $response->json('refresh_token');

        return is_string($refresh) && $refresh !== '' ? $refresh : null;
    }

    /** Mint a short-lived access token from a stored refresh token (used by the connectors). */
    public function accessTokenFromRefresh(string $refreshToken): ?string
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            return null;
        }

        $token = $response->json('access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function clientId(): string
    {
        $value = config('services.google_oauth.client_id');

        return is_string($value) ? $value : '';
    }

    private function clientSecret(): string
    {
        $value = config('services.google_oauth.client_secret');

        return is_string($value) ? $value : '';
    }
}
