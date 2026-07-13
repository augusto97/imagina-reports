<?php

declare(strict_types=1);

namespace App\Connectors\Connect\OAuth;

use Illuminate\Support\Facades\Http;

/**
 * Meta (Facebook) OAuth helper for one-click "Connect with Facebook" (Facebook/Instagram
 * Ads). Builds the login-dialog URL and exchanges the returned code for a long-lived user
 * access token (~60 days) we store as the source credential. The app_id/app_secret are
 * platform-level (services.meta_oauth), configured once by the operator.
 */
final class MetaOAuthClient
{
    private const GRAPH_VERSION = 'v21.0';

    private const DIALOG_URL = 'https://www.facebook.com/v21.0/dialog/oauth';

    /** Read-only ads access — the scope that requires Meta App Review. */
    private const SCOPE = 'ads_read';

    public function isConfigured(): bool
    {
        return $this->appId() !== '' && $this->appSecret() !== '';
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $state,
        ]);

        return self::DIALOG_URL.'?'.$query;
    }

    /**
     * Exchange the code for a token, then upgrade it to a long-lived token so it survives
     * ~60 days instead of ~1-2 hours. Returns the long-lived token, or null on failure.
     */
    public function exchangeCode(string $code, string $redirectUri): ?string
    {
        $short = Http::get('https://graph.facebook.com/'.self::GRAPH_VERSION.'/oauth/access_token', [
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        $shortToken = $short->successful() ? $short->json('access_token') : null;

        if (! is_string($shortToken) || $shortToken === '') {
            return null;
        }

        // Exchange the short-lived token for a long-lived one (best effort — fall back to
        // the short one if the upgrade call fails, so the connection still works short-term).
        $long = Http::get('https://graph.facebook.com/'.self::GRAPH_VERSION.'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'fb_exchange_token' => $shortToken,
        ]);

        $longToken = $long->successful() ? $long->json('access_token') : null;

        return is_string($longToken) && $longToken !== '' ? $longToken : $shortToken;
    }

    private function appId(): string
    {
        $value = config('services.meta_oauth.app_id');

        return is_string($value) ? $value : '';
    }

    private function appSecret(): string
    {
        $value = config('services.meta_oauth.app_secret');

        return is_string($value) ? $value : '';
    }
}
