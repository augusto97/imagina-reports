<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\PlatformSetting;

/**
 * Resolves the platform's Google/Meta OAuth app credentials, preferring what the super-admin
 * saved in PlatformSetting (encrypted at rest) and falling back to config/.env. This is the
 * single source of truth for these creds — used by the OAuth clients, the Google Ads
 * connector and the super-admin controller, so the key names live in one place.
 *
 * Secrets are stored via PlatformSetting::putSecret (encrypted); non-secret ids via put.
 */
final class OAuthCredentials
{
    /** Setting keys (also the request field names in the super-admin controller). */
    public const GOOGLE_CLIENT_ID = 'google_oauth_client_id';

    public const GOOGLE_CLIENT_SECRET = 'google_oauth_client_secret';

    public const GOOGLE_ADS_DEVELOPER_TOKEN = 'google_ads_developer_token';

    public const GOOGLE_ADS_LOGIN_CUSTOMER_ID = 'google_ads_login_customer_id';

    public const META_APP_ID = 'meta_oauth_app_id';

    public const META_APP_SECRET = 'meta_oauth_app_secret';

    private ?PlatformSetting $settings = null;

    public function googleClientId(): string
    {
        return $this->plain(self::GOOGLE_CLIENT_ID, 'services.google_oauth.client_id');
    }

    public function googleClientSecret(): string
    {
        return $this->secret(self::GOOGLE_CLIENT_SECRET, 'services.google_oauth.client_secret');
    }

    public function googleAdsDeveloperToken(): string
    {
        return $this->secret(self::GOOGLE_ADS_DEVELOPER_TOKEN, 'services.google_oauth.ads_developer_token');
    }

    public function googleAdsLoginCustomerId(): string
    {
        return $this->plain(self::GOOGLE_ADS_LOGIN_CUSTOMER_ID, 'services.google_oauth.ads_login_customer_id');
    }

    public function metaAppId(): string
    {
        return $this->plain(self::META_APP_ID, 'services.meta_oauth.app_id');
    }

    public function metaAppSecret(): string
    {
        return $this->secret(self::META_APP_SECRET, 'services.meta_oauth.app_secret');
    }

    /** A non-secret id: the panel value, falling back to config. */
    private function plain(string $key, string $configKey): string
    {
        $value = $this->settings()->get($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $this->config($configKey);
    }

    /** A secret: the decrypted panel value, falling back to config. */
    private function secret(string $key, string $configKey): string
    {
        $value = $this->settings()->secret($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $this->config($configKey);
    }

    private function config(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }

    private function settings(): PlatformSetting
    {
        return $this->settings ??= PlatformSetting::current();
    }
}
