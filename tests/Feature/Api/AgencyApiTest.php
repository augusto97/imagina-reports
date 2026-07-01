<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AnthropicAiClient;
use App\Jobs\SendWebhookJob;
use App\Models\Agency;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgencyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_reports_settings_without_exposing_the_key(): void
    {
        $agency = Agency::factory()->create(['brand_color' => '#101828']);
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $this->getJson('/api/v1/agency')
            ->assertOk()
            ->assertJsonPath('brand_color', '#101828')
            ->assertJsonPath('ai_key_set', false);
    }

    public function test_update_saves_branding_and_stores_the_key_encrypted(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $this->putJson('/api/v1/agency', [
            'name' => 'Imagina WP',
            'brand_color' => '#5b21b6',
            'default_locale' => 'en',
            'anthropic_key' => 'sk-ant-secret',
        ])
            ->assertOk()
            ->assertJsonPath('brand_color', '#5b21b6')
            ->assertJsonPath('default_locale', 'en')
            ->assertJsonPath('ai_key_set', true)
            ->assertJsonMissing(['anthropic_key' => 'sk-ant-secret']);

        $fresh = $agency->fresh();
        $this->assertSame('sk-ant-secret', $fresh?->anthropicKey());
        // Stored ciphertext, never plaintext.
        $this->assertNotSame('sk-ant-secret', $fresh?->settings['anthropic_key'] ?? null);
    }

    public function test_logo_upload_stores_the_file_and_returns_its_url(): void
    {
        Storage::fake('public');

        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $response = $this->postJson('/api/v1/agency/logo', [
            'logo' => UploadedFile::fake()->image('brand.png', 200, 80),
        ])->assertOk();

        $this->assertNotNull($response->json('logo_url'));
        $path = $agency->fresh()?->logo_path;
        $this->assertIsString($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_update_stores_webhook_urls_and_secret(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $this->putJson('/api/v1/agency', [
            'name' => 'Imagina WP',
            'webhook_urls' => ['https://hook.test/a', '', 'https://hook.test/b'],
            'webhook_secret' => 'shh',
        ])
            ->assertOk()
            ->assertJsonPath('webhook_urls', ['https://hook.test/a', 'https://hook.test/b'])
            ->assertJsonPath('webhook_secret_set', true)
            ->assertJsonMissing(['webhook_secret' => 'shh']);

        $this->assertSame('shh', $agency->fresh()?->settings['webhook_secret'] ?? null);
    }

    public function test_update_rejects_an_invalid_webhook_url(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $this->putJson('/api/v1/agency', ['name' => 'X', 'webhook_urls' => ['not-a-url']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('webhook_urls.0');
    }

    public function test_test_webhooks_endpoint_reports_no_endpoints(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $this->postJson('/api/v1/agency/webhooks/test')->assertStatus(422)->assertJsonPath('sent', 0);
    }

    public function test_test_webhooks_endpoint_dispatches_to_configured_endpoints(): void
    {
        Queue::fake();
        $agency = Agency::factory()->create(['settings' => ['webhook_urls' => ['https://hook.test/a']]]);
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $this->postJson('/api/v1/agency/webhooks/test')->assertOk()->assertJsonPath('sent', 1);

        Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->event === 'ping' && $job->url === 'https://hook.test/a');
    }

    public function test_logo_upload_rejects_a_non_image(): void
    {
        Storage::fake('public');

        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id]));

        $this->postJson('/api/v1/agency/logo', [
            'logo' => UploadedFile::fake()->create('malware.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('logo');
    }

    public function test_ai_client_prefers_the_agency_key_over_config(): void
    {
        config(['services.anthropic.key' => 'sk-config-fallback']);

        $agency = Agency::factory()->create();
        $agency->setAnthropicKey('sk-agency-key');
        $agency->save();

        app(TenantContext::class)->set($agency->id);

        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['text' => 'ok']]])]);

        app(AnthropicAiClient::class)->complete('system', 'prompt');

        Http::assertSent(fn (Request $request): bool => $request->header('x-api-key')[0] === 'sk-agency-key');
    }
}
