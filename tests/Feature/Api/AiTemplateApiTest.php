<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AiClient;
use App\Enums\DataSourceType;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_it_returns_422_on_unusable_ai_output(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient('no json here'));

        $site = $this->site();

        $this->postJson("/api/v1/sites/{$site->id}/ai-template")->assertUnprocessable();
    }
}
