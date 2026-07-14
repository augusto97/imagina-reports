<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\Platform\OAuthCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform OAuth app credentials for the one-click connectors (Google + Meta). Stored in
 * PlatformSetting (secrets encrypted, never returned in plaintext); non-secret ids are
 * echoed so the operator can see/edit them. Values fall back to config/.env when unset here
 * (a boolean flags when the fallback is active). Platform-admin only (route middleware).
 */
final class PlatformIntegrationsController extends Controller
{
    /** The plain (non-secret) id fields, echoed back to the panel. */
    private const PLAIN = [OAuthCredentials::GOOGLE_CLIENT_ID, OAuthCredentials::GOOGLE_ADS_LOGIN_CUSTOMER_ID, OAuthCredentials::META_APP_ID];

    /** The secret fields, only ever reported as "configured" booleans. */
    private const SECRETS = [OAuthCredentials::GOOGLE_CLIENT_SECRET, OAuthCredentials::GOOGLE_ADS_DEVELOPER_TOKEN, OAuthCredentials::META_APP_SECRET];

    public function show(): JsonResponse
    {
        return response()->json($this->present(PlatformSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            OAuthCredentials::GOOGLE_CLIENT_ID => ['sometimes', 'nullable', 'string'],
            OAuthCredentials::GOOGLE_CLIENT_SECRET => ['sometimes', 'nullable', 'string'],
            OAuthCredentials::GOOGLE_ADS_DEVELOPER_TOKEN => ['sometimes', 'nullable', 'string'],
            OAuthCredentials::GOOGLE_ADS_LOGIN_CUSTOMER_ID => ['sometimes', 'nullable', 'string'],
            OAuthCredentials::META_APP_ID => ['sometimes', 'nullable', 'string'],
            OAuthCredentials::META_APP_SECRET => ['sometimes', 'nullable', 'string'],
        ]);

        $settings = PlatformSetting::current();

        foreach (self::PLAIN as $key) {
            if ($request->has($key)) {
                $value = $request->input($key);
                $settings->put($key, is_string($value) ? trim($value) : '');
            }
        }

        // A present secret sets (or clears, when blank) it; absent leaves it untouched.
        foreach (self::SECRETS as $key) {
            if ($request->has($key)) {
                $value = $request->input($key);
                $settings->putSecret($key, is_string($value) ? $value : null);
            }
        }

        $settings->save();

        return response()->json($this->present($settings));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PlatformSetting $settings): array
    {
        $googleConfigured = $this->effective($settings, OAuthCredentials::GOOGLE_CLIENT_ID, false) !== ''
            && $this->effective($settings, OAuthCredentials::GOOGLE_CLIENT_SECRET, true) !== '';
        $metaConfigured = $this->effective($settings, OAuthCredentials::META_APP_ID, false) !== ''
            && $this->effective($settings, OAuthCredentials::META_APP_SECRET, true) !== '';

        return [
            // Non-secret ids the operator can see/edit (panel value only, not the env fallback).
            'google_oauth_client_id' => $this->plain($settings, OAuthCredentials::GOOGLE_CLIENT_ID),
            'google_ads_login_customer_id' => $this->plain($settings, OAuthCredentials::GOOGLE_ADS_LOGIN_CUSTOMER_ID),
            'meta_oauth_app_id' => $this->plain($settings, OAuthCredentials::META_APP_ID),
            // Secrets: only whether they're saved in the panel.
            'google_oauth_client_secret_set' => $settings->hasSecret(OAuthCredentials::GOOGLE_CLIENT_SECRET),
            'google_ads_developer_token_set' => $settings->hasSecret(OAuthCredentials::GOOGLE_ADS_DEVELOPER_TOKEN),
            'meta_oauth_app_secret_set' => $settings->hasSecret(OAuthCredentials::META_APP_SECRET),
            // Whether the connect button will actually show (panel OR .env provides the pair).
            'google_connect_ready' => $googleConfigured,
            'meta_connect_ready' => $metaConfigured,
            // Whether the effective value currently comes from .env (so the panel can say so).
            'google_from_env' => $googleConfigured && ! ($settings->get(OAuthCredentials::GOOGLE_CLIENT_ID) && $settings->hasSecret(OAuthCredentials::GOOGLE_CLIENT_SECRET)),
            'meta_from_env' => $metaConfigured && ! ($settings->get(OAuthCredentials::META_APP_ID) && $settings->hasSecret(OAuthCredentials::META_APP_SECRET)),
        ];
    }

    private function plain(PlatformSetting $settings, string $key): string
    {
        $value = $settings->get($key);

        return is_string($value) ? $value : '';
    }

    private function effective(PlatformSetting $settings, string $key, bool $isSecret): string
    {
        $panel = $isSecret ? $settings->secret($key) : $settings->get($key);
        if (is_string($panel) && $panel !== '') {
            return $panel;
        }

        // Mirror OAuthCredentials' config fallback keys.
        $map = [
            OAuthCredentials::GOOGLE_CLIENT_ID => 'services.google_oauth.client_id',
            OAuthCredentials::GOOGLE_CLIENT_SECRET => 'services.google_oauth.client_secret',
            OAuthCredentials::META_APP_ID => 'services.meta_oauth.app_id',
            OAuthCredentials::META_APP_SECRET => 'services.meta_oauth.app_secret',
        ];
        $config = isset($map[$key]) ? config($map[$key]) : null;

        return is_string($config) ? $config : '';
    }
}
