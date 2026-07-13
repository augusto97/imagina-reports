<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        $this->postJson("/api/v1/sites/{$site->id}/connect/ga4", ['input' => []])->assertNotFound();
    }
}
