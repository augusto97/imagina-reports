<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AnthropicAiClient;
use App\Models\Agency;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
