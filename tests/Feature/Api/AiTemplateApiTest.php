<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AiClient;
use App\Ai\AnthropicAiClient;
use App\Enums\DataSourceType;
use App\Jobs\AiDesignJob;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\User;
use App\Reports\AiReportBuilder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_a_big_report_runs_in_the_background_and_the_editor_polls_it(): void
    {
        Queue::fake();
        $site = $this->site();

        $response = $this->postJson("/api/v1/sites/{$site->id}/ai-template", ['prompt' => 'informe completo'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');
        $job = (string) $response->json('job');
        Queue::assertPushed(AiDesignJob::class, fn (AiDesignJob $queued): bool => $queued->jobId === $job && $queued->mode === 'template' && $queued->prompt === 'informe completo');

        $this->getJson("/api/v1/ai-design/{$job}")->assertStatus(202)->assertJsonPath('status', 'queued');

        // Another agency can't see it.
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));
        $this->getJson("/api/v1/ai-design/{$job}")->assertNotFound();
    }

    public function test_the_job_runs_and_its_result_is_read_back(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient((string) json_encode(['pages' => [['name' => 'Resumen', 'items' => [['kind' => 'kpi', 'metric' => 'ga4.sessions']]]]])));
        Queue::fake();
        $site = $this->site();
        $job = (string) $this->postJson("/api/v1/sites/{$site->id}/ai-template")->json('job');

        Queue::pushedJobs()[AiDesignJob::class][0]['job']->handle(app(AiReportBuilder::class), app(TenantContext::class));

        $this->getJson("/api/v1/ai-design/{$job}")
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('pages', ['Resumen'])
            ->assertJsonPath('blocks.1.type', 'kpi');
    }

    public function test_asking_for_changes_sends_the_canvas(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient((string) json_encode(['pages' => [['name' => 'Tráfico', 'items' => [['ref' => 'k1', 'kind' => 'kpi', 'metric' => 'ga4.sessions', 'title' => 'Visitas']]]]])));
        $site = $this->site();
        $canvas = [['id' => 'k1', 'type' => 'kpi', 'binding' => ['source' => 'ga4', 'metric' => 'sessions'], 'props' => ['label' => 'Sesiones'], 'style' => [], 'layout' => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 4], 'page' => 0]];

        $this->postJson("/api/v1/sites/{$site->id}/ai-adjust", ['prompt' => '', 'blocks' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt', 'blocks']);

        $this->postJson("/api/v1/sites/{$site->id}/ai-adjust", ['prompt' => 'llama «Visitas» al KPI', 'blocks' => $canvas, 'pages' => ['Tráfico']])
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('blocks.1.id', 'k1')
            ->assertJsonPath('blocks.1.props.label', 'Visitas');
    }

    public function test_it_returns_422_on_unusable_ai_output(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient('no json here'));

        $site = $this->site();

        $this->postJson("/api/v1/sites/{$site->id}/ai-template")->assertUnprocessable();
    }
}
