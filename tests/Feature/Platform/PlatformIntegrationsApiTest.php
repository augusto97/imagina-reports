<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Platform\OAuthCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformIntegrationsApiTest extends TestCase
{
    use RefreshDatabase;

    private function actAsPlatformAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => null, 'is_platform_admin' => true, 'role' => UserRole::Owner]));
    }

    public function test_it_saves_google_credentials_encrypted_and_reports_ready(): void
    {
        $this->actAsPlatformAdmin();

        $response = $this->putJson('/api/v1/platform/integrations', [
            'google_oauth_client_id' => 'cid.apps.googleusercontent.com',
            'google_oauth_client_secret' => 'GOCSPX-secret',
        ])->assertOk();

        $response->assertJson([
            'google_oauth_client_id' => 'cid.apps.googleusercontent.com',
            'google_oauth_client_secret_set' => true,
            'google_connect_ready' => true,
            'google_from_env' => false,
        ]);

        // The secret is encrypted at rest (not stored in plaintext) but decrypts back.
        $settings = PlatformSetting::current();
        $this->assertNotSame('GOCSPX-secret', ($settings->settings ?? [])[OAuthCredentials::GOOGLE_CLIENT_SECRET] ?? null);
        $this->assertSame('GOCSPX-secret', (new OAuthCredentials)->googleClientSecret());
    }

    public function test_a_blank_secret_keeps_the_existing_one(): void
    {
        $this->actAsPlatformAdmin();
        $settings = PlatformSetting::current();
        $settings->putSecret(OAuthCredentials::META_APP_SECRET, 'keep-me');
        $settings->put(OAuthCredentials::META_APP_ID, '123');
        $settings->save();

        // Update the app id only, leaving the secret field absent → secret preserved.
        $this->putJson('/api/v1/platform/integrations', ['meta_oauth_app_id' => '456'])->assertOk();

        $this->assertSame('keep-me', (new OAuthCredentials)->metaAppSecret());
        $this->assertSame('456', (new OAuthCredentials)->metaAppId());
    }

    public function test_panel_value_overrides_the_env_fallback(): void
    {
        config(['services.google_oauth.client_id' => 'env-id', 'services.google_oauth.client_secret' => 'env-secret']);
        $this->actAsPlatformAdmin();

        // With nothing in the panel, the effective creds come from .env.
        $this->getJson('/api/v1/platform/integrations')->assertOk()->assertJson([
            'google_connect_ready' => true,
            'google_from_env' => true,
            'google_oauth_client_id' => '', // panel value only; the env id isn't leaked here
        ]);

        // Saving a panel value takes precedence.
        $this->putJson('/api/v1/platform/integrations', ['google_oauth_client_id' => 'panel-id', 'google_oauth_client_secret' => 'panel-secret'])->assertOk();
        $this->assertSame('panel-id', (new OAuthCredentials)->googleClientId());
    }

    public function test_it_is_platform_admin_only(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));

        $this->getJson('/api/v1/platform/integrations')->assertForbidden();
    }
}
