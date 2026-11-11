<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Connectors\Connect\ConnectRegistry;
use App\Enums\DataSourceType;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ConnectInvite;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConnectInviteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google_oauth.client_id' => 'cid.apps.googleusercontent.com', 'services.google_oauth.client_secret' => 'secret']);
        $this->app->forgetInstance(ConnectRegistry::class);
    }

    private function site(Agency $agency): Site
    {
        $client = Client::factory()->create(['agency_id' => $agency->id, 'name' => 'Panadería Sol']);

        return Site::factory()->create(['agency_id' => $agency->id, 'client_id' => $client->id, 'name' => 'panaderiasol.com']);
    }

    /**
     * @param  list<string>  $types
     * @return array{0: Site, 1: string}
     */
    private function invite(array $types = ['ga4', 'woocommerce'], int $days = 7): array
    {
        $agency = Agency::factory()->create(['name' => 'Imagina WP']);
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Collaborator]));
        $site = $this->site($agency);

        $url = $this->postJson("/api/v1/sites/{$site->id}/connect-invites", ['types' => $types, 'expires_days' => $days])
            ->assertCreated()
            ->json('data.url');

        return [$site, (string) substr((string) $url, strrpos((string) $url, '/') + 1)];
    }

    public function test_an_agency_member_creates_lists_and_revokes_a_link(): void
    {
        [$site, $token] = $this->invite();

        // Only the hash is searchable; the token itself is stored encrypted.
        $invite = ConnectInvite::query()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $invite->token_hash);
        $this->assertNotSame($token, $invite->getRawOriginal('token'));

        $this->getJson("/api/v1/sites/{$site->id}/connect-invites")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.types', ['ga4', 'woocommerce'])
            ->assertJsonMissingPath('data.0.token_hash');

        $this->deleteJson("/api/v1/connect-invites/{$invite->id}")->assertOk();
        $this->getJson("/api/v1/sites/{$site->id}/connect-invites")->assertJsonCount(0, 'data');
        $this->get("/connect/{$token}")->assertNotFound();

        $this->assertTrue(AuditLog::query()->where('action', 'connect_invite.created')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'connect_invite.revoked')->exists());
    }

    public function test_only_one_click_sources_can_be_requested(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));
        $site = $this->site($agency);

        $this->postJson("/api/v1/sites/{$site->id}/connect-invites", ['types' => ['cloudflare'], 'expires_days' => 7])
            ->assertUnprocessable()->assertJsonValidationErrors('types.0');
        $this->postJson("/api/v1/sites/{$site->id}/connect-invites", ['types' => ['ga4'], 'expires_days' => 365])
            ->assertUnprocessable()->assertJsonValidationErrors('expires_days');
    }

    public function test_another_agency_cannot_see_or_revoke_the_link(): void
    {
        [$site] = $this->invite();
        $invite = ConnectInvite::query()->withoutGlobalScopes()->firstOrFail();

        $other = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $other->id]));

        $this->getJson("/api/v1/sites/{$site->id}/connect-invites")->assertNotFound();
        $this->deleteJson("/api/v1/connect-invites/{$invite->id}")->assertNotFound();
        $this->assertNull($invite->fresh()?->revoked_at);
    }

    public function test_the_public_page_shows_the_brand_site_and_requested_sources_without_login(): void
    {
        [, $token] = $this->invite();
        $this->app['auth']->forgetGuards();

        $this->get("/connect/{$token}")
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Imagina WP')
            ->assertSee('panaderiasol.com')
            ->assertSee('Google Analytics 4')
            ->assertSee('URL de la tienda')
            ->assertDontSee('Cloudflare');
    }

    public function test_an_expired_or_unknown_link_is_rejected(): void
    {
        [, $token] = $this->invite();
        ConnectInvite::query()->withoutGlobalScopes()->update(['expires_at' => now()->subMinute()]);

        $this->get("/connect/{$token}")->assertNotFound()->assertSee('ya no está disponible');
        $this->post("/connect/{$token}/start/ga4")->assertNotFound();
        $this->get('/connect/'.str_repeat('x', 48))->assertNotFound();
    }

    public function test_a_type_outside_the_link_cannot_be_started(): void
    {
        [, $token] = $this->invite(['woocommerce']);

        $this->post("/connect/{$token}/start/ga4")->assertNotFound();
    }

    public function test_the_client_connects_their_own_google_account_and_picks_the_property(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt_client']),
            'analyticsadmin.googleapis.com/*' => Http::response([
                'accountSummaries' => [[
                    'displayName' => 'Sol',
                    'propertySummaries' => [
                        ['property' => 'properties/111', 'displayName' => 'Sol Web'],
                        ['property' => 'properties/222', 'displayName' => 'Sol Tienda'],
                    ],
                ]],
            ]),
        ]);
        [$site, $token] = $this->invite();
        $this->app['auth']->forgetGuards();

        // The page sends the browser to Google; the intent returns to the same link.
        $redirect = $this->post("/connect/{$token}/start/ga4")->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', (string) $redirect);
        parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);

        $back = $this->get("/api/v1/connect/callback/ga4?code=c&state={$query['state']}")->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(url("/connect/{$token}").'?connected=ga4', (string) $back);

        $source = DataSource::withoutGlobalScopes()->where('site_id', $site->id)->where('type', DataSourceType::Ga4->value)->firstOrFail();
        $this->assertSame('rt_client', $source->credentials['oauth_refresh_token']);
        $this->assertSame($site->agency_id, $source->agency_id);
        $this->assertTrue(AuditLog::query()->where('action', 'connect_invite.used')->exists());
        // Still a property to pick: the team hears about it once it's complete, not before.
        $this->assertSame(0, AppNotification::query()->withoutGlobalScopes()->count());
        $this->assertNotNull(ConnectInvite::query()->withoutGlobalScopes()->firstOrFail()->last_used_at);

        // Two properties → the page asks the client to choose; only listed values are accepted.
        $this->get("/connect/{$token}?connected=ga4")->assertOk()->assertSee('Sol Tienda')->assertSee('elige cuál usar');
        $this->post("/connect/{$token}/select/{$source->id}", ['value' => '999'])->assertRedirect();
        $this->assertArrayNotHasKey('property_id', $source->fresh()?->config ?? []);

        $this->post("/connect/{$token}/select/{$source->id}", ['value' => '222'])->assertRedirect();
        $fresh = $source->fresh();
        $this->assertSame('222', $fresh?->config['property_id']);
        $this->assertSame(
            ['El cliente conectó Google Analytics 4 en panaderiasol.com'],
            AppNotification::query()->withoutGlobalScopes()->pluck('title')->all(),
        );
        $this->assertArrayNotHasKey('connect_options', $fresh->meta ?? []);
        $this->get("/connect/{$token}")->assertSee('Conectado');
    }

    public function test_a_link_cannot_touch_another_sites_source(): void
    {
        [, $token] = $this->invite();
        $otherAgency = Agency::factory()->create();
        $foreign = DataSource::factory()->create([
            'agency_id' => $otherAgency->id,
            'site_id' => $this->site($otherAgency)->id,
            'type' => DataSourceType::Ga4,
            'meta' => ['connect_options' => ['field' => 'property_id', 'label' => 'Propiedad', 'options' => [['value' => '1', 'label' => 'X']]]],
        ]);

        $this->post("/connect/{$token}/select/{$foreign->id}", ['value' => '1'])->assertNotFound();
        $this->assertArrayNotHasKey('property_id', $foreign->fresh()?->config ?? []);
    }

    public function test_woocommerce_needs_the_store_url_first(): void
    {
        [, $token] = $this->invite(['woocommerce']);

        $this->from("/connect/{$token}")->post("/connect/{$token}/start/woocommerce", [])
            ->assertRedirect("/connect/{$token}")
            ->assertSessionHasErrors('input.store_url');

        $redirect = $this->post("/connect/{$token}/start/woocommerce", ['input' => ['store_url' => 'https://panaderiasol.com']])
            ->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://panaderiasol.com/wc-auth/v1/authorize?', (string) $redirect);
        $this->assertStringContainsString('return_url='.rawurlencode(url("/connect/{$token}")), (string) $redirect);
    }
}
