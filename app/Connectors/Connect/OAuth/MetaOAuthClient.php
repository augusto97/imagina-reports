<?php

declare(strict_types=1);

namespace App\Connectors\Connect\OAuth;

use App\Services\Platform\OAuthCredentials;
use Illuminate\Support\Facades\Http;

/**
 * Meta (Facebook) OAuth helper for one-click "Connect with Facebook" (Facebook/Instagram
 * Ads). Builds the login-dialog URL and exchanges the returned code for a long-lived user
 * access token (~60 days) we store as the source credential. The app_id/app_secret are
 * platform-level (super-admin panel, falling back to services.meta_oauth).
 */
final class MetaOAuthClient
{
    private const GRAPH_VERSION = 'v21.0';

    private const DIALOG_URL = 'https://www.facebook.com/v21.0/dialog/oauth';

    public function __construct(private readonly OAuthCredentials $credentials = new OAuthCredentials) {}

    public function isConfigured(): bool
    {
        return $this->appId() !== '' && $this->appSecret() !== '';
    }

    /**
     * @param  list<string>  $scopes  The read-only permissions to request (each subject to
     *                                Meta App Review), e.g. ['ads_read'] or the Instagram set.
     */
    public function authorizeUrl(array $scopes, string $redirectUri, string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(',', $scopes),
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
        return $this->credentials->metaAppId();
    }

    private function appSecret(): string
    {
        return $this->credentials->metaAppSecret();
    }
}
