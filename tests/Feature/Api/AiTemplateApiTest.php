<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AiClient;
use App\Ai\AnthropicAiClient;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class AiTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    private function site(): Site
    {
        $client = Client::factory()->create(['agency_id' => $this->agency->id]);
        $site = Site::factory()->create(['agency_id' => $this->agency->id, 'client_id' => $client->id]);
        DataSource::factory()->create(['agency_id' => $this->agency->id, 'site_id' => $site->id, 'type' => DataSourceType::Ga4]);

        return $site;
    }

    public function test_it_returns_a_validated_draft_layout(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient((string) json_encode([
            'blocks' => [
                ['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions'], 'props' => [], 'style' => []],
            ],
            'narrative' => 'ok',
        ])));

        $site = $this->site();

        $this->postJson("/api/v1/sites/{$site->id}/ai-template", ['prompt' => 'enfoque SEO'])
            ->assertOk()
            ->assertJsonPath('blocks.0.id', 'k1')
            ->assertJsonPath('narrative', 'ok');
    }

    public function test_a_failing_ai_says_why_instead_of_a_generic_error(): void
    {
        // The real client (the base TestCase swaps in a fake), with Anthropic answering «bad key».
        $this->app->bind(AiClient::class, AnthropicAiClient::class);
        config(['services.anthropic.key' => 'sk-wrong']);
        Http::fake([
            'api.anthropic.com/v1/models*' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401),
            'api.anthropic.com/v1/messages' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401),
        ]);

        $site = $this->site();

        $response = $this->postJson("/api/v1/sites/{$site->id}/ai-template", ['prompt' => 'informe completo'])->assertUnprocessable();
        $this->assertStringContainsString('No se pudo usar la IA: La clave no es válida', (string) $response->json('message'));
        // The AI isn't called twice for a problem a retry can't fix.
        Http::assertSentCount(2);
    }

    public function test_it_returns_422_on_unusable_ai_output(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient('no json here'));

        $site = $this->site();

        $this->postJson("/api/v1/sites/{$site->id}/ai-template")->assertUnprocessable();
    }
}
