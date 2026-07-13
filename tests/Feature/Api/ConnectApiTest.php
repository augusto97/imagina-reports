<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Connectors\Connect\ConnectRegistry;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConnectApiTest extends TestCase
{
    use RefreshDatabase;

    private function site(Agency $agency): Site
    {
        $client = Client::factory()->create(['agency_id' => $agency->id]);

        return Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id]);
    }

    public function test_start_returns_the_woocommerce_authorize_url_and_stores_an_intent(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $site = $this->site($agency);

        $response = $this->postJson("/api/v1/sites/{$site->id}/connect/woocommerce", [
            'input' => ['store_url' => 'https://shop.test'],
            'return_url' => config('app.url').'/panel',
        ])->assertOk();

        $url = $response->json('redirect_url');
        $this->assertStringStartsWith('https://shop.test/wc-auth/v1/authorize?', $url);
        $this->assertStringContainsString('scope=read', $url);
        $this->assertStringContainsString('app_name=Imagina+Reports', $url);
        $this->assertStringContainsString('callback_url='.rawurlencode(route('api.connect.callback', ['type' => 'woocommerce'])), $url);

        // The nonce it round-trips is a live intent in the cache.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertIsArray(Cache::get("connect:intent:{$query['user_id']}"));
    }

    public function test_callback_stores_the_granted_credentials_as_a_source(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $site = $this->site($agency);

        $url = $this->postJson("/api/v1/sites/{$site->id}/connect/woocommerce", [
            'input' => ['store_url' => 'https://shop.test'],
        ])->json('redirect_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $nonce = $query['user_id'];

        // The store POSTs the generated read-only keys to our public callback.
        $this->postJson('/api/v1/connect/callback/woocommerce', [
            'key_id' => 5,
            'user_id' => $nonce,
            'consumer_key' => 'ck_live_123',
            'consumer_secret' => 'cs_live_456',
            'key_permissions' => 'read',
        ])->assertOk()->assertJson(['connected' => true]);

        $source = DataSource::withoutGlobalScopes()->where('site_id', $site->id)->where('type', DataSourceType::WooCommerce->value)->first();
        $this->assertNotNull($source);
        $this->assertSame('https://shop.test', $source->config['store_url']);
        $this->assertSame('ck_live_123', $source->credentials['consumer_key']);
        $this->assertSame('cs_live_456', $source->credentials['consumer_secret']);

        // Single-use: the nonce is consumed, so a replay is rejected.
        $this->assertNull(Cache::get("connect:intent:{$nonce}"));
        $this->postJson('/api/v1/connect/callback/woocommerce', [
            'user_id' => $nonce,
            'consumer_key' => 'ck_x',
            'consumer_secret' => 'cs_x',
            'key_permissions' => 'read',
        ])->assertStatus(422);
    }

    public function test_callback_rejects_an_approval_without_read_permission(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $site = $this->site($agency);

        $url = $this->postJson("/api/v1/sites/{$site->id}/connect/woocommerce", [
            'input' => ['store_url' => 'https://shop.test'],
        ])->json('redirect_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        // A denied/write-only grant must not create a source.
        $this->postJson('/api/v1/connect/callback/woocommerce', [
            'user_id' => $query['user_id'],
            'consumer_key' => '',
            'consumer_secret' => '',
            'key_permissions' => 'write',
        ])->assertStatus(422);

        $this->assertSame(0, DataSource::withoutGlobalScopes()->where('site_id', $site->id)->count());
    }

    public function test_start_is_unavailable_for_a_type_without_a_provider(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $site = $this->site($agency);

        // Google isn't configured in tests → no provider registered → manual form only.
        $this->postJson("/api/v1/sites/{$site->id}/connect/ga4", ['input' => []])->assertNotFound();
    }

    /** Register the Google/Meta providers by configuring their platform OAuth apps. */
    private function enableGoogleOAuth(): void
    {
        config(['services.google_oauth.client_id' => 'cid.apps.googleusercontent.com', 'services.google_oauth.client_secret' => 'secret']);
        $this->app->forgetInstance(ConnectRegistry::class);
    }

    public function test_google_start_returns_the_consent_url_when_configured(): void
    {
        $this->enableGoogleOAuth();
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $site = $this->site($agency);

        $url = $this->postJson("/api/v1/sites/{$site->id}/connect/ga4", [])->assertOk()->json('redirect_url');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('scope='.rawurlencode('https://www.googleapis.com/auth/analytics.readonly'), $url);
        $this->assertStringContainsString('client_id=cid.apps.googleusercontent.com', $url);
    }

    public function test_google_callback_stores_the_refresh_token_and_offers_property_options(): void
    {
        $this->enableGoogleOAuth();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt_durable']),
            'analyticsadmin.googleapis.com/*' => Http::response([
                'accountSummaries' => [[
                    'displayName' => 'Acme',
                    'propertySummaries' => [
                        ['property' => 'properties/111', 'displayName' => 'Acme Web'],
                        ['property' => 'properties/222', 'displayName' => 'Acme Blog'],
                    ],
                ]],
            ]),
        ]);

        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $site = $this->site($agency);

        $url = $this->postJson("/api/v1/sites/{$site->id}/connect/ga4", [])->json('redirect_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        // Google redirects the browser back with code + state → we store the refresh token.
        $this->get("/api/v1/connect/callback/ga4?code=auth_code&state={$query['state']}")
            ->assertRedirect();

        $source = DataSource::withoutGlobalScopes()->where('site_id', $site->id)->where('type', DataSourceType::Ga4->value)->first();
        $this->assertNotNull($source);
        $this->assertSame('rt_durable', $source->credentials['oauth_refresh_token']);

        // Two properties → the picker options are stashed for the client to choose.
        $this->assertSame('property_id', $source->meta['connect_options']['field']);
        $this->assertCount(2, $source->meta['connect_options']['options']);
        $this->assertSame('111', $source->meta['connect_options']['options'][0]['value']);
    }

    public function test_google_callback_auto_selects_a_single_property(): void
    {
        $this->enableGoogleOAuth();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt']),
            'analyticsadmin.googleapis.com/*' => Http::response([
                'accountSummaries' => [['displayName' => 'Solo', 'propertySummaries' => [['property' => 'properties/999', 'displayName' => 'Only']]]],
            ]),
        ]);

        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $site = $this->site($agency);

        $url = $this->postJson("/api/v1/sites/{$site->id}/connect/ga4", [])->json('redirect_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->get("/api/v1/connect/callback/ga4?code=c&state={$query['state']}")->assertRedirect();

        $source = DataSource::withoutGlobalScopes()->where('site_id', $site->id)->first();
        $this->assertNotNull($source);
        // Exactly one property → auto-filled, no picker needed.
        $this->assertSame('999', $source->config['property_id']);
        $this->assertNull($source->meta['connect_options'] ?? null);
    }

    public function test_google_callback_redirects_with_an_error_when_the_user_denies(): void
    {
        $this->enableGoogleOAuth();
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $site = $this->site($agency);

        $url = $this->postJson("/api/v1/sites/{$site->id}/connect/ga4", ['return_url' => config('app.url').'/panel'])->json('redirect_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        // Denial: Google redirects back with error + state, no code → bounce to the app with a flag.
        $response = $this->get("/api/v1/connect/callback/ga4?error=access_denied&state={$query['state']}");
        $response->assertRedirect();
        $this->assertStringContainsString('connect_error=', (string) $response->headers->get('Location'));
        $this->assertSame(0, DataSource::withoutGlobalScopes()->where('site_id', $site->id)->count());
    }
}
