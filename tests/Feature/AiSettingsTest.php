<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ai\AiModels;
use App\Ai\AiSettings;
use App\Ai\AnthropicAiClient;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Which Claude key + model each call uses (agency's own vs the platform's), the request shape
 * per model, reading text past thinking blocks, and «Probar» in both panels.
 */
class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function answer(string $text = 'Hola'): PromiseInterface
    {
        return Http::response([
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'x'],
                ['type' => 'text', 'text' => $text],
            ],
        ]);
    }

    private function platform(?string $key, ?string $model = null): void
    {
        $settings = PlatformSetting::current();
        $settings->putSecret(AiSettings::PLATFORM_KEY, $key);
        $settings->put(AiSettings::PLATFORM_MODEL, $model);
        $settings->save();
    }

    public function test_an_agency_with_its_own_key_uses_its_own_model(): void
    {
        $this->platform('sk-platform', 'claude-haiku-5-5');
        $agency = Agency::factory()->create(['settings' => [AiSettings::AGENCY_MODEL => 'claude-sonnet-5-5']]);
        $agency->setAnthropicKey('sk-agency');
        $agency->save();
        app(TenantContext::class)->set($agency->id);
        Http::fake(['api.anthropic.com/*' => $this->answer('Resumen')]);

        $text = app(AnthropicAiClient::class)->complete('system', 'prompt');

        $this->assertSame('Resumen', $text, 'the text after the thinking block');
        Http::assertSent(fn (Request $request): bool => $request->header('x-api-key')[0] === 'sk-agency'
            && $request['model'] === 'claude-sonnet-5-5'
            && $request['output_config'] === ['effort' => 'medium']
            && $request['fallbacks'] === 'default'
            && $request->header('anthropic-beta')[0] === AiModels::FALLBACK_BETA);
    }

    public function test_without_its_own_key_the_platform_key_and_model_apply(): void
    {
        $this->platform('sk-platform', 'claude-haiku-5-5');
        // A model choice without its own key is ignored: the platform pays for those calls.
        $agency = Agency::factory()->create(['settings' => [AiSettings::AGENCY_MODEL => 'claude-opus-5-5']]);
        app(TenantContext::class)->set($agency->id);
        Http::fake(['api.anthropic.com/*' => $this->answer()]);

        app(AnthropicAiClient::class)->complete('system', 'prompt');

        // Haiku 5.5 has no server-side fallback: no beta header, no `fallbacks`.
        Http::assertSent(fn (Request $request): bool => $request->header('x-api-key')[0] === 'sk-platform'
            && $request['model'] === 'claude-haiku-5-5'
            && ! isset($request['fallbacks'])
            && $request->header('anthropic-beta') === []);
    }

    public function test_the_effort_level_is_the_agencys_with_its_own_key_or_the_platforms(): void
    {
        $this->platform('sk-platform', 'claude-opus-5-5');
        $settings = PlatformSetting::current();
        $settings->put(AiSettings::PLATFORM_EFFORT, 'low');
        $settings->save();

        $agency = Agency::factory()->create(['settings' => [AiSettings::AGENCY_EFFORT => 'high']]);
        app(TenantContext::class)->set($agency->id);
        Http::fake(['api.anthropic.com/*' => $this->answer()]);

        // Without its own key the agency's choice is ignored: the platform's level applies.
        app(AnthropicAiClient::class)->complete('s', 'p');
        Http::assertSent(fn (Request $request): bool => $request['output_config'] === ['effort' => 'low']);

        $agency->setAnthropicKey('sk-agency');
        $agency->save();
        app(AnthropicAiClient::class)->complete('s', 'p');
        Http::assertSent(fn (Request $request): bool => $request->header('x-api-key')[0] === 'sk-agency' && $request['output_config'] === ['effort' => 'high']);
    }

    public function test_the_agency_and_the_superadmin_pick_the_effort_level(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->getJson('/api/v1/agency')
            ->assertOk()
            ->assertJsonPath('ai_effort', null)
            ->assertJsonPath('ai_efforts.1.id', 'medium')
            ->assertJsonPath('ai_platform_effort', 'Medio');
        $this->putJson('/api/v1/agency', ['name' => $agency->name, 'ai_effort' => 'max'])->assertStatus(422)->assertJsonValidationErrors('ai_effort');
        $this->putJson('/api/v1/agency', ['name' => $agency->name, 'ai_effort' => 'high'])->assertOk()->assertJsonPath('ai_effort', 'high');

        Sanctum::actingAs(User::factory()->create(['agency_id' => null, 'is_platform_admin' => true]));
        $this->putJson('/api/v1/platform/integrations', ['ai_effort' => 'low'])
            ->assertOk()
            ->assertJsonPath('ai_effort', 'low')
            ->assertJsonPath('ai_efforts.0.label', 'Bajo');
        $this->assertSame('low', AiSettings::platformEffort());
    }

    public function test_the_platform_falls_back_to_env_then_to_the_default_model(): void
    {
        config(['services.anthropic.key' => 'sk-env', 'services.anthropic.model' => null]);
        $this->assertSame(['key' => 'sk-env', 'model' => AiModels::DEFAULT, 'effort' => 'medium', 'own_key' => false], AiSettings::for(null));
        $this->assertTrue(AiSettings::platformKeyFromEnv());

        // A model set only in .env (not in the panel's list) is still used as-is, without extras.
        config(['services.anthropic.model' => 'claude-sonnet-4-6']);
        $this->assertSame('claude-sonnet-4-6', AiSettings::platformModel());
        $this->assertSame(['body' => [], 'beta' => null], AiModels::extras('claude-sonnet-4-6'));

        $this->platform('sk-panel', 'claude-opus-5-5');
        $this->assertSame(['key' => 'sk-panel', 'model' => 'claude-opus-5-5', 'effort' => 'medium', 'own_key' => false], AiSettings::for(null));
        $this->assertFalse(AiSettings::platformKeyFromEnv());
    }

    public function test_a_refusal_or_an_error_returns_no_text(): void
    {
        $this->platform('sk-platform');
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['stop_reason' => 'refusal', 'content' => []])
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529);

        $client = app(AnthropicAiClient::class);
        $this->assertSame('', $client->complete('s', 'p'));
        $this->assertSame('', $client->complete('s', 'p'));
    }

    public function test_the_agency_picks_a_model_from_the_list(): void
    {
        config(['services.anthropic.model' => null]);
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->getJson('/api/v1/agency')
            ->assertOk()
            ->assertJsonPath('ai_model', null)
            ->assertJsonPath('ai_models.0.id', 'claude-opus-5-5')
            ->assertJsonPath('ai_platform_model', 'Claude Opus 5.5');

        $this->putJson('/api/v1/agency', ['name' => $agency->name, 'ai_model' => 'gpt-5'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ai_model');

        $this->putJson('/api/v1/agency', ['name' => $agency->name, 'ai_model' => 'claude-haiku-5-5'])
            ->assertOk()
            ->assertJsonPath('ai_model', 'claude-haiku-5-5');

        $this->putJson('/api/v1/agency', ['name' => $agency->name, 'ai_model' => null])
            ->assertOk()
            ->assertJsonPath('ai_model', null);
    }

    public function test_probar_says_whether_the_key_and_model_work(): void
    {
        $agency = Agency::factory()->create(['settings' => [AiSettings::AGENCY_MODEL => 'claude-sonnet-5-5']]);
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->postJson('/api/v1/agency/ai/test')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $agency->setAnthropicKey('sk-agency');
        $agency->save();
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'OK']]])
            ->push(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401);

        $this->postJson('/api/v1/agency/ai/test')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('model', 'claude-sonnet-5-5')
            ->assertJsonPath('message', 'Funciona: Claude Sonnet 5.5 respondió (esfuerzo medio).');

        $response = $this->postJson('/api/v1/agency/ai/test')->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('La clave no es válida', (string) $response->json('message'));
        $this->assertStringNotContainsString('sk-agency', (string) $response->getContent());
    }

    public function test_a_collaborator_cannot_spend_the_ai_on_a_test(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Collaborator]));

        $this->postJson('/api/v1/agency/ai/test')->assertForbidden();
    }

    public function test_superadmin_sets_the_platform_key_and_default_model(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => null, 'is_platform_admin' => true]));

        $this->putJson('/api/v1/platform/integrations', ['anthropic_key' => 'sk-platform-secret', 'ai_model' => 'claude-sonnet-5-5'])
            ->assertOk()
            ->assertJsonPath('anthropic_key_set', true)
            ->assertJsonPath('ai_ready', true)
            ->assertJsonPath('ai_from_env', false)
            ->assertJsonPath('ai_model', 'claude-sonnet-5-5')
            ->assertJsonPath('ai_model_label', 'Claude Sonnet 5.5')
            ->assertJsonMissing(['anthropic_key' => 'sk-platform-secret']);

        $this->assertSame('sk-platform-secret', AiSettings::platformKey());

        $this->putJson('/api/v1/platform/integrations', ['ai_model' => 'not-a-model'])->assertStatus(422);

        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'model: claude-sonnet-5-5']], 404)]);
        $response = $this->postJson('/api/v1/platform/integrations/ai/test')->assertStatus(422);
        $this->assertStringContainsString('no tiene acceso al modelo «Claude Sonnet 5.5»', (string) $response->json('message'));
    }

    public function test_an_agency_cannot_reach_the_platform_ai_settings(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->putJson('/api/v1/platform/integrations', ['ai_model' => 'claude-haiku-5-5'])->assertForbidden();
        $this->postJson('/api/v1/platform/integrations/ai/test')->assertForbidden();
    }
}
