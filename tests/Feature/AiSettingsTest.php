<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ai\AiModelCatalog;
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
 * The AI settings read the models live from Anthropic (GET /v1/models): «the newest of a family»
 * follows new releases on its own, a retired model is replaced, and each request only carries what
 * that model says it accepts. Key and choices come from the panels; nothing from the server config
 * but an install-time key.
 */
class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * @param  list<string>  $efforts
     * @param  list<string>|null  $fallbacks
     * @return array<string, mixed>
     */
    private function model(string $id, string $name, string $created, array $efforts = self::ALL, ?array $fallbacks = ['claude-opus-4-8']): array
    {
        $effort = ['supported' => $efforts !== []];
        foreach (self::ALL as $level) {
            $effort[$level] = ['supported' => in_array($level, $efforts, true)];
        }

        return array_filter([
            'type' => 'model',
            'id' => $id,
            'display_name' => $name,
            'created_at' => $created,
            'capabilities' => ['effort' => $effort, 'thinking' => ['supported' => true]],
            'allowed_fallback_models' => $fallbacks,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $extra
     * @return array<string, mixed>
     */
    private function catalog(array $extra = []): array
    {
        return ['data' => [
            ...$extra,
            $this->model('claude-haiku-5-5', 'Claude Haiku 5.5', '2026-10-15T00:00:00Z', self::ALL, []),
            $this->model('claude-sonnet-5-5', 'Claude Sonnet 5.5', '2026-10-01T00:00:00Z'),
            $this->model('claude-opus-5-5', 'Claude Opus 5.5', '2026-09-01T00:00:00Z'),
            $this->model('claude-opus-5', 'Claude Opus 5', '2026-05-01T00:00:00Z'),
            $this->model('claude-haiku-4-5', 'Claude Haiku 4.5', '2025-10-01T00:00:00Z', [], null),
        ], 'has_more' => false, 'last_id' => 'claude-haiku-4-5'];
    }

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

    /**
     * @param  array<string, mixed>|null  $catalog
     */
    private function fakeAnthropic(?array $catalog = null, ?PromiseInterface $answer = null): void
    {
        Http::fake([
            'api.anthropic.com/v1/models*' => Http::response($catalog ?? $this->catalog()),
            'api.anthropic.com/v1/messages' => $answer ?? $this->answer(),
        ]);
    }

    private function platform(?string $key, ?string $model = null, ?string $effort = null): void
    {
        $settings = PlatformSetting::current();
        $settings->putSecret(AiSettings::PLATFORM_KEY, $key);
        $settings->put(AiSettings::PLATFORM_MODEL, $model);
        $settings->put(AiSettings::PLATFORM_EFFORT, $effort);
        $settings->save();
    }

    private function sentMessage(callable $check): void
    {
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/messages') && $check($request));
    }

    public function test_by_default_the_newest_opus_is_used_with_what_it_accepts(): void
    {
        $this->platform('sk-platform');
        $this->fakeAnthropic();

        $text = app(AnthropicAiClient::class)->complete('system', 'prompt');

        $this->assertSame('Hola', $text, 'the text after the thinking block');
        // The list is read with the header that publishes who supports the server-side fallback.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1/models') && $request->header('anthropic-beta')[0] === AiModels::LIST_BETA);
        $this->sentMessage(fn (Request $request): bool => $request['model'] === 'claude-opus-5-5'
            && $request['output_config'] === ['effort' => 'medium']
            && $request['fallbacks'] === 'default'
            && $request->header('anthropic-beta')[0] === AiModels::FALLBACK_BETA
            && $request->header('x-api-key')[0] === 'sk-platform');
    }

    public function test_a_new_model_from_anthropic_is_picked_up_on_its_own(): void
    {
        $this->platform('sk-platform', 'auto:opus');
        $this->fakeAnthropic($this->catalog([$this->model('claude-opus-6', 'Claude Opus 6', '2026-12-01T00:00:00Z')]));

        app(AnthropicAiClient::class)->complete('s', 'p');

        $this->sentMessage(fn (Request $request): bool => $request['model'] === 'claude-opus-6');
    }

    public function test_a_retired_pinned_model_gives_way_to_the_newest_of_its_family(): void
    {
        $this->platform('sk-platform', 'claude-sonnet-4-6');
        $this->fakeAnthropic();

        app(AnthropicAiClient::class)->complete('s', 'p');

        $this->sentMessage(fn (Request $request): bool => $request['model'] === 'claude-sonnet-5-5');
    }

    public function test_each_request_only_carries_what_the_model_accepts(): void
    {
        // A model that takes no effort level and has no fallback: neither is sent.
        $this->platform('sk-platform', 'claude-haiku-4-5', 'high');
        $this->fakeAnthropic();
        app(AnthropicAiClient::class)->complete('s', 'p');
        $this->sentMessage(fn (Request $request): bool => $request['model'] === 'claude-haiku-4-5'
            && ! isset($request['output_config'])
            && ! isset($request['fallbacks'])
            && $request->header('anthropic-beta') === []);

    }

    public function test_a_level_the_model_does_not_list_falls_back_to_medium(): void
    {
        $this->platform('sk-platform', 'auto:opus', 'max');
        $this->fakeAnthropic($this->catalog([$this->model('claude-opus-6', 'Claude Opus 6', '2026-12-01T00:00:00Z', ['low', 'medium', 'high'])]));
        app(AnthropicAiClient::class)->complete('s', 'p');
        $this->sentMessage(fn (Request $request): bool => $request['model'] === 'claude-opus-6' && $request['output_config'] === ['effort' => 'medium']);
    }

    public function test_an_agency_with_its_own_key_uses_its_own_choices(): void
    {
        $this->platform('sk-platform', 'auto:haiku', 'low');
        $agency = Agency::factory()->create(['settings' => [AiSettings::AGENCY_MODEL => 'auto:sonnet', AiSettings::AGENCY_EFFORT => 'high']]);
        app(TenantContext::class)->set($agency->id);
        $this->fakeAnthropic();

        // Without its own key the agency's choices are ignored: the platform pays, the platform decides.
        app(AnthropicAiClient::class)->complete('s', 'p');
        $this->sentMessage(fn (Request $request): bool => $request->header('x-api-key')[0] === 'sk-platform'
            && $request['model'] === 'claude-haiku-5-5'
            && $request['output_config'] === ['effort' => 'low']
            && ! isset($request['fallbacks']));

        $agency->setAnthropicKey('sk-agency');
        $agency->save();
        app(AnthropicAiClient::class)->complete('s', 'p');
        $this->sentMessage(fn (Request $request): bool => $request->header('x-api-key')[0] === 'sk-agency'
            && $request['model'] === 'claude-sonnet-5-5'
            && $request['output_config'] === ['effort' => 'high']);
    }

    public function test_the_list_is_cached_and_survives_an_outage(): void
    {
        $catalog = app(AiModelCatalog::class);
        Http::fakeSequence('api.anthropic.com/v1/models*')
            ->push($this->catalog())
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529);

        $this->assertCount(5, $catalog->list('sk-x')['models']);
        $this->assertCount(5, $catalog->list('sk-x')['models'], 'served from cache, no second call');
        Http::assertSentCount(1);

        // Forced refresh while Anthropic is down: the last good list stays, with the reason.
        $refreshed = $catalog->list('sk-x', true);
        $this->assertCount(5, $refreshed['models']);
        $this->assertStringContainsString('Anthropic no está respondiendo', (string) $refreshed['error']);
    }

    public function test_the_list_pages_and_does_without_the_beta_header_if_it_is_refused(): void
    {
        Http::fakeSequence('api.anthropic.com/v1/models*')
            ->push(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'unknown beta']], 400)
            ->push(['data' => [$this->model('claude-opus-5-5', 'Claude Opus 5.5', '2026-09-01T00:00:00Z')], 'has_more' => true, 'last_id' => 'claude-opus-5-5'])
            ->push(['data' => [$this->model('claude-haiku-5-5', 'Claude Haiku 5.5', '2026-10-15T00:00:00Z')], 'has_more' => false, 'last_id' => 'claude-haiku-5-5']);

        $models = app(AiModelCatalog::class)->list('sk-y')['models'];

        $this->assertSame(['claude-opus-5-5', 'claude-haiku-5-5'], array_column($models, 'id'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'after_id=claude-opus-5-5') && $request->header('anthropic-beta') === []);
    }

    public function test_with_no_list_at_all_a_safe_default_is_used_without_extras(): void
    {
        $this->platform('sk-platform');
        $this->fakeAnthropic(['data' => [], 'has_more' => false]);

        app(AnthropicAiClient::class)->complete('s', 'p');

        $this->sentMessage(fn (Request $request): bool => $request['model'] === AiModels::LAST_RESORT && ! isset($request['output_config']));
    }

    public function test_long_answers_are_streamed_and_capped_to_the_model(): void
    {
        $this->platform('sk-platform');
        $haiku = $this->model('claude-haiku-4-5', 'Claude Haiku 4.5', '2025-10-01T00:00:00Z', [], null);
        $opus = [...$this->model('claude-opus-5-5', 'Claude Opus 5.5', '2026-09-01T00:00:00Z'), 'max_tokens' => 40000];
        $sse = implode("\n", [
            'event: message_start', 'data: {"type":"message_start","message":{"id":"m"}}', '',
            'event: content_block_start', 'data: {"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":""}}', '',
            'event: content_block_delta', 'data: {"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":"hmm"}}', '',
            'event: content_block_delta', 'data: {"type":"content_block_delta","index":1,"delta":{"type":"text_delta","text":"{\\"pages\\":"}}', '',
            'event: content_block_delta', 'data: {"type":"content_block_delta","index":1,"delta":{"type":"text_delta","text":"[]}"}}', '',
            'event: message_delta', 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":9}}', '',
            'event: message_stop', 'data: {"type":"message_stop"}', '',
        ]);
        Http::fake([
            'api.anthropic.com/v1/models*' => Http::response(['data' => [$opus, $haiku], 'has_more' => false]),
            'api.anthropic.com/v1/messages' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $text = app(AnthropicAiClient::class)->complete('s', 'p', 64000);

        $this->assertSame('{"pages":[]}', $text, 'only the text deltas, not the thinking');
        $this->sentMessage(fn (Request $request): bool => $request['stream'] === true && $request['max_tokens'] === 40000);
    }

    public function test_a_stream_cut_by_the_length_limit_says_so(): void
    {
        $this->platform('sk-platform');
        $sse = "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"{\\\"pages\\\": [\"}}\n\n"
            ."data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"max_tokens\"}}\n\n";
        $this->fakeAnthropic(null, Http::response($sse, 200));

        $client = app(AnthropicAiClient::class);
        $client->complete('s', 'p', 64000);

        $this->assertSame('La respuesta de la IA quedó incompleta porque era demasiado larga.', $client->lastError());
    }

    public function test_the_agency_panel_lists_anthropics_models_for_its_own_key(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->fakeAnthropic($this->catalog([$this->model('claude-fable-5-1', 'Claude Fable 5.1', '2026-10-20T00:00:00Z')]));

        // Without its own key: nothing to choose, and no call to Anthropic.
        $this->getJson('/api/v1/agency')->assertOk()->assertJsonPath('ai_choices', [])->assertJsonPath('ai_platform_available', false);
        Http::assertNothingSent();

        $agency->setAnthropicKey('sk-agency');
        $agency->save();

        $response = $this->getJson('/api/v1/agency')
            ->assertOk()
            ->assertJsonPath('ai_model', null)
            ->assertJsonPath('ai_model_now', 'Claude Opus 5.5')
            ->assertJsonPath('ai_effort_now', 'Medio')
            ->assertJsonPath('ai_efforts.4.label', 'Máximo')
            ->assertJsonPath('ai_choices.0.id', 'auto:fable')
            ->assertJsonPath('ai_choices.1.id', 'auto:opus')
            ->assertJsonPath('ai_choices.1.label', 'El Opus más reciente');
        $this->assertContains('claude-opus-5', array_column((array) $response->json('ai_choices'), 'id'));
        $this->assertStringNotContainsString('sk-agency', (string) $response->getContent());

        $this->putJson('/api/v1/agency', ['name' => $agency->name, 'ai_model' => 'nada válido!'])->assertStatus(422)->assertJsonValidationErrors('ai_model');
        $this->putJson('/api/v1/agency', ['name' => $agency->name, 'ai_model' => 'auto:sonnet', 'ai_effort' => 'low'])
            ->assertOk()
            ->assertJsonPath('ai_model', 'auto:sonnet')
            ->assertJsonPath('ai_model_now', 'Claude Sonnet 5.5')
            ->assertJsonPath('ai_effort_now', 'Bajo');
    }

    public function test_buscar_modelos_nuevos_reads_the_list_again(): void
    {
        $agency = Agency::factory()->create();
        $agency->setAnthropicKey('sk-agency');
        $agency->save();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));
        Http::fakeSequence('api.anthropic.com/v1/models*')
            ->push($this->catalog())
            ->push($this->catalog([$this->model('claude-opus-6', 'Claude Opus 6', '2026-12-01T00:00:00Z')]));

        $this->getJson('/api/v1/agency')->assertJsonPath('ai_model_now', 'Claude Opus 5.5');
        $this->postJson('/api/v1/agency/ai/models/refresh')->assertOk()->assertJsonPath('ai_model_now', 'Claude Opus 6');
    }

    public function test_probar_says_whether_the_key_and_model_work(): void
    {
        $agency = Agency::factory()->create(['settings' => [AiSettings::AGENCY_MODEL => 'auto:sonnet']]);
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->postJson('/api/v1/agency/ai/test')->assertStatus(422)->assertJsonPath('ok', false);

        $agency->setAnthropicKey('sk-agency');
        $agency->save();
        Http::fake([
            'api.anthropic.com/v1/models*' => Http::response($this->catalog()),
            'api.anthropic.com/v1/messages' => Http::sequence()
                ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'OK']]])
                ->push(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401),
        ]);

        $this->postJson('/api/v1/agency/ai/test')
            ->assertOk()
            ->assertJsonPath('model', 'claude-sonnet-5-5')
            ->assertJsonPath('message', 'Funciona: Claude Sonnet 5.5 respondió (esfuerzo medio).');

        $response = $this->postJson('/api/v1/agency/ai/test')->assertStatus(422);
        $this->assertStringContainsString('La clave no es válida', (string) $response->json('message'));
        $this->assertStringNotContainsString('sk-agency', (string) $response->getContent());
    }

    public function test_a_collaborator_cannot_spend_the_ai_on_a_test(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Collaborator]));

        $this->postJson('/api/v1/agency/ai/test')->assertForbidden();
        $this->postJson('/api/v1/agency/ai/models/refresh')->assertForbidden();
    }

    public function test_superadmin_sets_key_model_and_effort_from_the_panel(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => null, 'is_platform_admin' => true]));
        $this->fakeAnthropic();

        // A key left at install time works and the panel says so — without asking anyone to edit files.
        config(['services.anthropic.key' => 'sk-installed']);
        $this->getJson('/api/v1/platform/integrations')
            ->assertOk()
            ->assertJsonPath('ai_ready', true)
            ->assertJsonPath('ai_key_preinstalled', true)
            ->assertJsonPath('ai_model', 'auto:opus')
            ->assertJsonPath('ai_model_now', 'Claude Opus 5.5');

        $this->putJson('/api/v1/platform/integrations', ['anthropic_key' => 'sk-platform-secret', 'ai_model' => 'claude-opus-5', 'ai_effort' => 'high'])
            ->assertOk()
            ->assertJsonPath('anthropic_key_set', true)
            ->assertJsonPath('ai_key_preinstalled', false)
            ->assertJsonPath('ai_model', 'claude-opus-5')
            ->assertJsonPath('ai_model_now', 'Claude Opus 5')
            ->assertJsonPath('ai_effort_now', 'Alto')
            ->assertJsonMissing(['anthropic_key' => 'sk-platform-secret']);
        $this->assertSame('sk-platform-secret', AiSettings::platformKey());

        $this->putJson('/api/v1/platform/integrations', ['ai_effort' => 'MUCHO'])->assertStatus(422);

        $this->postJson('/api/v1/platform/integrations/ai/test')
            ->assertOk()
            ->assertJsonPath('message', 'Funciona: Claude Opus 5 respondió (esfuerzo alto).');
        $this->postJson('/api/v1/platform/integrations/ai/models/refresh')->assertOk()->assertJsonPath('ai_model_now', 'Claude Opus 5');
    }

    public function test_an_agency_cannot_reach_the_platform_ai_settings(): void
    {
        $agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $agency->id, 'role' => UserRole::Owner]));

        $this->putJson('/api/v1/platform/integrations', ['ai_model' => 'auto:haiku'])->assertForbidden();
        $this->postJson('/api/v1/platform/integrations/ai/test')->assertForbidden();
        $this->postJson('/api/v1/platform/integrations/ai/models/refresh')->assertForbidden();
    }
}
